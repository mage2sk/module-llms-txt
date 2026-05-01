<?php
/**
 * Panth LLMs.txt — optional source-module integrations.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt\Section;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders three OPTIONAL Markdown sections — Testimonials, FAQs and
 * Forms — when their respective source modules (Panth_Testimonials,
 * Panth_Faq, Panth_DynamicForms) are installed on the host site.
 *
 * Each section is fully conditional on the source module's tables
 * being present at runtime — never references a class from the
 * source module, never throws when tables are missing. A merchant
 * can also disable any individual section via the
 * `panth_llms_txt/optional/include_*` admin toggles.
 *
 * The render() method returns one combined list of lines so the
 * Builder can splice it into the final document with a single
 * foreach. A blank result means "all three sections are empty for
 * this store" — drop it from the document silently.
 */
class OptionalIntegrations
{
    public const XML_INCLUDE_TESTIMONIALS  = 'panth_llms_txt/optional/include_testimonials';
    public const XML_INCLUDE_FAQS          = 'panth_llms_txt/optional/include_faqs';
    public const XML_INCLUDE_DYNAMIC_FORMS = 'panth_llms_txt/optional/include_dynamic_forms';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int $storeId
     * @return string[]
     */
    public function render(int $storeId): array
    {
        try {
            $store   = $this->storeManager->getStore($storeId);
            $baseUrl = rtrim((string) $store->getBaseUrl(), '/') . '/';
        } catch (\Throwable $e) {
            $this->logger->info('[panth_llms_txt] optional sections store load failed: ' . $e->getMessage());
            return [];
        }

        $lines = [];
        if ($this->flag(self::XML_INCLUDE_TESTIMONIALS, $storeId, true)) {
            foreach ($this->renderTestimonials($storeId, $baseUrl) as $l) {
                $lines[] = $l;
            }
        }
        if ($this->flag(self::XML_INCLUDE_FAQS, $storeId, true)) {
            foreach ($this->renderFaqs($storeId, $baseUrl) as $l) {
                $lines[] = $l;
            }
        }
        if ($this->flag(self::XML_INCLUDE_DYNAMIC_FORMS, $storeId, true)) {
            foreach ($this->renderForms($storeId, $baseUrl) as $l) {
                $lines[] = $l;
            }
        }
        return $lines;
    }

    /**
     * @return string[]
     */
    private function renderTestimonials(int $storeId, string $baseUrl): array
    {
        try {
            $conn = $this->resourceConnection->getConnection();
        } catch (\Throwable) {
            return [];
        }
        $itemTable = $this->resourceConnection->getTableName('panth_testimonial');
        $catTable  = $this->resourceConnection->getTableName('panth_testimonial_category');
        $hasItems  = $conn->isTableExists($itemTable);
        $hasCats   = $conn->isTableExists($catTable);
        if (!$hasItems && !$hasCats) {
            return [];
        }

        $base = trim((string) ($this->scopeConfig->getValue('panth_testimonials/general/base_url', ScopeInterface::SCOPE_STORE, $storeId)
            ?: 'testimonials'), '/') ?: 'testimonials';
        $listing = $baseUrl . $base;

        $items = [];
        if ($hasCats) {
            try {
                $cols = $conn->describeTable($catTable);
                $select = $conn->select()
                    ->from($catTable, ['url_key', 'name'])
                    ->where('is_active = ?', 1)
                    ->where('url_key IS NOT NULL')
                    ->where('url_key != ?', '');
                if (isset($cols['store_id'])) {
                    $select->where('store_id IN (?)', [0, $storeId]);
                }
                if (isset($cols['sort_order'])) {
                    $select->order('sort_order ASC');
                }
                foreach ($conn->fetchAll($select) as $row) {
                    $name = trim((string) ($row['name'] ?? ''));
                    $key  = trim((string) ($row['url_key'] ?? ''));
                    if ($name === '' || $key === '') {
                        continue;
                    }
                    $items[] = sprintf('- [%s](%s/category/%s) — category', $name, $listing, $key);
                }
            } catch (\Throwable $e) {
                $this->logger->info('[panth_llms_txt] testimonial categories failed: ' . $e->getMessage());
            }
        }
        if ($hasItems) {
            try {
                $cols = $conn->describeTable($itemTable);
                $columns = ['url_key', 'title'];
                if (isset($cols['short_content'])) {
                    $columns[] = 'short_content';
                }
                $select = $conn->select()
                    ->from($itemTable, $columns)
                    ->where('url_key IS NOT NULL')
                    ->where('url_key != ?', '');
                if (isset($cols['status'])) {
                    $select->where('status = ?', 1);
                }
                if (isset($cols['store_id'])) {
                    $select->where('store_id IN (?)', [0, $storeId]);
                }
                if (isset($cols['sort_order'])) {
                    $select->order('sort_order ASC');
                }
                $select->limit(100);
                foreach ($conn->fetchAll($select) as $row) {
                    $title = trim((string) ($row['title'] ?? ''));
                    $key   = trim((string) ($row['url_key'] ?? ''));
                    if ($title === '' || $key === '') {
                        continue;
                    }
                    $line = sprintf('- [%s](%s/%s)', $title, $listing, $key);
                    $excerpt = trim((string) ($row['short_content'] ?? ''));
                    if ($excerpt !== '') {
                        $line .= ': ' . $excerpt;
                    }
                    $items[] = $line;
                }
            } catch (\Throwable $e) {
                $this->logger->info('[panth_llms_txt] testimonial items failed: ' . $e->getMessage());
            }
        }
        if ($items === []) {
            return [];
        }

        return array_merge(
            ['## Testimonials', '', '> Customer testimonials and themed landing pages — useful when an AI assistant needs social proof for buying decisions.', ''],
            $items,
            ['']
        );
    }

