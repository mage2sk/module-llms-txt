<?php
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
use Panth\LlmsTxt\Model\LlmsTxt\Section\OptionalIntegrations;
use Panth\LlmsTxt\Model\LlmsTxt\Section\Overview;
use Panth\LlmsTxt\Model\LlmsTxt\Section\PriorityUrls;
use Panth\LlmsTxt\Model\LlmsTxt\Section\ProductTypes;
use Panth\LlmsTxt\Model\LlmsTxt\Section\Products;
use Panth\LlmsTxt\Model\LlmsTxt\Section\Sitemap;
use Panth\LlmsTxt\Model\LlmsTxt\Section\UseCases;
use Panth\LlmsTxt\Model\Summary\SummaryGenerator;

class Builder
{
    public const XML_ENABLED = 'panth_llms_txt/llms_txt/enabled';
    public const XML_SUMMARY = 'panth_llms_txt/llms_txt/summary';

    private const SCHEMA_VERSION = 'v7';

    private const CACHE_LIFETIME = 3600;
    private const STORE_UNAVAILABLE = "# llms.txt\n\nStore not available.\n";

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
        private readonly SitemapFetcherInterface $sitemapFetcher,
        private readonly OptionalIntegrations $optionalIntegrations
    ) {
    }

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
            return self::STORE_UNAVAILABLE;
        }
        try {
            $body = $this->renderBody($storeId);
        } finally {
            $this->appEmulation->stopEnvironmentEmulation();
        }

        if ($body !== self::STORE_UNAVAILABLE) {
            $this->cache->save($body, $cacheKey, $this->cacheTags(), self::CACHE_LIFETIME);
        }
        return $body;
    }

    public function isEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

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

    private function renderBody(int $storeId): string
    {
        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (\Throwable) {
            return self::STORE_UNAVAILABLE;
        }

        $baseUrl = rtrim((string) $store->getBaseUrl(), '/') . '/';
        $title   = $this->resolveTitle($storeId, $store);
        $summary = $this->resolveSummary($storeId);

        $lines = [];

        foreach ($this->overview->renderHeader($storeId, $title, $summary, $baseUrl) as $l) {
            $lines[] = $l;
        }

        foreach ($this->overview->renderCompany($storeId) as $l) { $lines[] = $l; }
        foreach ($this->priorityUrls->render($storeId) as $l)     { $lines[] = $l; }
        foreach ($this->collections->render($storeId) as $l)      { $lines[] = $l; }
        foreach ($this->keyPages->render($storeId) as $l)         { $lines[] = $l; }

        foreach ($this->categoryTree->render($storeId) as $l)     { $lines[] = $l; }
        foreach ($this->productTypes->render($storeId) as $l)     { $lines[] = $l; }
        foreach ($this->useCases->render($storeId) as $l)         { $lines[] = $l; }

        foreach ($this->products->renderFeatured($storeId) as $l)    { $lines[] = $l; }
        foreach ($this->products->renderBestsellers($storeId) as $l) { $lines[] = $l; }
        foreach ($this->products->renderRecent($storeId) as $l)      { $lines[] = $l; }

        foreach ($this->optionalIntegrations->render($storeId) as $l) { $lines[] = $l; }

        foreach ($this->sitemap->render($storeId) as $l)             { $lines[] = $l; }

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

    private function resolveTitle(int $storeId, \Magento\Store\Api\Data\StoreInterface $store): string
    {
        $brand = (string) $this->scopeConfig->getValue('general/store_information/name', ScopeInterface::SCOPE_STORE, $storeId);
        if ($brand !== '') {
            return $brand;
        }
        $name = (string) $store->getName();
        return $name !== '' ? $name : 'Store';
    }

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
