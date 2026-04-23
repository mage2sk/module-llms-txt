<?php
/**
 * Panth LLMs.txt — curated product sections.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt\Section;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Emits three curated product blocks:
 *
 *   - **Featured** — products flagged by the merchant via a configurable
 *     EAV attribute (default `is_featured`). Falls back to an empty list
 *     when the attribute doesn't exist on the install.
 *
 *   - **Best Sellers** — products with the highest lifetime order count
 *     for the current store, pulled from Magento's aggregated bestseller
 *     table (`sales_bestsellers_aggregated_yearly`). O(1) queries, no
 *     collection scans.
 *
 *   - **Recent Arrivals** — products sorted by `created_at DESC`.
 *
 * Pre-v1.2 the module just dumped `N products sorted by updated_at`,
 * which was noisy and produced the same list every day whether the
 * merchant had highlighted something or not. The three curated lists
 * give the LLM an explicit signal about which products the merchant
 * actually wants surfaced.
 *
 * Each product rendered as a single Markdown line:
 *
 *     - [Product Name](url) — $12.34 — SKU ABC123 — Category (Short description)
 *
 * Category name is the first category the product is assigned to
 * (cheap to resolve, deterministic for a given catalog state).
 */
class Products
{
    public const XML_FEATURED_ATTRIBUTE = 'panth_llms_txt/llms_txt/featured_attribute';
    public const XML_MAX_FEATURED       = 'panth_llms_txt/llms_txt/max_featured';
    public const XML_SHOW_BESTSELLERS   = 'panth_llms_txt/llms_txt/show_bestsellers';
    public const XML_MAX_BESTSELLERS    = 'panth_llms_txt/llms_txt/max_bestsellers';
    public const XML_SHOW_RECENT        = 'panth_llms_txt/llms_txt/show_recent';
    public const XML_MAX_RECENT         = 'panth_llms_txt/llms_txt/max_recent';
    public const XML_INCLUDE_DESCRIPTION = 'panth_llms_txt/llms_txt/include_short_description';

    private const DEFAULT_FEATURED_ATTRIBUTE = 'is_featured';
    private const DEFAULT_MAX_FEATURED       = 6;
    private const DEFAULT_MAX_BESTSELLERS    = 10;
    private const DEFAULT_MAX_RECENT         = 10;

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ResourceConnection $resourceConnection,
        private readonly Visibility $visibility,
        private readonly ShortDescription $shortDescription,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Render the "Featured Products" section.
     *
     * @return string[]
     */
    public function renderFeatured(int $storeId): array
    {
        $limit = $this->intConfig(self::XML_MAX_FEATURED, $storeId, self::DEFAULT_MAX_FEATURED);
        if ($limit <= 0) {
            return [];
        }

        $attributeCode = trim((string) $this->scopeValue(self::XML_FEATURED_ATTRIBUTE, $storeId));
        if ($attributeCode === '') {
            $attributeCode = self::DEFAULT_FEATURED_ATTRIBUTE;
        }

        try {
            $collection = $this->baseCollection($storeId, $limit);
            $collection->addAttributeToFilter($attributeCode, 1);
        } catch (\Throwable $e) {
            // The attribute probably doesn't exist on this install — that's fine,
            // the section just stays empty.
            $this->logger->info('[panth_llms_txt] featured attribute unavailable: ' . $e->getMessage());
            return [];
        }

        return $this->renderSection('Featured Products', $collection, $storeId);
    }

