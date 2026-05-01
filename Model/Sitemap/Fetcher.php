<?php
/**
 * Panth LLMs.txt — sitemap fetcher.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\Sitemap;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Api\Data\SitemapEntryInterface;
use Panth\LlmsTxt\Api\SitemapFetcherInterface;
use Panth\LlmsTxt\Model\Cache\Type as LlmsCache;
use Psr\Log\LoggerInterface;

/**
 * Fetches the merchant-configured sitemap URLs, recursing one level
 * into `<sitemapindex>` documents, and caches the merged entry list.
 *
 * Configuration paths read:
 *   - panth_llms_txt/sitemap/urls       Newline-separated absolute URLs
 *   - panth_llms_txt/sitemap/auto       Yes/No, falls back to baseUrl + sitemap.xml when no URLs configured
 *   - panth_llms_txt/sitemap/timeout    Per-request timeout in seconds (default 8)
 *   - panth_llms_txt/sitemap/max_entries Hard cap on parsed entries (default 5000)
 *   - panth_llms_txt/sitemap/ttl        Cache TTL in seconds (default 3600)
 *
 * Failure handling matches the contract: every fetch is wrapped in a
 * try/catch, errors land in the Magento system log, and the merchant
 * sees an empty sitemap section rather than a fatal page.
 */
class Fetcher implements SitemapFetcherInterface
{
    public const XML_URLS         = 'panth_llms_txt/sitemap/urls';
    public const XML_AUTO         = 'panth_llms_txt/sitemap/auto';
    public const XML_TIMEOUT      = 'panth_llms_txt/sitemap/timeout';
    public const XML_MAX_ENTRIES  = 'panth_llms_txt/sitemap/max_entries';
    public const XML_TTL          = 'panth_llms_txt/sitemap/ttl';

    /**
     * Hard upper bound on nested sitemaps fetched from a single index
     * document — protects against runaway sitemapindex chains.
     */
    private const MAX_NESTED = 50;

    private const DEFAULT_TIMEOUT     = 8;
    private const DEFAULT_MAX_ENTRIES = 5000;
    private const DEFAULT_TTL         = 3600;

