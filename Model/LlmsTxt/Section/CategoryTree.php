<?php
/**
 * Panth LLMs.txt — hierarchical category tree section.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt\Section;

use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders a deduplicated, hierarchically-nested category tree.
 *
 * The pre-v1.2 implementation emitted a flat "Top Categories" list which
 * produced confusing output like `- [Tops](...men-tops)` followed by
 * `- [Tops](...women-tops)` — an LLM reading those lines has no way to
 * tell them apart without parsing URLs. The nested Markdown tree makes
 * the relationship obvious:
 *
 *     - Men
 *       - [Tops](.../men/tops-men.html)
 *       - [Bottoms](.../men/bottoms-men.html)
 *     - Women
 *       - [Tops](.../women/tops-women.html)
 *       - [Bottoms](.../women/bottoms-women.html)
 *
 * Only active categories with a positive `include_in_menu` flag are
 * rendered — that's the same signal merchants already use to mark
 * "customer-facing". Depth is capped by `panth_llms_txt/llms_txt/max_category_depth`
 * (default 3) to prevent runaway output on catalogs with deep taxonomy.
 */
class CategoryTree implements SectionInterface
{
    public const XML_MAX_DEPTH = 'panth_llms_txt/llms_txt/max_category_depth';

    private const DEFAULT_MAX_DEPTH = 3;

    public function __construct(
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly CategoryFactory $categoryFactory,
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
        $store = null;
        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (\Throwable $e) {
            $this->logger->warning('[panth_llms_txt] category tree store load failed: ' . $e->getMessage());
            return [];
        }

        $rootId = (int) $store->getRootCategoryId();
        if ($rootId <= 0) {
            return [];
        }

        $maxDepth = (int) ($this->scopeConfig->getValue(
            self::XML_MAX_DEPTH,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: self::DEFAULT_MAX_DEPTH);
        $maxDepth = max(1, min(5, $maxDepth));

        try {
            // The root category lives at level 1 under Magento's canonical tree.
            // Children accessible via include_in_menu + is_active keep the list
            // aligned with what shoppers see in navigation.
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
            $this->logger->warning('[panth_llms_txt] category collection build failed: ' . $e->getMessage());
            return [];
        }

        // Build a parent_id → children[] index so we can walk the tree in one
        // pass rather than running N category URL lookups via the ORM.
        $byParent = [];
        foreach ($collection as $cat) {
            $byParent[(int) $cat->getParentId()][] = $cat;
        }

        $lines = [];
        $this->renderChildren($byParent, $rootId, 0, $lines);
        if ($lines === []) {
            return [];
        }

        array_unshift($lines, '## Category Tree', '');
        $lines[] = '';
        return $lines;
    }

    /**
     * Walk one subtree level, indenting each child's list item two spaces
     * per depth. Called recursively.
     *
     * @param array<int, \Magento\Catalog\Model\Category[]> $byParent
     * @param int                                           $parentId
     * @param int                                           $depth
     * @param string[]                                      $lines (by ref)
     */
    private function renderChildren(array $byParent, int $parentId, int $depth, array &$lines): void
    {
        if (!isset($byParent[$parentId])) {
            return;
        }

        $indent = str_repeat('  ', $depth);
        foreach ($byParent[$parentId] as $cat) {
            $name = trim((string) $cat->getName());
            if ($name === '') {
                continue;
            }
            $url = '';
            try {
                $url = (string) $cat->getUrl();
            } catch (\Throwable) {
                // fall through — emit the name without a link rather than skipping
            }

            $lines[] = $url !== ''
                ? sprintf('%s- [%s](%s)', $indent, $name, $url)
                : sprintf('%s- %s', $indent, $name);

            $this->renderChildren($byParent, (int) $cat->getId(), $depth + 1, $lines);
        }
    }
}
