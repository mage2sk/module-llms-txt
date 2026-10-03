<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt\Section;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Panth\LlmsTxt\Model\Summary\SummaryGenerator;
use Psr\Log\LoggerInterface;

class ProductTypes implements SectionInterface
{
    public const XML_ENABLED = 'panth_llms_txt/product_types/enabled';

    private const DEFAULT_TYPES = ['simple', 'configurable', 'bundle', 'grouped', 'virtual', 'downloadable'];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SummaryGenerator $summaryGenerator,
        private readonly LoggerInterface $logger
    ) {
    }

    public function render(int $storeId): array
    {
        if (!$this->flag(self::XML_ENABLED, $storeId, true)) {
            return [];
        }

        $counts = $this->countByType();
        if ($counts === []) {
            return [];
        }

        $lines = ['## Product Types', ''];
        $summary = $this->summaryGenerator->generateProductTypeSummary($storeId, array_keys($counts));
        if ($summary !== '') {
            $lines[] = '> ' . $summary;
            $lines[] = '';
        }
        foreach ($counts as $type => $count) {
            $lines[] = sprintf('- **%s**: %s items', ucfirst($type), number_format($count));
        }
        $lines[] = '';
        return $lines;
    }

    private function countByType(): array
    {
        try {
            $conn  = $this->resourceConnection->getConnection();
            $table = $conn->getTableName('catalog_product_entity');
            $select = $conn->select()
                ->from($table, ['type_id', new \Zend_Db_Expr('COUNT(*) AS cnt')])
                ->where('type_id IN (?)', self::DEFAULT_TYPES)
                ->group('type_id')
                ->order('cnt DESC');
            $rows = $conn->fetchAll($select);
        } catch (\Throwable $e) {
            $this->logger->info('[panth_llms_txt] product type query failed: ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $count = (int) ($row['cnt'] ?? 0);
            if ($count > 0) {
                $out[(string) $row['type_id']] = $count;
            }
        }
        return $out;
    }

    private function flag(string $path, int $storeId, bool $default): bool
    {
        $raw = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($raw === null || $raw === '') {
            return $default;
        }
        return (bool) (int) $raw;
    }
}