    public function __construct(
        private readonly Curl $curl,
        private readonly Parser $parser,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LlmsCache $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return SitemapEntryInterface[]
     */
    public function fetchForStore(int $storeId): array
    {
        $cacheKey = sprintf('panth_llms_sitemap_v1_store_%d', $storeId);
        $hit = $this->cache->load($cacheKey);
        if (is_string($hit) && $hit !== '') {
            $decoded = json_decode($hit, true);
            if (is_array($decoded)) {
                return $this->hydrate($decoded);
            }
        }

        $urls = $this->resolveSitemapUrls($storeId);
        if ($urls === []) {
            return [];
        }

        $maxEntries = $this->intConfig(self::XML_MAX_ENTRIES, $storeId, self::DEFAULT_MAX_ENTRIES);
        $entries    = [];
        $seen       = [];

        foreach ($urls as $sitemapUrl) {
            $rows = $this->fetchAndParse($sitemapUrl, $storeId);
            foreach ($rows as $row) {
                $loc = $row->getLocation();
                if (isset($seen[$loc])) {
                    continue;
                }
                $seen[$loc] = true;
                $entries[] = $row;
                if (count($entries) >= $maxEntries) {
                    break 2;
                }
            }
        }

        $payload = json_encode(array_map(static fn (SitemapEntryInterface $e): array => [
            'loc'      => $e->getLocation(),
            'lastmod'  => $e->getLastModified(),
            'cf'       => $e->getChangeFrequency(),
            'priority' => $e->getPriority(),
            'src'      => $e->getSource(),
        ], $entries));
        if (is_string($payload)) {
            $ttl = $this->intConfig(self::XML_TTL, $storeId, self::DEFAULT_TTL);
            $this->cache->save(
                $payload,
                $cacheKey,
                [LlmsCache::CACHE_TAG, 'config_scopes'],
                max(60, $ttl)
            );
        }

        return $entries;
    }

    /**
     * Manually evict the parsed sitemap cache for a single store —
     * used by the cron warm-up hook so the next request fetches fresh.
     */
    public function clearCache(int $storeId): void
    {
        $this->cache->remove(sprintf('panth_llms_sitemap_v1_store_%d', $storeId));
    }

    /**
     * Public accessor for the resolved sitemap URL list. Used by
     * Builder / FullBuilder / JsonBuilder when rendering the
     * "Index Formats" / sitemap footer so the listed URLs match
     * exactly what the merchant configured (no more hard-coded
     * `{baseUrl}sitemap.xml` when the merchant runs custom shards
     * elsewhere).
     *
     * @return string[]
     */
    public function getSitemapUrls(int $storeId): array
    {
        return $this->resolveSitemapUrls($storeId);
    }

    /**
     * Resolve the merchant's configured sitemap list with fallback to
     * `{baseUrl}sitemap.xml` when "auto" mode is on and no URLs are set.
     *
     * @return string[]
     */
    private function resolveSitemapUrls(int $storeId): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_URLS, ScopeInterface::SCOPE_STORE, $storeId);
        $urls = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $url = trim($line);
            if ($url === '' || str_starts_with($url, '#')) {
                continue;
            }
            // Allow relative paths — resolve against base URL.
            if (!preg_match('#^https?://#i', $url)) {
                $url = $this->resolveRelative($storeId, $url);
            }
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        if ($urls === [] && $this->flag(self::XML_AUTO, $storeId, true)) {
            $auto = $this->resolveRelative($storeId, 'sitemap.xml');
            if ($auto !== '') {
                $urls[] = $auto;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Fetch one sitemap URL, recursing one level if the response is a
     * `<sitemapindex>`. Logs and continues on HTTP / parse failure.
     *
     * @return SitemapEntryInterface[]
     */
    private function fetchAndParse(string $url, int $storeId): array
    {
        $body = $this->httpGet($url, $storeId);
        if ($body === '') {
            return [];
        }
        $type = $this->parser->detectType($body);
        if ($type === 'urlset') {
            return $this->parser->parseUrlset($body, $url);
        }
        if ($type === 'sitemapindex') {
            $nested = $this->parser->parseIndex($body);
            $nested = array_slice($nested, 0, self::MAX_NESTED);
            $rows = [];
            foreach ($nested as $childUrl) {
                $childBody = $this->httpGet($childUrl, $storeId);
                if ($childBody === '') {
                    continue;
                }
                if ($this->parser->detectType($childBody) !== 'urlset') {
                    // Reject nested-of-nested to keep the recursion bounded.
                    continue;
                }
                $rows = array_merge($rows, $this->parser->parseUrlset($childBody, $childUrl));
            }
            return $rows;
        }
        $this->logger->info('[panth_llms_txt] sitemap response was not urlset/sitemapindex: ' . $url);
        return [];
    }

    /**
     * GET one URL with the merchant-configured timeout. Empty string
     * on any failure (logged at info level — sitemap availability is
     * not load-bearing for the rest of the pipeline).
     */
    private function httpGet(string $url, int $storeId): string
    {
        $timeout = $this->intConfig(self::XML_TIMEOUT, $storeId, self::DEFAULT_TIMEOUT);
        $timeout = max(1, min(60, $timeout));
        try {
            $this->curl->setOptions([
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_ENCODING       => '',
            ]);
            $this->curl->addHeader('User-Agent', 'Panth_LlmsTxt/1.3 (+https://kishansavaliya.com)');
            $this->curl->addHeader('Accept', 'application/xml, text/xml, */*;q=0.5');
            $this->curl->get($url);
            $status = (int) $this->curl->getStatus();
            if ($status >= 400 || $status === 0) {
                $this->logger->info(sprintf('[panth_llms_txt] sitemap GET %s -> HTTP %d', $url, $status));
                return '';
            }
            return (string) $this->curl->getBody();
        } catch (\Throwable $e) {
            $this->logger->info('[panth_llms_txt] sitemap GET failed for ' . $url . ': ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Rebuild Entry instances from the cached JSON payload.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return SitemapEntryInterface[]
     */
    private function hydrate(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $loc = isset($row['loc']) ? (string) $row['loc'] : '';
            if ($loc === '') {
                continue;
            }
            $out[] = new Entry(
                $loc,
                isset($row['lastmod']) ? (string) $row['lastmod'] : null,
                isset($row['cf']) ? (string) $row['cf'] : null,
                isset($row['priority']) && $row['priority'] !== null ? (float) $row['priority'] : null,
                isset($row['src']) ? (string) $row['src'] : ''
            );
        }
        return $out;
    }

    private function resolveRelative(int $storeId, string $path): string
    {
        try {
            $base = rtrim((string) $this->storeManager->getStore($storeId)->getBaseUrl(), '/');
            return $base . '/' . ltrim($path, '/');
        } catch (\Throwable) {
            return '';
        }
    }

    private function flag(string $path, int $storeId, bool $default): bool
    {
        $raw = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($raw === null || $raw === '') {
            return $default;
        }
        return (bool) (int) $raw;
    }

    private function intConfig(string $path, int $storeId, int $default): int
    {
        $raw = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($raw === null || $raw === '') {
            return $default;
        }
        return max(0, (int) $raw);
    }
}
