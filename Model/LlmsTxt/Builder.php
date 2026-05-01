<?php
/**
 * Panth LLMs.txt — llms.txt body builder.
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
use Panth\LlmsTxt\Api\SitemapFetcherInterface;
use Panth\LlmsTxt\Model\Cache\Type as LlmsCache;
use Panth\LlmsTxt\Model\LlmsTxt\Section\CategoryTree;
use Panth\LlmsTxt\Model\LlmsTxt\Section\Collections;
use Panth\LlmsTxt\Model\LlmsTxt\Section\KeyPages;
use Panth\LlmsTxt\Model\LlmsTxt\Section\Overview;
use Panth\LlmsTxt\Model\LlmsTxt\Section\PriorityUrls;
use Panth\LlmsTxt\Model\LlmsTxt\Section\ProductTypes;
use Panth\LlmsTxt\Model\LlmsTxt\Section\Products;
use Panth\LlmsTxt\Model\LlmsTxt\Section\Sitemap;
use Panth\LlmsTxt\Model\LlmsTxt\Section\UseCases;
use Panth\LlmsTxt\Model\Summary\SummaryGenerator;

/**
 * Builds `/llms.txt` — the compact LLM-oriented Markdown site map.
 *
 * This class is a lean orchestrator. Each content block lives in its own
 * {@see \Panth\LlmsTxt\Model\LlmsTxt\Section\SectionInterface}
 * implementation so the slim file stays focused on "what sections in
 * what order" rather than every implementation detail.
 *
 * Order of sections (v1.2+):
 *   1. Header (H1 title + summary + Store Overview metadata block)
 *   2. Company
 *   3. Priority URLs (admin-authored)
 *   4. Collections (admin-picked landing categories)
 *   5. Key Pages (CMS)
 *   6. Category Tree (hierarchical)
 *   7. Featured Products
 *   8. Best Sellers
 *   9. Recent Arrivals
 *  10. Sitemaps
 *
 * Rendered content is cached in the dedicated `panth_llms_txt` cache
 * type with catalog / CMS / store / config tags so ordinary admin saves
 * invalidate the output without any custom observer wiring.
 */
class Builder
{
    public const XML_ENABLED = 'panth_llms_txt/llms_txt/enabled';
    public const XML_SUMMARY = 'panth_llms_txt/llms_txt/summary';

    /**
     * Schema version — bump to force cache invalidation when the output
     * format changes without a manual flush.
     */
    private const SCHEMA_VERSION = 'v5';

