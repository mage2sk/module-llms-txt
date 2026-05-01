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
}