    /**
     * Render the "Best Sellers" section.
     *
     * @return string[]
     */
    public function renderBestsellers(int $storeId): array
    {
        if (!$this->flag(self::XML_SHOW_BESTSELLERS, $storeId, true)) {
            return [];
        }
        $limit = $this->intConfig(self::XML_MAX_BESTSELLERS, $storeId, self::DEFAULT_MAX_BESTSELLERS);
        if ($limit <= 0) {
            return [];
        }

        $productIds = $this->bestsellerIds($storeId, $limit);
        if ($productIds === []) {
            // New store with no orders yet — fall through with an empty section.
            return [];
        }

        try {
            $collection = $this->baseCollection($storeId, $limit);
            $collection->addFieldToFilter('entity_id', ['in' => $productIds]);
            // Preserve bestseller ranking in the output.
            $collection->getSelect()->order(
                $this->resourceConnection->getConnection()
                    ->quoteInto('FIELD(e.entity_id, ?)', $productIds)
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[panth_llms_txt] bestseller collection failed: ' . $e->getMessage());
            return [];
        }

        return $this->renderSection('Best Sellers', $collection, $storeId);
    }

    /**
     * Render the "Recent Arrivals" section.
     *
     * @return string[]
     */
    public function renderRecent(int $storeId): array
    {
        if (!$this->flag(self::XML_SHOW_RECENT, $storeId, true)) {
            return [];
        }
        $limit = $this->intConfig(self::XML_MAX_RECENT, $storeId, self::DEFAULT_MAX_RECENT);
        if ($limit <= 0) {
            return [];
        }

        try {
            $collection = $this->baseCollection($storeId, $limit);
            $collection->setOrder('created_at', 'DESC');
        } catch (\Throwable $e) {
            $this->logger->warning('[panth_llms_txt] recent collection failed: ' . $e->getMessage());
            return [];
        }

        return $this->renderSection('Recent Arrivals', $collection, $storeId);
    }

    /**
     * Build a product collection scoped to one store + pre-filtered to
     * enabled, catalog-visible products with catalog attributes selected.
     */
    private function baseCollection(int $storeId, int $limit): \Magento\Catalog\Model\ResourceModel\Product\Collection
    {
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect(['name', 'sku', 'short_description', 'meta_description', 'description', 'url_key', 'url_path'])
            ->addAttributeToFilter('status', 1)
            ->setVisibility($this->visibility->getVisibleInCatalogIds())
            ->addPriceData()
            ->setPageSize($limit);
        return $collection;
    }

    /**
     * Fetch up to $limit bestseller product ids for the store.
     *
     * Reads `sales_bestsellers_aggregated_yearly` directly because the
     * aggregated table is pre-summed by Magento's bestseller indexer and
     * keyed by store — no cross-join to sales_order_item required.
     *
     * @return int[] ids in descending qty-ordered rank
     */
    private function bestsellerIds(int $storeId, int $limit): array
    {
        try {
            $conn = $this->resourceConnection->getConnection();
            $table = $conn->getTableName('sales_bestsellers_aggregated_yearly');
            $select = $conn->select()
                ->from($table, ['product_id'])
                ->where('store_id = ?', $storeId)
                ->order('qty_ordered DESC')
                ->limit($limit * 2); // fetch extra so we can dedupe per product_id
            $rows = $conn->fetchCol($select);
        } catch (\Throwable $e) {
            $this->logger->info('[panth_llms_txt] bestseller query failed: ' . $e->getMessage());
            return [];
        }

        $unique = [];
        foreach ($rows as $pid) {
            $pid = (int) $pid;
            if ($pid > 0 && !in_array($pid, $unique, true)) {
                $unique[] = $pid;
                if (count($unique) >= $limit) {
                    break;
                }
            }
        }
        return $unique;
    }

    /**
     * Render one labelled section from an already-built collection.
     *
     * @param string $heading
     * @param \Magento\Catalog\Model\ResourceModel\Product\Collection $collection
     * @param int $storeId
     * @return string[]
     */
    private function renderSection(
        string $heading,
        \Magento\Catalog\Model\ResourceModel\Product\Collection $collection,
        int $storeId
    ): array {
        $includeDescription = $this->flag(self::XML_INCLUDE_DESCRIPTION, $storeId, true);
        $lines = [];
        foreach ($collection as $product) {
            $line = $this->renderProductLine($product, $includeDescription);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        if ($lines === []) {
            return [];
        }

        array_unshift($lines, '## ' . $heading, '');
        $lines[] = '';
        return $lines;
    }

    /**
     * Render a single product's Markdown list item.
     */
    private function renderProductLine(ProductInterface $product, bool $includeDescription): string
    {
        $name = trim((string) $product->getName());
        if ($name === '') {
            return '';
        }
        $url = '';
        if (method_exists($product, 'getProductUrl')) {
            try {
                $url = (string) $product->getProductUrl();
            } catch (\Throwable) {
                // fall through
            }
        }
        $sku = trim((string) $product->getSku());
        $category = $this->firstCategoryName($product);

        $priceStr = '';
        if (method_exists($product, 'getFinalPrice')) {
            $price = (float) $product->getFinalPrice();
            if ($price > 0) {
                try {
                    $priceStr = (string) $this->priceCurrency->format($price, false);
                } catch (\Throwable) {
                    $priceStr = '$' . number_format($price, 2);
                }
            }
        }

        $parts = [sprintf('[%s](%s)', $name, $url !== '' ? $url : '#')];
        if ($priceStr !== '') {
            $parts[] = $priceStr;
        }
        if ($sku !== '') {
            $parts[] = 'SKU ' . $sku;
        }
        if ($category !== '') {
            $parts[] = $category;
        }

        $line = '- ' . implode(' — ', $parts);

        if ($includeDescription) {
            $descr = $this->shortDescription->resolve($product, $category);
            if ($descr !== '') {
                $line .= "\n  " . $descr;
            }
        }

        return $line;
    }

    /**
     * First assigned category name for a product, empty string if none.
     */
    private function firstCategoryName(ProductInterface $product): string
    {
        if (!method_exists($product, 'getCategoryCollection')) {
            return '';
        }
        try {
            $collection = $product->getCategoryCollection()
                ->addAttributeToSelect('name')
                ->setPageSize(1);
            foreach ($collection as $cat) {
                return trim((string) $cat->getName());
            }
        } catch (\Throwable) {
            // no-op
        }
        return '';
    }

    private function scopeValue(string $path, int $storeId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function flag(string $path, int $storeId, bool $default): bool
    {
        $raw = $this->scopeValue($path, $storeId);
        if ($raw === null || $raw === '') {
            return $default;
        }
        return (bool) (int) $raw;
    }

    private function intConfig(string $path, int $storeId, int $default): int
    {
        $raw = $this->scopeValue($path, $storeId);
        if ($raw === null || $raw === '') {
            return $default;
        }
        return max(0, (int) $raw);
    }
}
