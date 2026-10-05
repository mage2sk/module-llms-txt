<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\LlmsTxt\Section;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Panth\LlmsTxt\Api\Data\IndexEntryInterface;
use Panth\LlmsTxt\Api\SitemapFetcherInterface;
use Panth\LlmsTxt\Api\WeightedRankerInterface;
use Panth\LlmsTxt\Model\LlmsTxt\Section\Sitemap;
use Panth\LlmsTxt\Model\Sitemap\Entry;
use PHPUnit\Framework\TestCase;

class SitemapSectionTest extends TestCase
{
    private array $ranked = [];

    private function section(array $rows, array $config = []): Sitemap
    {
        $fetcher = $this->createStub(SitemapFetcherInterface::class);
        $fetcher->method('fetchForStore')->willReturn($rows);
        $ranker = $this->createStub(WeightedRankerInterface::class);
        $ranker->method('rank')->willReturnCallback(function (array $entries) {
            $this->ranked = $entries;
            return array_reverse($entries);
        });
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(static fn (string $p) => $config[$p] ?? null);
        return new Sitemap($fetcher, $ranker, $scopeConfig);
    }

    public function testRowsBecomeRankedLabelledLinks(): void
    {
        $lines = $this->section([
            new Entry('https://s/', '2026-01-01', null, 0.9),
            new Entry('https://s/blue-shoes_new.html', null, null, null),
            new Entry('https://s/dir/', null, null, 0.4),
        ])->render(1);

        $this->assertSame('## Sitemap Highlights', $lines[0]);
        $this->assertSame([
            '- [Dir](https://s/dir/)',
            '- [Blue Shoes New](https://s/blue-shoes_new.html)',
            '- [Homepage](https://s/)',
            '',
        ], array_slice($lines, 4));

        $this->assertSame(IndexEntryInterface::TYPE_SITEMAP, $this->ranked[0]->getType());
        $this->assertSame(['sitemap_priority' => 0.9, 'lastmod' => '2026-01-01'], $this->ranked[0]->getMetadata());
        $this->assertSame(0.0, $this->ranked[1]->getMetadata()['sitemap_priority']);
    }

    public function testMaxRowsLimitsOutputAndZeroMeansAll(): void
    {
        $rows = [new Entry('https://s/a'), new Entry('https://s/b'), new Entry('https://s/c')];

        $this->assertCount(4 + 1 + 1, $this->section($rows, [Sitemap::XML_MAX_ROWS => '1'])->render(1));
        $this->assertCount(4 + 3 + 1, $this->section($rows, [Sitemap::XML_MAX_ROWS => '0'])->render(1));
    }

    public function testDisabledOrEmptySitemapRendersNothing(): void
    {
        $this->assertSame([], $this->section([new Entry('https://s/a')], [Sitemap::XML_ENABLED => '0'])->render(1));
        $this->assertSame([], $this->section([])->render(1));
    }
}
