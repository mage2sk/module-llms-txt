<?php
/**
 * Panth LLMs.txt — summary generator.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\Summary;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Derives concise, data-driven summaries from live store state.
 *
 * The class never calls out to an external LLM — everything is built
 * from facts already in the catalog (product counts, top categories,
 * currency, locale, brand). The output reads naturally enough for an
 * LLM to use verbatim and cheaply enough to regenerate per request.
 *
 * Usage from the orchestrator:
 *
 *     $store    = $generator->generateStoreSummary($storeId);
 *     $category = $generator->generateCategorySummary($storeId, $categoryId);
 *     $type     = $generator->generateProductTypeSummary($storeId, 'configurable');
 */
class SummaryGenerator
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ResourceConnection $resourceConnection,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Top-level "what is this store?" sentence used in the Overview
     * section. Composed from: brand → product count → top category
     * names → currency.
     */
    public function generateStoreSummary(int $storeId): string
    {
        try {
            $store    = $this->storeManager->getStore($storeId);
            $brand    = (string) $this->scopeConfig->getValue('general/store_information/name', ScopeInterface::SCOPE_STORE, $storeId);
            if ($brand === '') {
                $brand = (string) $store->getName();
            }
            $currency = (string) $store->getCurrentCurrencyCode();
        } catch (\Throwable $e) {
            $this->logger->info('[panth_llms_txt] store summary store load failed: ' . $e->getMessage());
            return '';
        }
        $productCount = $this->countActiveProducts($storeId);
        $topCategories = $this->topCategoryNames($storeId, 3);

        if ($productCount === 0 && $topCategories === []) {
            return '';
        }

        $sentence = $brand !== '' ? $brand : 'This store';
        if ($productCount > 0) {
            $sentence .= sprintf(' offers %s products', number_format($productCount));
        }
        if ($topCategories !== []) {
            $sentence .= ' across categories such as ' . $this->joinHumanReadable($topCategories);
        }
        if ($currency !== '') {
            $sentence .= ' (priced in ' . $currency . ')';
        }
        return rtrim($sentence, '.') . '.';
    }

    /**
     * Per-category summary used under the category heading in the
     * tree. "{Name} contains {N} products from {min} to {max}."
     */
    public function generateCategorySummary(int $storeId, int $categoryId): string
    {
        try {
            $category = $this->categoryRepository->get($categoryId, $storeId);
        } catch (\Throwable) {
            return '';
        }
        $name = trim((string) $category->getName());
        if ($name === '') {
            return '';
        }
        $stats = $this->categoryStats($storeId, $categoryId);
        if ($stats['count'] === 0) {
            return '';
        }
        $sentence = sprintf('%s contains %s products', $name, number_format($stats['count']));
        if ($stats['min'] !== null && $stats['max'] !== null && $stats['max'] >= $stats['min']) {
            $sentence .= sprintf(' priced from %s to %s', $this->formatPrice($stats['min']), $this->formatPrice($stats['max']));
        }
        return $sentence . '.';
    }

    /**
     * Summary keyed by Magento product type id (`simple`,
     * `configurable`, `bundle`, etc).
     *
     * @param string[] $codes one or more product type ids; output is
     *                         `{N} configurable, {M} simple, {K} bundle`
     */
    public function generateProductTypeSummary(int $storeId, array $codes): string
    {
        if ($codes === []) {
            return '';
        }
        try {
            $conn  = $this->resourceConnection->getConnection();
            $table = $conn->getTableName('catalog_product_entity');
            $select = $conn->select()
                ->from($table, ['type_id', new \Zend_Db_Expr('COUNT(*) AS cnt')])
                ->where('type_id IN (?)', $codes)
                ->group('type_id');
            $rows = $conn->fetchAll($select);
        } catch (\Throwable $e) {
            $this->logger->info('[panth_llms_txt] product type summary failed: ' . $e->getMessage());
            return '';
        }
        if ($rows === []) {
            return '';
        }
        $parts = [];
        foreach ($rows as $row) {
            $count = (int) ($row['cnt'] ?? 0);
            if ($count > 0) {
                $parts[] = sprintf('%s %s', number_format($count), (string) $row['type_id']);
            }
        }
        if ($parts === []) {
            return '';
        }
        return 'Catalog mix: ' . $this->joinHumanReadable($parts) . ' products.';
    }

    /**
     * Count active, catalog-visible products for the store.
     */
    private function countActiveProducts(int $storeId): int
    {
        try {
            $collection = $this->productCollectionFactory->create();
            $collection->setStoreId($storeId)
                ->addAttributeToFilter('status', 1)
                ->addAttributeToFilter('visibility', ['gt' => 1]);
            return (int) $collection->getSize();
        } catch (\Throwable $e) {
            $this->logger->info('[panth_llms_txt] product count failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Names of top-level categories under the store root, sorted by
     * position. Limited to keep the summary readable.
     *
     * @return string[]
     */
    private function topCategoryNames(int $storeId, int $limit): array
    {
        try {
            $store  = $this->storeManager->getStore($storeId);
            $rootId = (int) $store->getRootCategoryId();
            if ($rootId <= 0) {
                return [];
            }
            $conn = $this->resourceConnection->getConnection();
            $catalogCategoryEntity = $conn->getTableName('catalog_category_entity');
            $catalogCategoryName = $conn->getTableName('catalog_category_entity_varchar');
            $eavAttribute = $conn->getTableName('eav_attribute');

            $attrIdSelect = $conn->select()
                ->from($eavAttribute, ['attribute_id'])
                ->where('attribute_code = ?', 'name')
                ->where('entity_type_id = ?', 3)
                ->limit(1);
            $attrId = (int) $conn->fetchOne($attrIdSelect);
            if ($attrId === 0) {
                return [];
            }

            $select = $conn->select()
                ->from(['c' => $catalogCategoryEntity], ['entity_id', 'position'])
                ->joinLeft(
                    ['n' => $catalogCategoryName],
                    sprintf('n.entity_id = c.entity_id AND n.attribute_id = %d AND n.store_id IN (0, %d)', $attrId, $storeId),
                    ['name' => 'n.value']
                )
                ->where('c.parent_id = ?', $rootId)
                ->where('c.level = ?', 2)
                ->order('c.position ASC')
                ->limit($limit * 2);
            $rows = $conn->fetchAll($select);
            $names = [];
            foreach ($rows as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name !== '' && !in_array($name, $names, true)) {
                    $names[] = $name;
                }
                if (count($names) >= $limit) {
                    break;
                }
            }
            return $names;
        } catch (\Throwable $e) {
            $this->logger->info('[panth_llms_txt] top categories query failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @return array{count:int, min:?float, max:?float}
     */
    private function categoryStats(int $storeId, int $categoryId): array
    {
        try {
            $collection = $this->productCollectionFactory->create();
            $collection->setStoreId($storeId)
                ->addAttributeToFilter('status', 1)
                ->addAttributeToFilter('visibility', ['gt' => 1])
                ->addCategoriesFilter(['in' => [$categoryId]])
                ->addPriceData();

            $count = (int) $collection->getSize();
            if ($count === 0) {
                return ['count' => 0, 'min' => null, 'max' => null];
            }

            $select = clone $collection->getSelect();
            $select->reset(\Magento\Framework\DB\Select::COLUMNS);
            $select->reset(\Magento\Framework\DB\Select::ORDER);
            $select->reset(\Magento\Framework\DB\Select::LIMIT_COUNT);
            $select->reset(\Magento\Framework\DB\Select::LIMIT_OFFSET);
            $select->columns([
                'min_price' => new \Zend_Db_Expr('MIN(price_index.final_price)'),
                'max_price' => new \Zend_Db_Expr('MAX(price_index.final_price)'),
            ]);
            $row = $collection->getConnection()->fetchRow($select);
            return [
                'count' => $count,
                'min'   => $row && $row['min_price'] !== null ? (float) $row['min_price'] : null,
                'max'   => $row && $row['max_price'] !== null ? (float) $row['max_price'] : null,
            ];
        } catch (\Throwable $e) {
            $this->logger->info('[panth_llms_txt] category stats failed: ' . $e->getMessage());
            return ['count' => 0, 'min' => null, 'max' => null];
        }
    }

    /**
     * @param string[] $items
     */
    private function joinHumanReadable(array $items): string
    {
        if ($items === []) {
            return '';
        }
        if (count($items) === 1) {
            return $items[0];
        }
        if (count($items) === 2) {
            return $items[0] . ' and ' . $items[1];
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }

    private function formatPrice(float $price): string
    {
        return number_format($price, 2, '.', ',');
    }
}
