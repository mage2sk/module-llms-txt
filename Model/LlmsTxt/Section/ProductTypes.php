<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt\Section;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Panth\LlmsTxt\Model\Summary\SummaryGenerator;

class ProductTypes implements SectionInterface
{
    public const XML_ENABLED = 'panth_llms_txt/product_types/enabled';

    private const DEFAULT_TYPES = ['simple', 'configurable', 'bundle', 'grouped', 'virtual', 'downloadable'];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SummaryGenerator $summaryGenerator
    ) {
    }

    public function render(int $storeId): array
    {
        if (!$this->flag(self::XML_ENABLED, $storeId, true)) {
            return [];
        }

        $counts = $this->summaryGenerator->countProductsByType($storeId, self::DEFAULT_TYPES);
        if ($counts === []) {
            return [];
        }

        $lines = ['## Product Types', ''];
        $summary = $this->summaryGenerator->generateProductTypeSummary($storeId, self::DEFAULT_TYPES);
        if ($summary !== '') {
            $lines[] = '> ' . $summary;
            $lines[] = '';
        }
        foreach ($counts as $type => $count) {
            $lines[] = sprintf(
                '- **%s**: %s %s',
                ucfirst((string) $type),
                number_format($count),
                $count === 1 ? 'item' : 'items'
            );
        }
        $lines[] = '';
        return $lines;
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
