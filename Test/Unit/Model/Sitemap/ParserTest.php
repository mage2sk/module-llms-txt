<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\Sitemap;

use Panth\LlmsTxt\Model\Sitemap\Parser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ParserTest extends TestCase
{
    private const NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    private function parser(): Parser
    {
        return new Parser($this->createStub(LoggerInterface::class));
    }

    private function urlset(string $rows): string
    {
        return '<?xml version="1.0"?><urlset xmlns="' . self::NS . '">' . $rows . '</urlset>';
    }

    public function testUrlsetRowsAreParsedWithAllOptionalFields(): void
    {
        $xml = $this->urlset(
            '<url><loc> https://shop.example/a.html </loc><lastmod>2026-01-02</lastmod>'
            . '<changefreq>daily</changefreq><priority>0.8</priority></url>'
            . '<url><loc>https://shop.example/b.html</loc></url>'
        );

        $entries = $this->parser()->parseUrlset($xml, 'https://shop.example/sitemap.xml');

        $this->assertCount(2, $entries);
        $this->assertSame('https://shop.example/a.html', $entries[0]->getLocation());
        $this->assertSame('2026-01-02', $entries[0]->getLastModified());
        $this->assertSame('daily', $entries[0]->getChangeFrequency());
        $this->assertSame(0.8, $entries[0]->getPriority());
        $this->assertSame('https://shop.example/sitemap.xml', $entries[0]->getSource());
        $this->assertNull($entries[1]->getLastModified());
        $this->assertNull($entries[1]->getChangeFrequency());
        $this->assertNull($entries[1]->getPriority());
    }

    public function testPriorityIsClampedAndEmptyLocationsSkipped(): void
    {
        $xml = $this->urlset(
            '<url><loc>https://shop.example/hi</loc><priority>7</priority></url>'
            . '<url><loc>https://shop.example/lo</loc><priority>-2</priority></url>'
            . '<url><loc>   </loc></url>'
        );

        $entries = $this->parser()->parseUrlset($xml);

        $this->assertCount(2, $entries);
        $this->assertSame(1.0, $entries[0]->getPriority());
        $this->assertSame(0.0, $entries[1]->getPriority());
    }

    public function testUrlsetParserIgnoresASitemapIndex(): void
    {
        $xml = '<sitemapindex xmlns="' . self::NS . '"><sitemap><loc>https://x/1.xml</loc></sitemap></sitemapindex>';

        $this->assertSame([], $this->parser()->parseUrlset($xml));
    }

    public function testIndexReturnsNestedLocationsOnly(): void
    {
        $xml = '<sitemapindex xmlns="' . self::NS . '">'
            . '<sitemap><loc>https://shop.example/1.xml</loc></sitemap>'
            . '<sitemap><loc></loc></sitemap>'
            . '<sitemap><loc> https://shop.example/2.xml </loc></sitemap></sitemapindex>';

        $this->assertSame(
            ['https://shop.example/1.xml', 'https://shop.example/2.xml'],
            $this->parser()->parseIndex($xml)
        );
        $this->assertSame([], $this->parser()->parseIndex($this->urlset('')));
    }

    public function testDetectTypeRecognisesOnlySitemapRoots(): void
    {
        $parser = $this->parser();

        $this->assertSame('urlset', $parser->detectType($this->urlset('')));
        $this->assertSame('sitemapindex', $parser->detectType('<sitemapindex/>'));
        $this->assertSame('', $parser->detectType('<html><body/></html>'));
        $this->assertSame('', $parser->detectType(''));
        $this->assertSame('', $parser->detectType('not xml at all <'));
    }

    public function testMalformedXmlDoesNotLeakLibxmlErrors(): void
    {
        $previous = libxml_use_internal_errors(false);
        try {
            $this->assertSame([], $this->parser()->parseUrlset('<urlset><url><loc>x</url>'));
            $this->assertFalse(libxml_use_internal_errors(false));
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    public function testGzippedBodiesAreDecoded(): void
    {
        $xml = $this->urlset('<url><loc>https://shop.example/gz</loc></url>');

        $entries = $this->parser()->parseUrlset(gzencode($xml));

        $this->assertCount(1, $entries);
        $this->assertSame('https://shop.example/gz', $entries[0]->getLocation());
    }

    public function testExternalEntitiesAreNotExpanded(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE urlset [<!ENTITY ext SYSTEM "file:///etc/hostname">]>'
            . '<urlset xmlns="' . self::NS . '"><url><loc>https://shop.example/&ext;</loc></url></urlset>';

        $entries = $this->parser()->parseUrlset($xml);

        foreach ($entries as $entry) {
            $this->assertStringStartsWith('https://shop.example/', $entry->getLocation());
            $this->assertStringNotContainsString("\n", $entry->getLocation());
        }
        $this->assertLessThanOrEqual(1, count($entries));
    }
}
