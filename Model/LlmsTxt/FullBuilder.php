<?php
/**
 * Panth LLMs.txt — llms-full.txt body builder.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt;

use Magento\Catalog\Api\CategoryListInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Template\FilterProvider;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the expanded `/llms-full.txt` — a comprehensive version of llms.txt
 * that includes full store description, ALL categories with descriptions,
 * featured products with descriptions and prices, and full-text CMS content
 * for shipping, returns, FAQ, and about pages.
 */
class FullBuilder
{
    public const XML_ENABLED       = 'panth_llms_txt/llms_txt/generate_full_llms';
    public const XML_SHIPPING_PAGE = 'panth_llms_txt/llms_txt/shipping_page';
    public const XML_RETURNS_PAGE  = 'panth_llms_txt/llms_txt/returns_page';
    public const XML_ABOUT_PAGE    = 'panth_llms_txt/llms_txt/about_page';
    public const XML_FAQ_PAGE      = 'panth_llms_txt/llms_txt/faq_page';
    public const XML_SUMMARY       = 'panth_llms_txt/llms_txt/summary';

    /**
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param CategoryListInterface $categoryList
     * @param ProductRepositoryInterface $productRepository
     * @param PageRepositoryInterface $pageRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     * @param FilterProvider $filterProvider
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
        private readonly FilterProvider $filterProvider,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Render the Markdown body for /llms-full.txt.
     *
     * @param int $storeId
     * @return string
     */
    public function build(int $storeId): string
    {
        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (\Throwable) {
            return "# llms-full.txt\n\nStore not available.\n";
        }

        $base = rtrim((string) $store->getBaseUrl(), '/') . '/';
        $title = (string) $store->getName();
        $summary = (string) $this->scopeValue(self::XML_SUMMARY, $storeId);
        if ($summary === '') {
            $summary = (string) $this->scopeValue('general/store_information/name', $storeId);
        }
        if ($summary === '') {
            $summary = 'Online store catalog, products and editorial content.';
        }

        $storeDescription = (string) $this->scopeValue('design/head/default_description', $storeId);
        if ($storeDescription === '') {
            $storeDescription = (string) $this->scopeValue('general/store_information/street_line1', $storeId);
        }

        $lines = [];
        $lines[] = '# ' . ($title !== '' ? $title : 'Store') . ' (Full)';
        $lines[] = '';
        $lines[] = '> ' . $summary;
        $lines[] = '';
        $lines[] = sprintf('- URL: %s', $base);
        $lines[] = sprintf('- Generated: %s UTC', gmdate('Y-m-d H:i:s'));
        $lines[] = sprintf('- Format: llms-full.txt (expanded version)');
        $lines[] = '';

        // Full store description
        if ($storeDescription !== '') {
            $lines[] = '## About This Store';
            $lines[] = '';
            $lines[] = $storeDescription;
            $lines[] = '';
        }

        // About Us page content
        $aboutContent = $this->loadCmsPageContent(
            (string) $this->scopeValue(self::XML_ABOUT_PAGE, $storeId),
            $storeId
        );
        if ($aboutContent !== '') {
            $lines[] = '## About Us';
            $lines[] = '';
            $lines[] = $aboutContent;
            $lines[] = '';
        }

        // All categories with descriptions
        $lines[] = '## Categories';
        $lines[] = '';
        foreach ($this->loadAllCategories($storeId) as $row) {
            $line = sprintf('- [%s](%s)', $row['name'], $row['url']);
            if ($row['description'] !== '') {
                $line .= ': ' . $row['description'];
            }
            $lines[] = $line;
        }
        $lines[] = '';

        // Featured products with descriptions and prices
        $lines[] = '## Featured Products';
        $lines[] = '';
        foreach ($this->loadFeaturedProducts($storeId) as $row) {
            $parts = [sprintf('[%s](%s)', $row['name'], $row['url'])];
            if ($row['sku'] !== '') {
                $parts[] = 'SKU ' . $row['sku'];
            }
            if ($row['price'] !== '') {
                $parts[] = $row['price'];
            }
            $line = '- ' . implode(' | ', $parts);
            if ($row['description'] !== '') {
                $line .= "\n  " . $row['description'];
            }
            $lines[] = $line;
        }
        $lines[] = '';

        // Shipping policy
        $shippingContent = $this->loadCmsPageContent(
            (string) $this->scopeValue(self::XML_SHIPPING_PAGE, $storeId),
            $storeId
        );
        if ($shippingContent !== '') {
            $lines[] = '## Shipping Policy';
            $lines[] = '';
            $lines[] = $shippingContent;
            $lines[] = '';
        }

        // Return policy
        $returnsContent = $this->loadCmsPageContent(
            (string) $this->scopeValue(self::XML_RETURNS_PAGE, $storeId),
            $storeId
        );
        if ($returnsContent !== '') {
            $lines[] = '## Return Policy';
            $lines[] = '';
            $lines[] = $returnsContent;
            $lines[] = '';
        }

        // FAQ
        $faqContent = $this->loadCmsPageContent(
            (string) $this->scopeValue(self::XML_FAQ_PAGE, $storeId),
            $storeId
        );
        if ($faqContent !== '') {
            $lines[] = '## Frequently Asked Questions';
            $lines[] = '';
            $lines[] = $faqContent;
            $lines[] = '';
        }

        // Sitemaps
        $lines[] = '## Sitemaps';
        $lines[] = '';
        $lines[] = sprintf('- %s', $base . 'panth-sitemap.xml');
        $lines[] = sprintf('- %s', $base . 'sitemap.xml');
        $lines[] = sprintf('- %s', $base . 'robots.txt');
        $lines[] = sprintf('- %s', $base . 'llms.txt');
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Whether the expanded llms-full.txt is enabled for the given store.
     *
     * @param int $storeId
     * @return bool
     */
    public function isEnabled(int $storeId): bool
    {
        return (bool) $this->scopeConfig->isSetFlag(
            self::XML_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Load ALL active categories (no limit) with descriptions.
     *
     * @param int $storeId
     * @return array<int,array{name:string,url:string,description:string}>
     */
    private function loadAllCategories(int $storeId): array
    {
        try {
            $sortOrder = $this->sortOrderBuilder
                ->setField('position')
                ->setDirection('ASC')
                ->create();
            $criteria = $this->searchCriteriaBuilder
                ->addFilter('is_active', 1)
                ->addFilter('level', 2, 'gteq')
                ->addSortOrder($sortOrder)
                ->create();
            $result = $this->categoryList->getList($criteria);
        } catch (\Throwable $e) {
            $this->logger->warning('[panth_seo llms-full.txt] category list failed: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($result->getItems() as $cat) {
            $description = '';
            if (method_exists($cat, 'getDescription')) {
                $rawDesc = (string) $cat->getDescription();
                $description = $this->stripHtml($rawDesc);
                $description = $this->truncateText($description, 200);
            }
            $out[] = [
                'name'        => (string) $cat->getName(),
                'url'         => method_exists($cat, 'getUrl') ? (string) $cat->getUrl() : '',
                'description' => $description,
            ];
        }
        return $out;
    }

    /**
     * Load featured/recent products with descriptions and prices.
     *
     * @param int $storeId
     * @return array<int,array{name:string,url:string,sku:string,price:string,description:string}>
     */
    private function loadFeaturedProducts(int $storeId): array
    {
        try {
            $sortOrder = $this->sortOrderBuilder
                ->setField('updated_at')
                ->setDirection('DESC')
                ->create();
            $criteria = $this->searchCriteriaBuilder
                ->addFilter('status', 1)
                ->addFilter('visibility', 1, 'neq')
                ->addSortOrder($sortOrder)
                ->setPageSize(100)
                ->create();
            $result = $this->productRepository->getList($criteria);
        } catch (\Throwable $e) {
            $this->logger->warning('[panth_seo llms-full.txt] product list failed: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($result->getItems() as $product) {
            $description = '';
            if (method_exists($product, 'getShortDescription')) {
                $rawDesc = (string) ($product->getShortDescription() ?? '');
                if ($rawDesc === '' && method_exists($product, 'getDescription')) {
                    $rawDesc = (string) ($product->getDescription() ?? '');
                }
                $description = $this->stripHtml($rawDesc);
                $description = $this->truncateText($description, 300);
            }

            $price = '';
            if (method_exists($product, 'getFinalPrice')) {
                $finalPrice = $product->getFinalPrice();
                if ($finalPrice !== null && (float) $finalPrice > 0) {
                    $price = '$' . number_format((float) $finalPrice, 2);
                }
            }

            $out[] = [
                'name'        => (string) $product->getName(),
                'url'         => method_exists($product, 'getProductUrl') ? (string) $product->getProductUrl() : '',
                'sku'         => (string) $product->getSku(),
                'price'       => $price,
                'description' => $description,
            ];
        }
        return $out;
    }

    /**
     * Load CMS page content by identifier, stripped of HTML for plain-text output.
     *
     * @param string $identifier
     * @param int $storeId
     * @return string
     */
    private function loadCmsPageContent(string $identifier, int $storeId): string
    {
        if ($identifier === '') {
            return '';
        }

        try {
            $criteria = $this->searchCriteriaBuilder
                ->addFilter('identifier', $identifier)
                ->addFilter('is_active', 1)
                ->addFilter('store_id', [$storeId, 0], 'in')
                ->setPageSize(1)
                ->create();
            $result = $this->pageRepository->getList($criteria);
            $items = $result->getItems();
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[panth_seo llms-full.txt] CMS page load failed for "' . $identifier . '": ' . $e->getMessage()
            );
            return '';
        }

        if (empty($items)) {
            return '';
        }

        $page = reset($items);
        $content = (string) $page->getContent();
        if ($content === '') {
            return '';
        }

        // Process Magento widget/block directives through the CMS filter
        try {
            $filter = $this->filterProvider->getPageFilter();
            $content = $filter->filter($content);
        } catch (\Throwable) {
            // If CMS filtering fails, use raw content
        }

        return $this->stripHtml($content);
    }

    /**
     * Strip HTML tags and normalize whitespace for plain-text output.
     *
     * @param string $html
     * @return string
     */
    private function stripHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        // Convert <br>, </p>, </div>, </li> to newlines for readability
        $html = (string) preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = (string) preg_replace('/<\/(p|div|li|tr|h[1-6])>/i', "\n", $html);

        // Strip remaining HTML tags
        $text = strip_tags($html);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace: collapse multiple spaces/tabs, trim lines
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Truncate text to a maximum length, breaking at word boundary.
     *
     * @param string $text
     * @param int $maxLength
     * @return string
     */
    private function truncateText(string $text, int $maxLength): string
    {
        if ($text === '' || mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $maxLength);
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false && $lastSpace > $maxLength * 0.5) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return $truncated . '...';
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
}