    /**
     * Cache TTL upper bound. Tag invalidation overrides this on admin
     * saves — see cacheTags().
     */
    private const CACHE_LIFETIME = 3600;

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AppEmulation $appEmulation,
        private readonly LlmsCache $cache,
        private readonly Overview $overview,
        private readonly PriorityUrls $priorityUrls,
        private readonly Collections $collections,
        private readonly KeyPages $keyPages,
        private readonly CategoryTree $categoryTree,
        private readonly Products $products,
        private readonly ProductTypes $productTypes,
        private readonly UseCases $useCases,
        private readonly Sitemap $sitemap,
        private readonly SummaryGenerator $summaryGenerator,
        private readonly SitemapFetcherInterface $sitemapFetcher
    ) {
    }

    /**
     * Render the Markdown body for /llms.txt for one store, caching the
     * output under a store-scoped + schema-versioned key.
     */
    public function build(int $storeId): string
    {
        $cacheKey = sprintf('panth_llms_txt_%s_store_%d', self::SCHEMA_VERSION, $storeId);
        $hit = $this->cache->load($cacheKey);
        if (is_string($hit) && $hit !== '') {
            return $hit;
        }

        try {
            $this->appEmulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        } catch (\Throwable $e) {
            return "# llms.txt\n\nStore not available.\n";
        }
        try {
            $body = $this->renderBody($storeId);
        } finally {
            $this->appEmulation->stopEnvironmentEmulation();
        }

        $this->cache->save($body, $cacheKey, $this->cacheTags(), self::CACHE_LIFETIME);
        return $body;
    }

    /**
     * Whether /llms.txt is enabled for the given store.
     */
    public function isEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Cache tags this type emits. Matches Magento's standard catalog /
     * CMS / store / config tag names so admin-save tag-clean calls hit
     * this type automatically.
     *
     * @return string[]
     */
    public function cacheTags(): array
    {
        return [
            LlmsCache::CACHE_TAG,
            \Magento\Catalog\Model\Category::CACHE_TAG,
            \Magento\Catalog\Model\Product::CACHE_TAG,
            \Magento\Cms\Model\Page::CACHE_TAG,
            \Magento\Store\Model\Store::CACHE_TAG,
            'config_scopes',
        ];
    }

    /**
     * Compose the output by asking each section for its lines, appending
     * them in spec order, and finishing with a sitemaps footer.
     */
    private function renderBody(int $storeId): string
    {
        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (\Throwable) {
            return "# llms.txt\n\nStore not available.\n";
        }

        $baseUrl = rtrim((string) $store->getBaseUrl(), '/') . '/';
        $title   = $this->resolveTitle($storeId, $store);
        $summary = $this->resolveSummary($storeId);

        $lines = [];

        // Header + metadata block
        foreach ($this->overview->renderHeader($storeId, $title, $summary, $baseUrl) as $l) {
            $lines[] = $l;
        }

        // Contact + identity sections
        foreach ($this->overview->renderCompany($storeId) as $l) { $lines[] = $l; }
        foreach ($this->priorityUrls->render($storeId) as $l)     { $lines[] = $l; }
        foreach ($this->collections->render($storeId) as $l)      { $lines[] = $l; }
        foreach ($this->keyPages->render($storeId) as $l)         { $lines[] = $l; }

        // Taxonomy + product type metadata + shopper-intent buckets
        foreach ($this->categoryTree->render($storeId) as $l)     { $lines[] = $l; }
        foreach ($this->productTypes->render($storeId) as $l)     { $lines[] = $l; }
        foreach ($this->useCases->render($storeId) as $l)         { $lines[] = $l; }

        // Curated product blocks
        foreach ($this->products->renderFeatured($storeId) as $l)    { $lines[] = $l; }
        foreach ($this->products->renderBestsellers($storeId) as $l) { $lines[] = $l; }
        foreach ($this->products->renderRecent($storeId) as $l)      { $lines[] = $l; }

        // Sitemap-derived URL highlights (ranked)
        foreach ($this->sitemap->render($storeId) as $l)             { $lines[] = $l; }

        // Sitemap footer — pointers to alternate AI index formats and
        // the canonical Magento sitemap(s) / robots so a crawler that
        // wants to go deeper has the entry points it needs.
        //
        // The sitemap line(s) come from SitemapFetcher::getSitemapUrls()
        // which honours the merchant's panth_llms_txt/sitemap/urls
        // textarea — so a custom split sitemap (sitemap_products.xml,
        // sitemap_categories.xml, etc.) is listed verbatim instead of
        // the wrong-by-default {baseUrl}sitemap.xml.
        $lines[] = '## Index Formats';
        $lines[] = '';
        foreach ($this->sitemapFetcher->getSitemapUrls($storeId) as $sitemapUrl) {
            $lines[] = '- ' . $sitemapUrl;
        }
        $lines[] = '- ' . $baseUrl . 'robots.txt';
        $lines[] = '- ' . $baseUrl . 'llms-full.txt';
        $lines[] = '- ' . $baseUrl . 'llms.json';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Prefer general/store_information/name (merchant-facing brand name)
     * over $store->getName() which is the internal store view label.
     */
    private function resolveTitle(int $storeId, \Magento\Store\Api\Data\StoreInterface $store): string
    {
        $brand = (string) $this->scopeConfig->getValue('general/store_information/name', ScopeInterface::SCOPE_STORE, $storeId);
        if ($brand !== '') {
            return $brand;
        }
        $name = (string) $store->getName();
        return $name !== '' ? $name : 'Store';
    }

    /**
     * Summary fallback chain: admin textarea → auto-generated from
     * store metadata → design/head/default_description → boilerplate.
     *
     * The auto-generation tier was added in v1.3 so an out-of-the-box
     * install produces a meaningful one-line summary ("Acme Store
     * offers 1,240 products across categories such as Tops, Bottoms
     * and Accessories priced in USD.") without the merchant having
     * to author one manually.
     */
    private function resolveSummary(int $storeId): string
    {
        $summary = (string) $this->scopeConfig->getValue(self::XML_SUMMARY, ScopeInterface::SCOPE_STORE, $storeId);
        if ($summary !== '') {
            return $summary;
        }
        $auto = $this->summaryGenerator->generateStoreSummary($storeId);
        if ($auto !== '') {
            return $auto;
        }
        $head = (string) $this->scopeConfig->getValue('design/head/default_description', ScopeInterface::SCOPE_STORE, $storeId);
        if ($head !== '') {
            return $head;
        }
        return 'Online store catalog, products and editorial content.';
    }
}
