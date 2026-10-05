<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt\Cms;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Psr\Log\LoggerInterface;

class ActivePages
{
    private const MAX_BATCH_SIZE = 500;
    private const MAX_SCANNED = 5000;
    private const OVERFETCH = 10;

    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getNewestFirst(int $storeId, int $limit, array $excludedIdentifiers, string $context): array
    {
        $limit = max(1, $limit);
        $excluded = array_flip($excludedIdentifiers);
        $batchSize = min($limit + count($excludedIdentifiers) + self::OVERFETCH, self::MAX_BATCH_SIZE);

        $selected = [];
        $scanned = 0;
        $currentPage = 1;
        $totalActive = null;

        while (count($selected) < $limit && $scanned < self::MAX_SCANNED) {
            $result = $this->loadBatch($storeId, $currentPage, $batchSize);
            if ($result === null) {
                return $selected;
            }

            $pages = $result->getItems();
            if ($pages === []) {
                break;
            }
            if ($totalActive === null) {
                $totalActive = (int) $result->getTotalCount();
            }

            foreach ($pages as $page) {
                $scanned++;
                if (!$this->isEligible($page, $excluded)) {
                    continue;
                }
                $selected[] = $page;
                if (count($selected) >= $limit) {
                    break;
                }
            }

            if (count($pages) < $batchSize) {
                break;
            }
            $currentPage++;
        }

        $this->reportTruncation($context, $totalActive, count($selected), $limit);

        return $selected;
    }

    private function loadBatch(int $storeId, int $currentPage, int $batchSize)
    {
        try {
            $criteria = $this->searchCriteriaBuilder
                ->addFilter('is_active', 1)
                ->addFilter('store_id', [$storeId, 0], 'in')
                ->addSortOrder($this->sortOrder('update_time'))
                ->addSortOrder($this->sortOrder('page_id'))
                ->setPageSize($batchSize)
                ->setCurrentPage($currentPage)
                ->create();

            return $this->pageRepository->getList($criteria);
        } catch (\Throwable $e) {
            $this->logger->warning('[panth_llms_txt] key pages list failed: ' . $e->getMessage());

            return null;
        }
    }

    private function sortOrder(string $field): SortOrder
    {
        return $this->sortOrderBuilder
            ->setField($field)
            ->setDirection(SortOrder::SORT_DESC)
            ->create();
    }

    private function isEligible(PageInterface $page, array $excluded): bool
    {
        $identifier = (string) $page->getIdentifier();
        if ($identifier === '' || isset($excluded[$identifier])) {
            return false;
        }

        return trim((string) $page->getTitle()) !== '';
    }

    private function reportTruncation(string $context, ?int $totalActive, int $selected, int $limit): void
    {
        if ($totalActive === null || $selected < $limit || $totalActive <= $limit) {
            return;
        }

        $this->logger->info(sprintf(
            '[panth_llms_txt] key pages truncated (%s): %d active pages, showing the %d most recently updated (raise panth_llms_txt/llms_txt/max_cms to include more)',
            $context,
            $totalActive,
            $limit
        ));
    }
}
