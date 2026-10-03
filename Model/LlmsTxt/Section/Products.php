<?php
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
use Panth\LlmsTxt\Model\LlmsTxt\Bestsellers;
use Psr\Log\LoggerInterface;

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
        private readonly LoggerInterface $logger,
        private readonly Bestsellers $bestsellers
    ) {
    }

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
            $this->logger->info('[panth_llms_txt] featured attribute unavailable: ' . $e->getMessage());
            return [];
        }

        return $this->renderSection('Featured Products', $collection, $storeId);
    }

    public function renderBestsellers(int $storeId): array
    {
        if (!$this->flag(self::XML_SHOW_BESTSELLERS, $storeId, true)) {
            return [];
        }
        $limit = $this->intConfig(self::XML_MAX_BESTSELLERS, $storeId, self::DEFAULT_MAX_BESTSELLERS);
        if ($limit <= 0) {
            return [];
        }

        $productIds = $this->bestsellers->getProductIds($storeId, $limit);
        if ($productIds === []) {
            return [];
        }

        try {
            $collection = $this->baseCollection($storeId, $limit);
            $collection->addFieldToFilter('entity_id', ['in' => $productIds]);

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
            }
        }
        $sku = trim((string) $product->getSku());
        $category = $this->firstCategoryName($product);

        $priceStr = '';
        if (method_exists($product, 'getFinalPrice')) {
            $price = (float) $product->getFinalPrice();
            if ($price > 0) {
                try {
                    $priceStr = (string) $this->priceCurrency->convertAndFormat($price, false);
                } catch (\Throwable) {
                    $priceStr = '';
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

        $line = '- ' . implode(' - ', $parts);

        if ($includeDescription) {
            $descr = $this->shortDescription->resolve($product, $category);
            if ($descr !== '') {
                $line .= "\n  " . $descr;
            }
        }

        return $line;
    }

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
