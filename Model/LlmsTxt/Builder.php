<?php
/**
 * Panth LLMs.txt — llms.txt body builder.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt;

use Magento\Catalog\Api\CategoryListInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the body of `/llms.txt` — the emerging standard proposed at
 * https://llmstxt.org/ that gives LLM crawlers a curated site map in Markdown.
 *
 * Structure:
 *  1. H1 title and a one-sentence summary (from store name / meta description).
 *  2. Key Sections: top-level CMS pages and blog roots.
 *  3. Top Categories: up to N top-level categories sorted by position.
 *  4. Top Products: up to N configured featured / most-recent products.
 *  5. Sitemaps: reference to robots-linked sitemaps.
 */
class Builder
{
    public const XML_ENABLED       = 'panth_llms_txt/llms_txt/enabled';
    public const XML_SUMMARY       = 'panth_llms_txt/llms_txt/summary';
    public const XML_MAX_CATEGORIES = 'panth_llms_txt/llms_txt/max_categories';
    public const XML_MAX_PRODUCTS   = 'panth_llms_txt/llms_txt/max_products';
    public const XML_MAX_CMS        = 'panth_llms_txt/llms_txt/max_cms';

    /**
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param CategoryListInterface $categoryList
     * @param ProductRepositoryInterface $productRepository
     * @param PageRepositoryInterface $pageRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CategoryListInterface $categoryList,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Render the Markdown body for /llms.txt.
     *
     * @param int $storeId
     * @return string
     */
    public function build(int $storeId): string
    {
        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (\Throwable) {
            return "# llms.txt\n\nStore not available.\n";
        }

        $base = rtrim($store->getBaseUrl(), '/') . '/';
        $title = (string) $store->getName();
        $summary = (string) $this->scopeValue(self::XML_SUMMARY, $storeId);
        if ($summary === '') {
            $summary = (string) $this->scopeValue('general/store_information/name', $storeId);
        }
        if ($summary === '') {
            $summary = 'Online store catalog, products and editorial content.';
        }

        $lines = [];
        $lines[] = '# ' . ($title !== '' ? $title : 'Store');
        $lines[] = '';
        $lines[] = '> ' . $summary;
        $lines[] = '';
        $lines[] = sprintf('- URL: %s', $base);
        $lines[] = sprintf('- Generated: %s UTC', gmdate('Y-m-d H:i:s'));
        $lines[] = '';

        // Key sections (CMS pages)
        $lines[] = '## Key Pages';
        $lines[] = '';
        foreach ($this->loadTopCmsPages($storeId, $this->intConfig(self::XML_MAX_CMS, $storeId, 15)) as $row) {
            $lines[] = sprintf('- [%s](%s)%s', $row['title'], $row['url'], $row['excerpt'] !== '' ? ': ' . $row['excerpt'] : '');
        }
        $lines[] = '';

        // Top categories
        $lines[] = '## Top Categories';
        $lines[] = '';
        foreach ($this->loadTopCategories($storeId, $this->intConfig(self::XML_MAX_CATEGORIES, $storeId, 30)) as $row) {
            $lines[] = sprintf('- [%s](%s)', $row['name'], $row['url']);
        }
        $lines[] = '';

        // Top products
        $lines[] = '## Top Products';
        $lines[] = '';
        foreach ($this->loadTopProducts($storeId, $this->intConfig(self::XML_MAX_PRODUCTS, $storeId, 50)) as $row) {
            $lines[] = sprintf('- [%s](%s)%s', $row['name'], $row['url'], $row['sku'] !== '' ? ' (SKU ' . $row['sku'] . ')' : '');
        }
        $lines[] = '';

        // Sitemaps
        $lines[] = '## Sitemaps';
        $lines[] = '';
        $lines[] = sprintf('- %s', $base . 'panth-sitemap.xml');
        $lines[] = sprintf('- %s', $base . 'sitemap.xml');
        $lines[] = sprintf('- %s', $base . 'robots.txt');
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Load top-N active CMS pages for the store.
     *
     * @param int $storeId
     * @param int $limit
     * @return array<int,array{title:string,url:string,excerpt:string}>
     */
    private function loadTopCmsPages(int $storeId, int $limit): array
    {
        try {
            $criteria = $this->searchCriteriaBuilder
                ->addFilter('is_active', 1)
                ->addFilter('store_id', $storeId)
                ->setPageSize($limit)
                ->create();
            $result = $this->pageRepository->getList($criteria);
        } catch (\Throwable $e) {
            $this->logger->warning('[panth_seo llms.txt] cms list failed: ' . $e->getMessage());
            return [];
        }

        $base = rtrim($this->storeOrDefault($storeId), '/') . '/';
        $out = [];
        foreach ($result->getItems() as $page) {
            $identifier = (string) $page->getIdentifier();
            if ($identifier === '') {
                continue;
            }
            $out[] = [
                'title' => (string) $page->getTitle(),
                'url' => $base . ltrim($identifier, '/'),
                'excerpt' => trim((string) ($page->getMetaDescription() ?? '')),
            ];
        }
        return $out;
    }

    /**
     * Load top-N active level-2/3 categories sorted by position.
     *
     * @param int $storeId
     * @param int $limit
     * @return array<int,array{name:string,url:string}>
     */
    private function loadTopCategories(int $storeId, int $limit): array
    {
        try {
            $sortOrder = $this->sortOrderBuilder->setField('position')->setDirection('ASC')->create();
            $criteria = $this->searchCriteriaBuilder
                ->addFilter('is_active', 1)
                ->addFilter('level', 2, 'gteq')
                ->addFilter('level', 3, 'lteq')
                ->addSortOrder($sortOrder)
                ->setPageSize($limit)
                ->create();
            $result = $this->categoryList->getList($criteria);
        } catch (\Throwable $e) {
            $this->logger->warning('[panth_seo llms.txt] category list failed: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($result->getItems() as $cat) {
            $out[] = [
                'name' => (string) $cat->getName(),
                'url' => method_exists($cat, 'getUrl') ? (string) $cat->getUrl() : '',
            ];
        }
        return $out;
    }

    /**
     * Load top-N most-recently-updated enabled, visible products.
     *
     * @param int $storeId
     * @param int $limit
     * @return array<int,array{name:string,url:string,sku:string}>
     */
    private function loadTopProducts(int $storeId, int $limit): array
    {
        try {
            $sortOrder = $this->sortOrderBuilder->setField('updated_at')->setDirection('DESC')->create();
            $criteria = $this->searchCriteriaBuilder
                ->addFilter('status', 1)
                ->addFilter('visibility', 1, 'neq')
                ->addSortOrder($sortOrder)
                ->setPageSize($limit)
                ->create();
            $result = $this->productRepository->getList($criteria);
        } catch (\Throwable $e) {
            $this->logger->warning('[panth_seo llms.txt] product list failed: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($result->getItems() as $product) {
            $out[] = [
                'name' => (string) $product->getName(),
                'url' => method_exists($product, 'getProductUrl') ? (string) $product->getProductUrl() : '',
                'sku' => (string) $product->getSku(),
            ];
        }
        return $out;
    }

    /**
     * Fetch a store-scoped config value.
     *
     * @param string $path
     * @param int $storeId
     * @return mixed
     */
    private function scopeValue(string $path, int $storeId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Fetch a positive integer config value with fallback default.
     *
     * @param string $path
     * @param int $storeId
     * @param int $default
     * @return int
     */
    private function intConfig(string $path, int $storeId, int $default): int
    {
        $val = $this->scopeValue($path, $storeId);
        return $val === null || $val === '' ? $default : max(1, (int) $val);
    }

    /**
     * Resolve the store's base URL with a safe fallback.
     *
     * @param int $storeId
     * @return string
     */
    private function storeOrDefault(int $storeId): string
    {
        try {
            return (string) $this->storeManager->getStore($storeId)->getBaseUrl();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Whether llms.txt generation is enabled for the given store.
     *
     * @param int $storeId
     * @return bool
     */
    public function isEnabled(int $storeId): bool
    {
        return (bool) $this->scopeConfig->isSetFlag(self::XML_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
