<?php
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

class Fetcher implements SitemapFetcherInterface
{
    public const XML_URLS         = 'panth_llms_txt/sitemap/urls';
    public const XML_AUTO         = 'panth_llms_txt/sitemap/auto';
    public const XML_TIMEOUT      = 'panth_llms_txt/sitemap/timeout';
    public const XML_MAX_ENTRIES  = 'panth_llms_txt/sitemap/max_entries';
    public const XML_TTL          = 'panth_llms_txt/sitemap/ttl';

    private const MAX_NESTED = 50;
    private const MAX_REDIRECTS = 3;
    private const MAX_BODY_BYTES = 52428800;
    private const TOTAL_BUDGET_SECONDS = 120;
    private const USER_AGENT = 'Panth_LlmsTxt sitemap fetcher';

    private array $allowedHosts = [];
    private float $deadline = 0.0;

    private const DEFAULT_TIMEOUT     = 8;
    private const DEFAULT_MAX_ENTRIES = 5000;
    private const DEFAULT_TTL         = 3600;

    public function __construct(
        private readonly Curl $curl,
        private readonly Parser $parser,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LlmsCache $cache,
        private readonly LoggerInterface $logger,
        private readonly UrlGuard $urlGuard
    ) {
    }

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

        $this->allowedHosts = $this->resolveAllowedHosts($urls);
        $this->deadline     = microtime(true) + self::TOTAL_BUDGET_SECONDS;

        $maxEntries = $this->intConfig(self::XML_MAX_ENTRIES, $storeId, self::DEFAULT_MAX_ENTRIES);
        $entries    = [];
        $seen       = [];

        foreach ($urls as $sitemapUrl) {
            $rows = $this->fetchAndParse($sitemapUrl, $storeId);
            foreach ($rows as $row) {
                $loc = $row->getLocation();
                if (isset($seen[$loc]) || !preg_match('#^https?://#i', $loc)) {
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

    public function clearCache(int $storeId): void
    {
        $this->cache->remove(sprintf('panth_llms_sitemap_v1_store_%d', $storeId));
    }

    public function getSitemapUrls(int $storeId): array
    {
        return $this->resolveSitemapUrls($storeId);
    }

    private function resolveSitemapUrls(int $storeId): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_URLS, ScopeInterface::SCOPE_STORE, $storeId);
        $urls = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $url = trim($line);
            if ($url === '' || str_starts_with($url, '#')) {
                continue;
            }

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
                if (!$this->isAllowedUrl($childUrl)) {
                    $this->logger->info('[panth_llms_txt] nested sitemap skipped, host not allowed: ' . $childUrl);
                    continue;
                }
                $childBody = $this->httpGet($childUrl, $storeId);
                if ($childBody === '') {
                    continue;
                }
                if ($this->parser->detectType($childBody) !== 'urlset') {
                    continue;
                }
                $rows = array_merge($rows, $this->parser->parseUrlset($childBody, $childUrl));
            }
            return $rows;
        }
        $this->logger->info('[panth_llms_txt] sitemap response was not urlset/sitemapindex: ' . $url);
        return [];
    }

    private function httpGet(string $url, int $storeId): string
    {
        $timeout = $this->intConfig(self::XML_TIMEOUT, $storeId, self::DEFAULT_TIMEOUT);
        $timeout = max(1, min(60, $timeout));
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (!$this->isAllowedUrl($url)) {
                $this->logger->info('[panth_llms_txt] sitemap GET blocked, host not allowed: ' . $url);
                return '';
            }
            $pinned = $this->pinnedAddress($url);
            if ($pinned === null) {
                $this->logger->info('[panth_llms_txt] sitemap GET blocked, host does not resolve to a public IP: ' . $url);
                return '';
            }
            if ($this->deadline > 0.0 && microtime(true) > $this->deadline) {
                $this->logger->info('[panth_llms_txt] sitemap fetch time budget exhausted, skipped: ' . $url);
                return '';
            }
            try {
                $options = [
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_CONNECTTIMEOUT => $timeout,
                    CURLOPT_TIMEOUT        => $timeout,
                    CURLOPT_MAXFILESIZE    => self::MAX_BODY_BYTES,
                    CURLOPT_ENCODING       => '',
                ];
                if ($pinned !== '') {
                    $options[CURLOPT_RESOLVE] = [$pinned];
                }
                $this->curl->setOptions($options);
                $this->curl->addHeader('User-Agent', self::USER_AGENT);
                $this->curl->addHeader('Accept', 'application/xml, text/xml, */*;q=0.5');
                $this->curl->get($url);
                $status = (int) $this->curl->getStatus();
                if ($status >= 300 && $status < 400) {
                    $next = $this->redirectTarget($url, $this->curl->getHeaders());
                    if ($next === '') {
                        $this->logger->info(sprintf('[panth_llms_txt] sitemap GET %s -> HTTP %d', $url, $status));
                        return '';
                    }
                    $url = $next;
                    continue;
                }
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
        $this->logger->info('[panth_llms_txt] sitemap GET stopped after too many redirects: ' . $url);
        return '';
    }

    private function redirectTarget(string $from, array $headers): string
    {
        $location = '';
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'location') {
                $location = trim((string) (is_array($value) ? reset($value) : $value));
                break;
            }
        }
        if ($location === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        if (!str_starts_with($location, '/') || str_starts_with($location, '//')) {
            return '';
        }
        $parts = parse_url($from);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return $parts['scheme'] . '://' . $parts['host'] . $port . $location;
    }

    private function resolveAllowedHosts(array $sitemapUrls): array
    {
        $hosts = $this->urlGuard->getStoreHosts();
        foreach ($sitemapUrls as $url) {
            $host = parse_url((string) $url, PHP_URL_HOST);
            if (!is_string($host) || $host === '' || isset($hosts[strtolower($host)])) {
                continue;
            }
            $error = $this->urlGuard->validate((string) $url);
            if ($error !== null) {
                $this->logger->info('[panth_llms_txt] configured sitemap URL skipped, ' . $error . ': ' . $url);
                continue;
            }
            $hosts[strtolower($host)] = true;
        }
        return $hosts;
    }

    private function pinnedAddress(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $this->urlGuard->isStoreHost($host)) {
            return '';
        }
        $addresses = $this->urlGuard->getPublicAddresses($host);
        if ($addresses === []) {
            return null;
        }
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return '';
        }
        $port = isset($parts['port'])
            ? (int) $parts['port']
            : (strtolower((string) ($parts['scheme'] ?? '')) === 'http' ? 80 : 443);
        $ip = (string) $addresses[0];
        if (str_contains($ip, ':')) {
            $ip = '[' . $ip . ']';
        }
        return $host . ':' . $port . ':' . $ip;
    }

    private function isAllowedUrl(string $url): bool
    {
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        return isset($this->allowedHosts[strtolower($host)]);
    }

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
