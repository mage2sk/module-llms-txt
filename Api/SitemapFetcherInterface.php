<?php
/**
 * Panth LLMs.txt — sitemap fetcher contract.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Api;

use Panth\LlmsTxt\Api\Data\SitemapEntryInterface;

/**
 * Resolves merchant-configured sitemap URLs into a flat list of
 * {@see SitemapEntryInterface} objects.
 *
 * Implementations MUST:
 *   - Accept `<sitemapindex>` documents and recurse into the nested
 *     `<sitemap>` references (one level — guards against loops).
 *   - Treat individual fetch failures (timeout, 4xx/5xx, malformed XML)
 *     as warnings, not exceptions, and continue with whatever rows
 *     parsed successfully.
 *   - Cache the parsed result in the dedicated panth_llms_txt cache
 *     type so subsequent /llms.txt hits don't re-fetch on every
 *     request.
 */
interface SitemapFetcherInterface
{
    /**
     * Fetch + parse + merge every sitemap mapped for the given store.
     *
     * @param int $storeId
     * @return SitemapEntryInterface[]
     */
    public function fetchForStore(int $storeId): array;

    /**
     * Return the resolved list of sitemap source URLs for the store —
     * the merchant's configured `panth_llms_txt/sitemap/urls` lines
     * plus the `{baseUrl}sitemap.xml` auto-fallback when both the
     * field is empty and auto-detection is enabled.
     *
     * Implementations MUST resolve relative entries against the store
     * base URL so the returned list is always absolute.
     *
     * @param int $storeId
     * @return string[]
     */
    public function getSitemapUrls(int $storeId): array;
}
