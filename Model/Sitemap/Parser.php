<?php
/**
 * Panth LLMs.txt — sitemap XML parser.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\Sitemap;

use Panth\LlmsTxt\Api\Data\SitemapEntryInterface;
use Psr\Log\LoggerInterface;

/**
 * Lightweight XML sitemap parser.
 *
 * Handles both shapes defined by https://www.sitemaps.org/protocol.html:
 *
 *   1. `<urlset>` — a flat list of `<url>` rows. Each row yields one
 *      {@see Entry} containing `loc` plus optional `lastmod`,
 *      `changefreq`, `priority`.
 *
 *   2. `<sitemapindex>` — a list of nested sitemap URLs. The parser
 *      itself does NOT fetch nested URLs (that's the {@see Fetcher}'s
 *      job to keep parsing pure and unit-testable). Instead it returns
 *      the nested URLs through {@see parseIndex()} and lets the fetcher
 *      decide how to recurse.
 *
 * All XML loading goes through {@see safeLoad()} which:
 *   - disables external entity loading (XXE protection)
 *   - turns libxml errors into warnings rather than fatal exceptions
 *   - supports gzip-encoded payloads (sitemap.xml.gz served with
 *     Content-Encoding: gzip is decoded by the fetcher; raw `.xml.gz`
 *     bytes are decoded here as a fallback).
 */
class Parser
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Parse a `<urlset>` sitemap into entry objects. Returns [] on
     * malformed input.
     *
     * @return SitemapEntryInterface[]
     */
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

    /**
     * Parse a `<sitemapindex>` document and return the nested sitemap
     * URLs (no recursion — caller handles fetching).
     *
     * @return string[]
     */
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

    /**
     * Determine the document type without committing to a parse path.
     * Returns 'urlset', 'sitemapindex' or '' (unknown / invalid).
     */
    public function detectType(string $xmlBody): string
    {
        $sxe = $this->safeLoad($xmlBody);
        if (!$sxe instanceof \SimpleXMLElement) {
            return '';
        }
        $name = $sxe->getName();
        return in_array($name, ['urlset', 'sitemapindex'], true) ? $name : '';
    }

    /**
     * SimpleXML loader hardened against XXE + tolerant of malformed
     * input. Decodes a raw gzip payload if present.
     */
    private function safeLoad(string $body): ?\SimpleXMLElement
    {
        if ($body === '') {
            return null;
        }
        // Raw gzip magic header — decode in-place so the XML parser
        // sees plain text. We do this in addition to letting curl
        // negotiate Accept-Encoding so manually fed payloads still
        // work.
        if (strncmp($body, "\x1f\x8b", 2) === 0) {
            $decoded = @gzdecode($body);
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