    /**
     * @return string[]
     */
    private function renderFaqs(int $storeId, string $baseUrl): array
    {
        try {
            $conn = $this->resourceConnection->getConnection();
        } catch (\Throwable) {
            return [];
        }
        $itemTable     = $this->resourceConnection->getTableName('panth_faq_item');
        $itemStore     = $this->resourceConnection->getTableName('panth_faq_item_store');
        $categoryTable = $this->resourceConnection->getTableName('panth_faq_category');

        $hasItems      = $conn->isTableExists($itemTable);
        $hasCategories = $conn->isTableExists($categoryTable);
        if (!$hasItems && !$hasCategories) {
            return [];
        }
        $hasItemStore = $hasItems && $conn->isTableExists($itemStore);

        // Source module stores this under `panth_faq/general/faq_route`
        // (Helper\Data::XML_PATH_FAQ_ROUTE in module-faq).
        $base = trim((string) ($this->scopeConfig->getValue('panth_faq/general/faq_route', ScopeInterface::SCOPE_STORE, $storeId)
            ?: 'faq'), '/') ?: 'faq';
        $listing = $baseUrl . $base;

        $items = [];
        if ($hasCategories) {
            try {
                $select = $conn->select()
                    ->from($categoryTable, ['url_key', 'name'])
                    ->where('is_active = ?', 1)
                    ->where('url_key IS NOT NULL')
                    ->where('url_key != ?', '');
                foreach ($conn->fetchAll($select) as $row) {
                    $name = trim((string) ($row['name'] ?? ''));
                    $key  = trim((string) ($row['url_key'] ?? ''));
                    if ($name === '' || $key === '') {
                        continue;
                    }
                    $items[] = sprintf('- [%s](%s/category/%s) — category', $name, $listing, $key);
                }
            } catch (\Throwable $e) {
                $this->logger->info('[panth_llms_txt] faq categories failed: ' . $e->getMessage());
            }
        }
        if ($hasItems) {
            try {
                $cols = $conn->describeTable($itemTable);
                $select = $conn->select()
                    ->from(['i' => $itemTable], ['url_key', 'question'])
                    ->where('i.url_key IS NOT NULL')
                    ->where('i.url_key != ?', '');
                if (isset($cols['is_active'])) {
                    $select->where('i.is_active = ?', 1);
                }
                if ($hasItemStore) {
                    $select->join(
                        ['s' => $itemStore],
                        's.item_id = i.item_id AND s.store_id IN (0, ' . (int) $storeId . ')',
                        []
                    )->group('i.item_id');
                }
                $select->limit(200);
                foreach ($conn->fetchAll($select) as $row) {
                    $title = trim((string) ($row['question'] ?? ''));
                    $key   = trim((string) ($row['url_key'] ?? ''));
                    if ($title === '' || $key === '') {
                        continue;
                    }
                    $items[] = sprintf('- [%s](%s/item/%s)', $title, $listing, $key);
                }
            } catch (\Throwable $e) {
                $this->logger->info('[panth_llms_txt] faq items failed: ' . $e->getMessage());
            }
        }
        if ($items === []) {
            return [];
        }

        return array_merge(
            ['## FAQs', '', '> Merchant-authored questions and answers — direct grounding material for AI assistants.', ''],
            $items,
            ['']
        );
    }

    /**
     * @return string[]
     */
    private function renderForms(int $storeId, string $baseUrl): array
    {
        try {
            $conn = $this->resourceConnection->getConnection();
        } catch (\Throwable) {
            return [];
        }
        $table = $this->resourceConnection->getTableName('panth_dynamic_form');
        if (!$conn->isTableExists($table)) {
            return [];
        }

        $items = [];
        try {
            $cols = $conn->describeTable($table);
            $columns = array_values(array_intersect(['url_key', 'title', 'name', 'description'], array_keys($cols)));
            $select = $conn->select()
                ->from($table, $columns)
                ->where('url_key IS NOT NULL')
                ->where('url_key != ?', '');
            if (isset($cols['is_active'])) {
                $select->where('is_active = ?', 1);
            }
            if (isset($cols['form_type'])) {
                $select->where('form_type IN (?)', ['page', 'both']);
            }
            if (isset($cols['store_id'])) {
                $select->where('store_id IN (?)', [0, $storeId]);
            }
            $select->limit(100);
            foreach ($conn->fetchAll($select) as $row) {
                $key = trim((string) ($row['url_key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $title = trim((string) ($row['title'] ?? ''));
                if ($title === '') {
                    $title = trim((string) ($row['name'] ?? '')) ?: $key;
                }
                $line = sprintf('- [%s](%spages/%s)', $title, $baseUrl, $key);
                $description = trim((string) ($row['description'] ?? ''));
                if ($description !== '') {
                    $line .= ': ' . $description;
                }
                $items[] = $line;
            }
        } catch (\Throwable $e) {
            $this->logger->info('[panth_llms_txt] dynamic forms failed: ' . $e->getMessage());
        }
        if ($items === []) {
            return [];
        }

        return array_merge(
            ['## Forms', '', '> Public form pages — quotes, callbacks, custom enquiries — that crawlers should know exist.', ''],
            $items,
            ['']
        );
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
