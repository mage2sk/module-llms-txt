<?php
/**
 * Panth LLMs.txt — /llms.json builder.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt;

use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Api\Data\IndexEntryInterface;
use Panth\LlmsTxt\Api\SitemapFetcherInterface;
use Panth\LlmsTxt\Api\WeightedRankerInterface;
use Panth\LlmsTxt\Model\Cache\Type as LlmsCache;
use Panth\LlmsTxt\Model\Index\Entry as IndexEntry;
use Panth\LlmsTxt\Model\Summary\SummaryGenerator;

/**
 * Serialises the AI index as a structured JSON document at /llms.json.
 *
 * The JSON shape is deliberately stable + verbose so future LLM
 * tooling (Anthropic Files, OpenAI knowledge retrieval, custom
 * embeddings pipelines) can ingest it without scraping Markdown:
 *
 * {
 *   "schema": "panth.llms_txt/v1",
 *   "store": { "name", "base_url", "currency", "language", "summary" },
 *   "company": { "email", "phone", "address" },
 *   "sections": [
 *     {
 *       "code": "priority_urls",
 *       "label": "Priority URLs",
 *       "summary": "...",
 *       "entries": [
 *         { "url", "label", "type", "score", "summary", "metadata" }
 *       ]
 *     },
 *     ...
 *   ],
 *   "sitemap": {
 *     "sources": ["..."],
 *     "entries": [{ "url", "lastmod", "priority", "score" }]
 *   },
 *   "generated_at": "ISO-8601"
 * }
 */
class JsonBuilder
{
    public const XML_ENABLED = 'panth_llms_txt/json/enabled';

    private const SCHEMA_VERSION = 'panth.llms_txt/v1';
    private const CACHE_LIFETIME = 3600;

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AppEmulation $appEmulation,
        private readonly LlmsCache $cache,
        private readonly SummaryGenerator $summaryGenerator,
        private readonly SitemapFetcherInterface $sitemapFetcher,
        private readonly WeightedRankerInterface $ranker,
        private readonly StructuredIndex $structuredIndex
    ) {
    }

    public function build(int $storeId): string
    {
        $cacheKey = sprintf('panth_llms_json_v1_store_%d', $storeId);
        $hit = $this->cache->load($cacheKey);
        if (is_string($hit) && $hit !== '') {
            return $hit;
        }

        try {
            $this->appEmulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        } catch (\Throwable $e) {
            return json_encode([
                'schema'       => self::SCHEMA_VERSION,
                'error'        => 'store_not_available',
                'generated_at' => gmdate('c'),
            ], JSON_UNESCAPED_SLASHES) ?: '{}';
        }
        try {
            $document = $this->renderDocument($storeId);
        } finally {
            $this->appEmulation->stopEnvironmentEmulation();
        }

        $body = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!is_string($body)) {
            $body = '{"error":"json_encode failed"}';
        }
        $this->cache->save(
            $body,
            $cacheKey,
            [
                LlmsCache::CACHE_TAG,
                \Magento\Catalog\Model\Category::CACHE_TAG,
                \Magento\Catalog\Model\Product::CACHE_TAG,
                \Magento\Cms\Model\Page::CACHE_TAG,
                \Magento\Store\Model\Store::CACHE_TAG,
                'config_scopes',
            ],
            self::CACHE_LIFETIME
        );
        return $body;
    }

    public function isEnabled(int $storeId): bool
    {
        $raw = $this->scopeConfig->getValue(self::XML_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
        if ($raw === null || $raw === '') {
            return true;
        }
        return (bool) (int) $raw;
    }

    /**
     * @return array<string,mixed>
     */
    private function renderDocument(int $storeId): array
    {
        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (\Throwable) {
            return [
                'schema'       => self::SCHEMA_VERSION,
                'error'        => 'store_not_available',
                'generated_at' => gmdate('c'),
            ];
        }

        $baseUrl = rtrim((string) $store->getBaseUrl(), '/') . '/';
        $brand   = (string) $this->scopeConfig->getValue('general/store_information/name', ScopeInterface::SCOPE_STORE, $storeId);
        if ($brand === '') {
            $brand = (string) $store->getName();
        }
        $summary = (string) $this->scopeConfig->getValue('panth_llms_txt/llms_txt/summary', ScopeInterface::SCOPE_STORE, $storeId);
        if ($summary === '') {
            $summary = $this->summaryGenerator->generateStoreSummary($storeId);
        }

        $sections = $this->structuredIndex->collect($storeId);

        $sitemapEntries = [];
        foreach ($this->sitemapFetcher->fetchForStore($storeId) as $row) {
            $sitemapEntries[] = new IndexEntry(
                $row->getLocation(),
                (string) parse_url($row->getLocation(), PHP_URL_PATH),
                IndexEntryInterface::TYPE_SITEMAP,
                0.5,
                '',
                [
                    'sitemap_priority' => $row->getPriority() ?? 0.0,
                    'lastmod'          => $row->getLastModified(),
                ]
            );
        }
        $sitemapEntries = $this->ranker->rank($sitemapEntries, $storeId);

        return [
            'schema'       => self::SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'store'        => [
                'name'      => $brand,
                'base_url'  => $baseUrl,
                'currency'  => (string) $store->getCurrentCurrencyCode(),
                'language'  => (string) $this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $storeId),
                'summary'   => $summary,
                'store_view'=> (string) $store->getName(),
            ],
            'company'      => [
                'email'   => (string) $this->scopeConfig->getValue('trans_email/ident_general/email', ScopeInterface::SCOPE_STORE, $storeId),
                'phone'   => (string) $this->scopeConfig->getValue('general/store_information/phone', ScopeInterface::SCOPE_STORE, $storeId),
                'address' => (string) $this->scopeConfig->getValue('general/store_information/street_line1', ScopeInterface::SCOPE_STORE, $storeId),
                'city'    => (string) $this->scopeConfig->getValue('general/store_information/city', ScopeInterface::SCOPE_STORE, $storeId),
                'country' => (string) $this->scopeConfig->getValue('general/store_information/country_id', ScopeInterface::SCOPE_STORE, $storeId),
                'vat'     => (string) $this->scopeConfig->getValue('general/store_information/merchant_vat_number', ScopeInterface::SCOPE_STORE, $storeId),
            ],
            'sections'     => array_map([$this, 'serialiseSection'], $sections),
            'sitemap'      => [
                'count'   => count($sitemapEntries),
                'entries' => array_map([$this, 'serialiseEntry'], $sitemapEntries),
            ],
        ];
    }

    /**
     * @param array{code:string,label:string,summary:string,entries:IndexEntryInterface[]} $section
     * @return array<string,mixed>
     */
    private function serialiseSection(array $section): array
    {
        return [
            'code'    => $section['code'],
            'label'   => $section['label'],
            'summary' => $section['summary'],
            'count'   => count($section['entries']),
            'entries' => array_map([$this, 'serialiseEntry'], $section['entries']),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function serialiseEntry(IndexEntryInterface $entry): array
    {
        return [
            'url'      => $entry->getUrl(),
            'label'    => $entry->getLabel(),
            'type'     => $entry->getType(),
            'score'    => round($entry->getScore(), 4),
            'summary'  => $entry->getSummary(),
            'metadata' => $entry->getMetadata(),
        ];
    }
}
