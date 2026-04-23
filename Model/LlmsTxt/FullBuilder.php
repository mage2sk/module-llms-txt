<?php
/**
 * Panth LLMs.txt — llms-full.txt body builder.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt;

use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Template\FilterProvider;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Model\Cache\Type as LlmsCache;
use Panth\LlmsTxt\Model\LlmsTxt\Section\CategoryTree;
use Panth\LlmsTxt\Model\LlmsTxt\Section\Collections;
use Panth\LlmsTxt\Model\LlmsTxt\Section\KeyPages;
use Panth\LlmsTxt\Model\LlmsTxt\Section\Overview;
use Panth\LlmsTxt\Model\LlmsTxt\Section\PriorityUrls;
use Panth\LlmsTxt\Model\LlmsTxt\Section\Products;
use Psr\Log\LoggerInterface;

/**
 * Builds `/llms-full.txt` — the expanded variant of `/llms.txt` that
 * bundles policy-page bodies (shipping, returns, FAQ, about us) inline
 * so LLMs can answer customer questions verbatim without fetching
 * additional URLs.
 *
 * Shares the same Section building blocks as the compact builder —
 * header, priority URLs, collections, key pages, category tree and the
 * three curated product lists — then appends full CMS content from the
 * four admin-mapped policy pages.
 */
class FullBuilder
{
    public const XML_ENABLED       = 'panth_llms_txt/llms_txt/generate_full_llms';
    public const XML_SHIPPING_PAGE = 'panth_llms_txt/llms_txt/shipping_page';
    public const XML_RETURNS_PAGE  = 'panth_llms_txt/llms_txt/returns_page';
    public const XML_ABOUT_PAGE    = 'panth_llms_txt/llms_txt/about_page';
    public const XML_FAQ_PAGE      = 'panth_llms_txt/llms_txt/faq_page';
    public const XML_SUMMARY       = 'panth_llms_txt/llms_txt/summary';

    private const SCHEMA_VERSION  = 'v3';
    private const CACHE_LIFETIME  = 3600;

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly FilterProvider $filterProvider,
        private readonly LoggerInterface $logger,
        private readonly AppEmulation $appEmulation,
        private readonly LlmsCache $cache,
        private readonly Overview $overview,
        private readonly PriorityUrls $priorityUrls,
        private readonly Collections $collections,
        private readonly KeyPages $keyPages,
        private readonly CategoryTree $categoryTree,
        private readonly Products $products
    ) {
    }

    public function build(int $storeId): string
    {
        $cacheKey = sprintf('panth_llms_full_txt_%s_store_%d', self::SCHEMA_VERSION, $storeId);
        $hit = $this->cache->load($cacheKey);
        if (is_string($hit) && $hit !== '') {
            return $hit;
        }

        $this->appEmulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            $body = $this->renderBody($storeId);
        } finally {
            $this->appEmulation->stopEnvironmentEmulation();
        }

        $this->cache->save($body, $cacheKey, $this->cacheTags(), self::CACHE_LIFETIME);
        return $body;
    }

    public function isEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
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

    private function renderBody(int $storeId): string
    {
        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (\Throwable) {
            return "# llms-full.txt\n\nStore not available.\n";
        }

        $baseUrl = rtrim((string) $store->getBaseUrl(), '/') . '/';
        $brand   = (string) $this->scopeValue('general/store_information/name', $storeId);
        $title   = ($brand !== '' ? $brand : (string) $store->getName()) . ' (Full)';
        $summary = (string) $this->scopeValue(self::XML_SUMMARY, $storeId);
        if ($summary === '') {
            $summary = (string) $this->scopeValue('design/head/default_description', $storeId);
        }
        if ($summary === '') {
            $summary = 'Online store catalog, products and editorial content.';
        }

        $lines = [];

        foreach ($this->overview->renderHeader($storeId, $title, $summary, $baseUrl) as $l) { $lines[] = $l; }
        foreach ($this->overview->renderCompany($storeId) as $l)    { $lines[] = $l; }
        foreach ($this->priorityUrls->render($storeId) as $l)       { $lines[] = $l; }

        // "About" policy body ahead of the category / product bulk so the
        // LLM grounds on the merchant's own words first.
        $this->appendPolicy($lines, 'About Us', self::XML_ABOUT_PAGE, $storeId);

        foreach ($this->collections->render($storeId) as $l)        { $lines[] = $l; }
        foreach ($this->keyPages->render($storeId) as $l)           { $lines[] = $l; }
        foreach ($this->categoryTree->render($storeId) as $l)       { $lines[] = $l; }
        foreach ($this->products->renderFeatured($storeId) as $l)   { $lines[] = $l; }
        foreach ($this->products->renderBestsellers($storeId) as $l){ $lines[] = $l; }
        foreach ($this->products->renderRecent($storeId) as $l)     { $lines[] = $l; }

        $this->appendPolicy($lines, 'Shipping Policy',             self::XML_SHIPPING_PAGE, $storeId);
        $this->appendPolicy($lines, 'Return Policy',               self::XML_RETURNS_PAGE, $storeId);
        $this->appendPolicy($lines, 'Frequently Asked Questions',  self::XML_FAQ_PAGE, $storeId);

        // Sitemap footer
        $lines[] = '## Sitemaps';
        $lines[] = '';
        $lines[] = '- ' . $baseUrl . 'sitemap.xml';
        $lines[] = '- ' . $baseUrl . 'robots.txt';
        $lines[] = '- ' . $baseUrl . 'llms.txt';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Append a `## <heading>` block with the body of the CMS page that
     * matches the identifier stored at $configPath. No-op if the page
     * identifier is unset or the page can't be loaded.
     *
     * @param string[] $lines  rendered lines, mutated in place
     */
    private function appendPolicy(array &$lines, string $heading, string $configPath, int $storeId): void
    {
        $identifier = trim((string) $this->scopeValue($configPath, $storeId));
        if ($identifier === '') {
            return;
        }
        $body = $this->loadCmsPageBody($identifier, $storeId);
        if ($body === '') {
            return;
        }
        $lines[] = '## ' . $heading;
        $lines[] = '';
        $lines[] = $body;
        $lines[] = '';
    }

    /**
     * Load a CMS page's body, run Magento's page-filter so widgets / blocks
     * resolve, then strip to clean text.
     */
    private function loadCmsPageBody(string $identifier, int $storeId): string
    {
        try {
            $criteria = $this->searchCriteriaBuilder
                ->addFilter('identifier', $identifier)
                ->addFilter('is_active', 1)
                ->addFilter('store_id', [$storeId, 0], 'in')
                ->setPageSize(1)
                ->create();
            $items = $this->pageRepository->getList($criteria)->getItems();
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[panth_llms_txt] CMS page load failed for "' . $identifier . '": ' . $e->getMessage()
            );
            return '';
        }
        if ($items === []) {
            return '';
        }
        $page = reset($items);
        $content = (string) $page->getContent();
        if ($content === '') {
            return '';
        }

        try {
            $filter = $this->filterProvider->getPageFilter();
            $content = $filter->filter($content);
        } catch (\Throwable) {
            // use raw content if CMS filter chain fails
        }

        return $this->stripHtml($content);
    }

    private function stripHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $html = (string) preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = (string) preg_replace('/<\/(p|div|li|tr|h[1-6])>/i', "\n", $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    private function scopeValue(string $path, int $storeId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
