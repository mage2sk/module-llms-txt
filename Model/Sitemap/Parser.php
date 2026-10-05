<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\Sitemap;

use Panth\LlmsTxt\Api\Data\SitemapEntryInterface;
use Psr\Log\LoggerInterface;

class Parser
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function parseUrlset(string $xmlBody, string $sourceUrl = ''): array
    {
        $sxe = $this->safeLoad($xmlBody);
        if (!$sxe instanceof \SimpleXMLElement) {
            return [];
        }
        if ($sxe->getName() !== 'urlset') {
            return [];
        }

        $entries = [];
        foreach ($sxe->url as $row) {
            $loc = trim((string) $row->loc);
            if ($loc === '') {
                continue;
            }
            $lastmod = trim((string) $row->lastmod);
            $cf      = trim((string) $row->changefreq);
            $pr      = trim((string) $row->priority);

            $entries[] = new Entry(
                $loc,
                $lastmod !== '' ? $lastmod : null,
                $cf !== '' ? $cf : null,
                $pr !== '' ? max(0.0, min(1.0, (float) $pr)) : null,
                $sourceUrl
            );
        }
        return $entries;
    }

    public function parseIndex(string $xmlBody): array
    {
        $sxe = $this->safeLoad($xmlBody);
        if (!$sxe instanceof \SimpleXMLElement) {
            return [];
        }
        if ($sxe->getName() !== 'sitemapindex') {
            return [];
        }
        $urls = [];
        foreach ($sxe->sitemap as $row) {
            $loc = trim((string) $row->loc);
            if ($loc !== '') {
                $urls[] = $loc;
            }
        }
        return $urls;
    }

    public function detectType(string $xmlBody): string
    {
        $sxe = $this->safeLoad($xmlBody);
        if (!$sxe instanceof \SimpleXMLElement) {
            return '';
        }
        $name = $sxe->getName();
        return in_array($name, ['urlset', 'sitemapindex'], true) ? $name : '';
    }

    private function safeLoad(string $body): ?\SimpleXMLElement
    {
        if ($body === '') {
            return null;
        }

        if (strncmp($body, "\x1f\x8b", 2) === 0) {
            $decoded = @gzdecode($body, 52428800);
            if ($decoded !== false) {
                $body = $decoded;
            }
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $sxe = @simplexml_load_string(
                $body,
                \SimpleXMLElement::class,
                LIBXML_NONET | LIBXML_NOCDATA
            );
        } catch (\Throwable $e) {
            $sxe = false;
            $this->logger->warning('[panth_llms_txt] sitemap parse exception: ' . $e->getMessage());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $sxe instanceof \SimpleXMLElement ? $sxe : null;
    }
}
