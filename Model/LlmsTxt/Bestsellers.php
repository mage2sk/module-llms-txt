<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class Bestsellers
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getProductIds(int $storeId, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        $fetch = $limit * 5;
        $ids = $this->fromAggregation($storeId, $fetch);
        if ($ids === []) {
            $ids = $this->fromOrderItems($storeId, $fetch);
        }
        if ($ids === []) {
            $this->logger->info(sprintf(
                '[panth_llms_txt] no sales data for store %d, best sellers section omitted',
                $storeId
            ));
            return [];
        }
        return $this->mapToParents($ids);
    }

    private function fromAggregation(int $storeId, int $limit): array
    {
        try {
            $conn  = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('sales_bestsellers_aggregated_yearly');
            if (!$conn->isTableExists($table)) {
                return [];
            }
            $select = $conn->select()
                ->from($table, ['product_id', 'qty' => new \Zend_Db_Expr('SUM(qty_ordered)')])
                ->where('store_id = ?', $storeId)
                ->group('product_id')
                ->order('qty DESC')
                ->order('product_id ASC')
                ->limit($limit);
            return array_map('intval', $conn->fetchCol($select));
        } catch (\Throwable $e) {
            $this->logger->info('[panth_llms_txt] bestseller aggregation query failed: ' . $e->getMessage());
            return [];
        }
    }

    private function fromOrderItems(int $storeId, int $limit): array
    {
        try {
            $conn   = $this->resourceConnection->getConnection();
            $items  = $this->resourceConnection->getTableName('sales_order_item');
            $orders = $this->resourceConnection->getTableName('sales_order');
            if (!$conn->isTableExists($items) || !$conn->isTableExists($orders)) {
                return [];
            }
            $select = $conn->select()
                ->from(['oi' => $items], ['product_id', 'qty' => new \Zend_Db_Expr('SUM(oi.qty_ordered)')])
                ->join(['o' => $orders], 'o.entity_id = oi.order_id', [])
                ->where('oi.store_id = ?', $storeId)
                ->where('oi.parent_item_id IS NULL')
                ->where('oi.product_id IS NOT NULL')
                ->where('o.state <> ?', 'canceled')
                ->group('oi.product_id')
                ->order('qty DESC')
                ->order('oi.product_id ASC')
                ->limit($limit);
            return array_map('intval', $conn->fetchCol($select));
        } catch (\Throwable $e) {
            $this->logger->info('[panth_llms_txt] bestseller order item query failed: ' . $e->getMessage());
            return [];
        }
    }

    private function mapToParents(array $ids): array
    {
        $parents = [];
        try {
            $conn  = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('catalog_product_relation');
            if ($conn->isTableExists($table)) {
                $select = $conn->select()
                    ->from($table, ['child_id', 'parent_id'])
                    ->where('child_id IN (?)', $ids);
                foreach ($conn->fetchAll($select) as $row) {
                    $child = (int) $row['child_id'];
                    if (!isset($parents[$child])) {
                        $parents[$child] = (int) $row['parent_id'];
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->info('[panth_llms_txt] bestseller parent lookup failed: ' . $e->getMessage());
        }

        $out = [];
        foreach ($ids as $id) {
            if ($id <= 0) {
                continue;
            }
            $out[] = $id;
            if (isset($parents[$id])) {
                $out[] = $parents[$id];
            }
        }
        return array_values(array_unique($out));
    }
}
