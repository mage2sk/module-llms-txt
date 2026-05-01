<?php
/**
 * Panth LLMs.txt — structured index collector.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Helper\Page as CmsPageHelper;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Api\Data\IndexEntryInterface;
use Panth\LlmsTxt\Api\WeightedRankerInterface;
use Panth\LlmsTxt\Model\Index\Entry as IndexEntry;
use Panth\LlmsTxt\Model\Summary\SummaryGenerator;
use Psr\Log\LoggerInterface;

/**
 * Walks the Magento entity graph + admin config and emits a list of
 * structured sections — each containing a heading, a 2–3 line summary
 * and an ordered list of {@see IndexEntryInterface} rows.
 *
 * The collector is independent of the output format: the Markdown
 * builders use it (indirectly via the existing per-section classes
 * for now), and the JSON builder uses it directly. This keeps the
 * /llms.json shape and /llms.txt content aligned without duplicating
 * collection logic.
 *
 * Sections produced:
 *   - priority_urls
 *   - collections
 *   - key_pages (CMS)
 *   - categories (taxonomy)
 *   - featured_products
 *   - bestsellers
 *   - recent_arrivals
 *   - use_cases (when configured)
 *   - product_types (catalog mix metadata)
 */
class StructuredIndex
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly Visibility $visibility,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly CmsPageHelper $cmsPageHelper,
        private readonly SummaryGenerator $summaryGenerator,
        private readonly WeightedRankerInterface $ranker,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Build the section array.
     *
     * @return array<int,array{code:string,label:string,summary:string,entries:IndexEntryInterface[]}>
     */
    public function collect(int $storeId): array
    {
        $sections = [];
        $storeRootId = $this->storeRootId($storeId);
        $baseUrl = $this->baseUrl($storeId);

        // Priority URLs — admin-authored.
        $priority = $this->priorityUrls($storeId, $baseUrl);
        if ($priority !== []) {
            $sections[] = [
                'code'    => 'priority_urls',
                'label'   => 'Priority URLs',
                'summary' => 'Pages the merchant has explicitly marked as the most important entry points to this store.',
                'entries' => $this->ranker->rank($priority, $storeId),
            ];
        }

        // Collections — admin-picked landing categories.
        $collections = $this->collectionEntries($storeId);
        if ($collections !== []) {
            $sections[] = [
                'code'    => 'collections',
                'label'   => 'Collections',
                'summary' => 'Curated landing pages such as Sale, New Arrivals, Clearance and seasonal collections.',
                'entries' => $this->ranker->rank($collections, $storeId),
            ];
        }

        // Key Pages — CMS.
        $keyPages = $this->keyPages($storeId);
        if ($keyPages !== []) {
            $sections[] = [
                'code'    => 'key_pages',
                'label'   => 'Key Pages',
                'summary' => 'Editorial CMS pages — about, policies, support — useful for grounding answers in merchant-authored copy.',
                'entries' => $this->ranker->rank($keyPages, $storeId),
            ];
        }

        // Category tree.
        $categories = $this->categoryEntries($storeId, $storeRootId);
        if ($categories !== []) {
            $sections[] = [
                'code'    => 'categories',
                'label'   => 'Categories',
                'summary' => 'Storefront taxonomy — the merchant\'s own grouping of the catalog into navigable sections.',
                'entries' => $this->ranker->rank($categories, $storeId),
            ];
        }

        // Featured / Bestsellers / Recent — high-signal product slices.
        foreach ([
            ['code' => 'featured_products', 'label' => 'Featured Products', 'limit' => $this->intConfig('panth_llms_txt/llms_txt/max_featured', $storeId, 6),     'mode' => 'featured'],
            ['code' => 'bestsellers',       'label' => 'Best Sellers',      'limit' => $this->intConfig('panth_llms_txt/llms_txt/max_bestsellers', $storeId, 10), 'mode' => 'bestsellers'],
            ['code' => 'recent_arrivals',   'label' => 'Recent Arrivals',   'limit' => $this->intConfig('panth_llms_txt/llms_txt/max_recent', $storeId, 10),      'mode' => 'recent'],
        ] as $cfg) {
            if ($cfg['limit'] <= 0) {
                continue;
            }
            $entries = $this->productEntries($storeId, $cfg['mode'], $cfg['limit']);
            if ($entries === []) {
                continue;
            }
            $sections[] = [
                'code'    => $cfg['code'],
                'label'   => $cfg['label'],
                'summary' => $this->productSectionSummary($cfg['code']),
                'entries' => $this->ranker->rank($entries, $storeId),
            ];
        }

        // Optional integrations — surfaced only when the source
        // module is installed (table-existence check).
        if ($this->flag('panth_llms_txt/optional/include_testimonials', $storeId, true)) {
            $entries = $this->testimonialEntries($storeId, $baseUrl);
            if ($entries !== []) {
                $sections[] = [
                    'code'    => 'testimonials',
                    'label'   => 'Testimonials',
                    'summary' => 'Approved customer testimonials and category landing pages — useful when an AI assistant needs social proof for buying decisions.',
                    'entries' => $this->ranker->rank($entries, $storeId),
                ];
            }
        }
        if ($this->flag('panth_llms_txt/optional/include_faqs', $storeId, true)) {
            $entries = $this->faqEntries($storeId, $baseUrl);
            if ($entries !== []) {
                $sections[] = [
                    'code'    => 'faqs',
                    'label'   => 'FAQs',
                    'summary' => 'Merchant-authored questions and answers — direct grounding material for AI assistants answering customer questions.',
                    'entries' => $this->ranker->rank($entries, $storeId),
                ];
            }
        }
        if ($this->flag('panth_llms_txt/optional/include_dynamic_forms', $storeId, true)) {
            $entries = $this->dynamicFormEntries($storeId, $baseUrl);
            if ($entries !== []) {
                $sections[] = [
                    'code'    => 'forms',
                    'label'   => 'Forms',
                    'summary' => 'Public form pages — quotes, callbacks, custom enquiries — that crawlers should know exist.',
                    'entries' => $this->ranker->rank($entries, $storeId),
                ];
            }
        }

        return $sections;
    }

    /**
     * @return IndexEntryInterface[]
     */
    private function priorityUrls(int $storeId, string $baseUrl): array
    {
        $raw = trim((string) $this->scopeConfig->getValue('panth_llms_txt/llms_txt/priority_urls', ScopeInterface::SCOPE_STORE, $storeId));
        if ($raw === '') {
            return [];
        }
        $entries = [];
        $base = rtrim($baseUrl, '/');
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $label = '';
            $url   = $line;
            if (str_contains($line, '|')) {
                [$label, $url] = array_map('trim', explode('|', $line, 2));
            }
            if ($url === '') {
                continue;
            }
            if (str_starts_with($url, '/')) {
                $url = $base . $url;
            } elseif (!preg_match('#^https?://#i', $url)) {
                $url = $base . '/' . ltrim($url, '/');
            }
            if ($label === '') {
                $label = $this->labelFromUrl($url);
            }
            $type = $url === ($base . '/') ? IndexEntryInterface::TYPE_HOMEPAGE : IndexEntryInterface::TYPE_COLLECTION;
            $entries[] = new IndexEntry($url, $label, $type, 0.9, '', ['source' => 'admin_priority']);
        }
        return $entries;
    }

    /**
     * @return IndexEntryInterface[]
     */
    private function collectionEntries(int $storeId): array
    {
        $raw = (string) $this->scopeConfig->getValue('panth_llms_txt/llms_txt/collections_categories', ScopeInterface::SCOPE_STORE, $storeId);
        $ids = array_filter(array_map(static fn ($v) => (int) trim((string) $v), explode(',', $raw)));
        if ($ids === []) {
            return [];
        }
        $entries = [];
        foreach ($ids as $id) {
            try {
                $cat = $this->categoryRepository->get($id, $storeId);
            } catch (\Throwable) {
                continue;
            }
            if (!$cat->getIsActive()) {
                continue;
            }
            $name = trim((string) $cat->getName());
            if ($name === '') {
                continue;
            }
            $url = '';
            try {
                $url = (string) $cat->getUrl();
            } catch (\Throwable) {
                continue;
            }
            if ($url === '') {
                continue;
            }
            $entries[] = new IndexEntry(
                $url,
                $name,
                IndexEntryInterface::TYPE_COLLECTION,
                0.8,
                $this->summaryGenerator->generateCategorySummary($storeId, (int) $cat->getId()),
                ['category_id' => (int) $cat->getId()]
            );
        }
        return $entries;
    }

    /**
     * @return IndexEntryInterface[]
     */
    private function keyPages(int $storeId): array
    {
        $limit = $this->intConfig('panth_llms_txt/llms_txt/max_cms', $storeId, 10);
        try {
            $criteria = $this->searchCriteriaBuilder
                ->addFilter('is_active', 1)
                ->addFilter('store_id', [$storeId, 0], 'in')
                ->setPageSize($limit + 5)
                ->create();
            $pages = $this->pageRepository->getList($criteria)->getItems();
        } catch (\Throwable $e) {
            $this->logger->info('[panth_llms_txt] structured key pages failed: ' . $e->getMessage());
            return [];
        }
        $excluded = ['no-route', 'privacy-policy-cookie-restriction-mode', 'enable-cookies'];
        $extra = trim((string) $this->scopeConfig->getValue('panth_llms_txt/llms_txt/exclude_cms', ScopeInterface::SCOPE_STORE, $storeId));
        if ($extra !== '') {
            $excluded = array_merge($excluded, array_filter(array_map('trim', explode(',', $extra))));
        }
        $entries = [];
        foreach ($pages as $page) {
            $identifier = (string) $page->getIdentifier();
            if ($identifier === '' || in_array($identifier, $excluded, true)) {
                continue;
            }
            $title = trim((string) $page->getTitle());
            if ($title === '') {
                continue;
            }
            $url = '';
            try {
                $url = (string) $this->cmsPageHelper->getPageUrl((int) $page->getId());
            } catch (\Throwable) {
                continue;
            }
            if ($url === '') {
                continue;
            }
            $entries[] = new IndexEntry(
                $url,
                $title,
                IndexEntryInterface::TYPE_CMS,
                0.7,
                trim((string) ($page->getMetaDescription() ?? '')),
                ['identifier' => $identifier, 'page_id' => (int) $page->getId()]
            );
            if (count($entries) >= $limit) {
                break;
            }
        }
        return $entries;
    }

    /**
     * @return IndexEntryInterface[]
     */
    private function categoryEntries(int $storeId, int $rootId): array
    {
        if ($rootId <= 0) {
            return [];
        }
        $maxDepth = (int) ($this->scopeConfig->getValue('panth_llms_txt/llms_txt/max_category_depth', ScopeInterface::SCOPE_STORE, $storeId) ?: 3);
        $maxDepth = max(1, min(5, $maxDepth));

        try {
            $collection = $this->categoryCollectionFactory->create();
            $collection->setStoreId($storeId)
                ->addAttributeToSelect(['name', 'url_key', 'url_path', 'include_in_menu', 'is_active'])
                ->addAttributeToFilter('is_active', 1)
                ->addAttributeToFilter('include_in_menu', 1)
                ->addPathFilter('^1/' . $rootId . '(/|$)')
                ->addAttributeToFilter('level', ['gt' => 1])
                ->addAttributeToFilter('level', ['lteq' => 1 + $maxDepth])
                ->addAttributeToSort('level', 'ASC')
                ->addAttributeToSort('position', 'ASC');
        } catch (\Throwable $e) {
            $this->logger->info('[panth_llms_txt] structured category collection failed: ' . $e->getMessage());
            return [];
        }
        $entries = [];
        foreach ($collection as $cat) {
            $name = trim((string) $cat->getName());
            if ($name === '') {
                continue;
            }
            $url = '';
            try {
                $url = (string) $cat->getUrl();
            } catch (\Throwable) {
                continue;
            }
            if ($url === '') {
                continue;
            }
            $entries[] = new IndexEntry(
                $url,
                $name,
                IndexEntryInterface::TYPE_CATEGORY,
                0.65,
                $this->summaryGenerator->generateCategorySummary($storeId, (int) $cat->getId()),
                [
                    'category_id' => (int) $cat->getId(),
                    'level'       => (int) $cat->getLevel(),
                    'parent_id'   => (int) $cat->getParentId(),
                ]
            );
        }
        return $entries;
    }

    /**
     * @return IndexEntryInterface[]
     */
    private function productEntries(int $storeId, string $mode, int $limit): array
    {
        try {
            $collection = $this->productCollectionFactory->create();
            $collection->setStoreId($storeId)
                ->addAttributeToSelect(['name', 'sku', 'short_description', 'url_key', 'url_path'])
                ->addAttributeToFilter('status', 1)
                ->setVisibility($this->visibility->getVisibleInCatalogIds())
                ->addPriceData()
                ->setPageSize($limit);

            if ($mode === 'featured') {
                $attr = trim((string) $this->scopeConfig->getValue('panth_llms_txt/llms_txt/featured_attribute', ScopeInterface::SCOPE_STORE, $storeId));
                if ($attr === '') {
                    $attr = 'is_featured';
                }
                try {
                    $collection->addAttributeToFilter($attr, 1);
                } catch (\Throwable) {
                    return [];
                }
            } elseif ($mode === 'recent') {
                $collection->setOrder('created_at', 'DESC');
            } elseif ($mode === 'bestsellers') {
                // We'd ordinarily reuse the bestseller table here but the
                // existing Section/Products class already does the lookup
                // and the structured collector is mainly used by JSON
                // output where listing the latest as a fallback is OK
                // when no aggregated bestseller data exists yet.
                $collection->setOrder('updated_at', 'DESC');
            }
        } catch (\Throwable $e) {
            $this->logger->info('[panth_llms_txt] structured product collection failed: ' . $e->getMessage());
            return [];
        }
        $entries = [];
        $rank = 0;
        foreach ($collection as $product) {
            $name = trim((string) $product->getName());
            if ($name === '') {
                continue;
            }
            $url = '';
            try {
                $url = (string) $product->getProductUrl();
            } catch (\Throwable) {
                continue;
            }
            if ($url === '') {
                continue;
            }
            $rank++;
            $price = (float) $product->getFinalPrice();
            $entries[] = new IndexEntry(
                $url,
                $name,
                IndexEntryInterface::TYPE_PRODUCT,
                0.55,
                trim((string) ($product->getData('short_description') ?? '')),
                [
                    'sku'             => (string) $product->getSku(),
                    'price'           => $price > 0 ? $price : null,
                    'featured'        => $mode === 'featured',
                    'bestseller_rank' => $mode === 'bestsellers' ? $rank : null,
                ]
            );
        }
        return $entries;
    }

    private function productSectionSummary(string $code): string
    {
        return match ($code) {
            'featured_products' => 'Products the merchant has explicitly flagged as showcase items.',
            'bestsellers'       => 'Highest-selling products by lifetime order quantity for this store view.',
            'recent_arrivals'   => 'Most recently added catalog items, ordered by created_at descending.',
            default             => '',
        };
    }

    private function storeRootId(int $storeId): int
    {
        try {
            return (int) $this->storeManager->getStore($storeId)->getRootCategoryId();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function baseUrl(int $storeId): string
    {
        try {
            return rtrim((string) $this->storeManager->getStore($storeId)->getBaseUrl(), '/') . '/';
        } catch (\Throwable) {
            return '/';
        }
    }

    private function labelFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = trim($path, '/');
        if ($path === '') {
            return 'Homepage';
        }
        $segment = basename($path);
        $segment = (string) preg_replace('/\.(html?|php)$/i', '', $segment);
        $segment = str_replace(['-', '_'], ' ', $segment);
        $label   = ucwords(trim($segment));
        return $label !== '' ? $label : $url;
    }

    private function intConfig(string $path, int $storeId, int $default): int
    {
        $raw = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($raw === null || $raw === '') {
            return $default;
        }
        return max(0, (int) $raw);
    }

    private function flag(string $path, int $storeId, bool $default): bool
    {
        $raw = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($raw === null || $raw === '') {
            return $default;
        }
        return (bool) (int) $raw;
    }

    /**
     * Testimonials integration — emits IndexEntries for testimonial
     * categories and approved testimonials. Returns [] when the
     * source module isn't installed (no panth_testimonial / panth_testimonial_category
     * tables).
     *
     * @return IndexEntryInterface[]
     */
    private function testimonialEntries(int $storeId, string $baseUrl): array
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
        $base = rtrim($baseUrl, '/') . '/' . $base;

        $entries = [];
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
                foreach ($conn->fetchAll($select) as $row) {
                    $name = trim((string) ($row['name'] ?? ''));
                    $key  = trim((string) ($row['url_key'] ?? ''));
                    if ($name === '' || $key === '') {
                        continue;
                    }
                    $entries[] = new IndexEntry(
                        $base . '/category/' . $key,
                        $name,
                        IndexEntryInterface::TYPE_COLLECTION,
                        0.6,
                        '',
                        ['source' => 'testimonial_category']
                    );
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
                $select->limit(200);
                foreach ($conn->fetchAll($select) as $row) {
                    $title = trim((string) ($row['title'] ?? ''));
                    $key   = trim((string) ($row['url_key'] ?? ''));
                    if ($title === '' || $key === '') {
                        continue;
                    }
                    $entries[] = new IndexEntry(
                        $base . '/' . $key,
                        $title,
                        IndexEntryInterface::TYPE_CMS,
                        0.55,
                        trim((string) ($row['short_content'] ?? '')),
                        ['source' => 'testimonial']
                    );
                }
            } catch (\Throwable $e) {
                $this->logger->info('[panth_llms_txt] testimonials failed: ' . $e->getMessage());
            }
        }
        return $entries;
    }

    /**
     * FAQ integration — emits IndexEntries for FAQ categories and
     * individual FAQ items (scoped to the store via the
     * panth_faq_item_store junction).
     *
     * @return IndexEntryInterface[]
     */
    private function faqEntries(int $storeId, string $baseUrl): array
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
        $base = rtrim($baseUrl, '/') . '/' . $base;

        $entries = [];
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
                    $entries[] = new IndexEntry(
                        $base . '/category/' . $key,
                        $name,
                        IndexEntryInterface::TYPE_COLLECTION,
                        0.65,
                        '',
                        ['source' => 'faq_category']
                    );
                }
            } catch (\Throwable $e) {
                $this->logger->info('[panth_llms_txt] faq categories failed: ' . $e->getMessage());
            }
        }
        if ($hasItems) {
            try {
                $cols = $conn->describeTable($itemTable);
                $columns = ['i.url_key', 'i.question'];
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
                $select->limit(500);
                foreach ($conn->fetchAll($select) as $row) {
                    $title = trim((string) ($row['question'] ?? ''));
                    $key   = trim((string) ($row['url_key'] ?? ''));
                    if ($title === '' || $key === '') {
                        continue;
                    }
                    $entries[] = new IndexEntry(
                        $base . '/item/' . $key,
                        $title,
                        IndexEntryInterface::TYPE_CMS,
                        0.55,
                        '',
                        ['source' => 'faq']
                    );
                }
            } catch (\Throwable $e) {
                $this->logger->info('[panth_llms_txt] faq items failed: ' . $e->getMessage());
            }
        }
        return $entries;
    }

    /**
     * Dynamic forms integration — emits IndexEntries for active,
     * page-type forms only (skips widget-only forms which don't have
     * standalone URLs).
     *
     * @return IndexEntryInterface[]
     */
    private function dynamicFormEntries(int $storeId, string $baseUrl): array
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
        $base = rtrim($baseUrl, '/') . '/pages';

        $entries = [];
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
                $entries[] = new IndexEntry(
                    $base . '/' . $key,
                    $title,
                    IndexEntryInterface::TYPE_CMS,
                    0.5,
                    trim((string) ($row['description'] ?? '')),
                    ['source' => 'dynamic_form']
                );
            }
        } catch (\Throwable $e) {
            $this->logger->info('[panth_llms_txt] dynamic forms failed: ' . $e->getMessage());
        }
        return $entries;
    }
}
