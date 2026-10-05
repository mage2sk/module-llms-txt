<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\LlmsTxt\Cms;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\Data\PageSearchResultsInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Panth\LlmsTxt\Model\LlmsTxt\Cms\ActivePages;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ActivePagesTest extends TestCase
{
    private PageRepositoryInterface $repository;
    private LoggerInterface $logger;
    private SearchCriteriaBuilder $criteriaBuilder;
    private SortOrderBuilder $sortOrderBuilder;
    private array $sortedFields = [];
    private array $requestedPages = [];

    protected function setUp(): void
    {
        $this->repository = $this->createStub(PageRepositoryInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);

        $criteriaBuilder = $this->createStub(SearchCriteriaBuilder::class);
        $criteriaBuilder->method('addFilter')->willReturnSelf();
        $criteriaBuilder->method('addSortOrder')->willReturnCallback(
            function (SortOrder $sortOrder) use ($criteriaBuilder) {
                $this->sortedFields[] = $sortOrder->getField() . ':' . $sortOrder->getDirection();

                return $criteriaBuilder;
            }
        );
        $criteriaBuilder->method('setPageSize')->willReturnSelf();
        $criteriaBuilder->method('setCurrentPage')->willReturnCallback(
            function (int $page) use ($criteriaBuilder) {
                $this->requestedPages[] = $page;

                return $criteriaBuilder;
            }
        );
        $criteriaBuilder->method('create')->willReturn($this->createStub(SearchCriteria::class));

        $sortOrderBuilder = $this->createStub(SortOrderBuilder::class);
        $field = null;
        $sortOrderBuilder->method('setField')->willReturnCallback(
            function (string $name) use ($sortOrderBuilder, &$field) {
                $field = $name;

                return $sortOrderBuilder;
            }
        );
        $sortOrderBuilder->method('setDirection')->willReturnSelf();
        $sortOrderBuilder->method('create')->willReturnCallback(
            static function () use (&$field) {
                $sortOrder = new SortOrder();
                $sortOrder->setField((string) $field);
                $sortOrder->setDirection(SortOrder::SORT_DESC);

                return $sortOrder;
            }
        );

        $this->criteriaBuilder = $criteriaBuilder;
        $this->sortOrderBuilder = $sortOrderBuilder;
    }

    private function activePages(): ActivePages
    {
        return new ActivePages(
            $this->repository,
            $this->criteriaBuilder,
            $this->sortOrderBuilder,
            $this->logger
        );
    }

    private function page(string $identifier, string $title = 'Title'): PageInterface
    {
        $page = $this->createStub(PageInterface::class);
        $page->method('getIdentifier')->willReturn($identifier);
        $page->method('getTitle')->willReturn($title);

        return $page;
    }

    private function results(array $pages, int $total): PageSearchResultsInterface
    {
        $results = $this->createStub(PageSearchResultsInterface::class);
        $results->method('getItems')->willReturn($pages);
        $results->method('getTotalCount')->willReturn($total);

        return $results;
    }

    private function identifiers(array $pages): array
    {
        return array_map(static fn (PageInterface $page): string => $page->getIdentifier(), $pages);
    }

    public function testSortsByUpdateTimeThenPageIdDescending(): void
    {
        $this->repository->method('getList')->willReturn($this->results([$this->page('a')], 1));

        $this->activePages()->getNewestFirst(1, 5, [], 'llms.txt');

        $this->assertSame(['update_time:DESC', 'page_id:DESC'], $this->sortedFields);
    }

    public function testKeepsRepositoryOrderAndStopsAtTheLimit(): void
    {
        $pages = [$this->page('newest'), $this->page('middle'), $this->page('oldest')];
        $this->repository->method('getList')->willReturn($this->results($pages, 3));

        $selected = $this->activePages()->getNewestFirst(1, 2, [], 'llms.txt');

        $this->assertSame(['newest', 'middle'], $this->identifiers($selected));
    }

    public function testSkipsExcludedAndEmptyIdentifiersAndTitles(): void
    {
        $pages = [
            $this->page('no-route'),
            $this->page(''),
            $this->page('untitled', '   '),
            $this->page('keep-me'),
        ];
        $this->repository->method('getList')->willReturn($this->results($pages, 4));

        $selected = $this->activePages()->getNewestFirst(1, 10, ['no-route'], 'llms.txt');

        $this->assertSame(['keep-me'], $this->identifiers($selected));
    }

    public function testPagesUntilTheLimitIsFilledWhenABatchIsMostlyExcluded(): void
    {
        $batchSize = 2 + 1 + 10;
        $firstBatch = array_map(fn (int $i): PageInterface => $this->page('skip'), range(1, $batchSize));
        $secondBatch = [$this->page('wanted-1'), $this->page('wanted-2')];
        $this->repository->method('getList')->willReturnOnConsecutiveCalls(
            $this->results($firstBatch, 100),
            $this->results($secondBatch, 100)
        );

        $selected = $this->activePages()->getNewestFirst(1, 2, ['skip'], 'llms.txt');

        $this->assertSame(['wanted-1', 'wanted-2'], $this->identifiers($selected));
        $this->assertSame([1, 2], $this->requestedPages);
    }

    public function testStopsWhenTheRepositoryReturnsAPartialBatch(): void
    {
        $this->repository = $this->createMock(PageRepositoryInterface::class);
        $this->repository->expects($this->once())->method('getList')
            ->willReturn($this->results([$this->page('only')], 1));

        $selected = $this->activePages()->getNewestFirst(1, 50, [], 'llms.txt');

        $this->assertSame(['only'], $this->identifiers($selected));
    }

    public function testLogsAnInformationalNoticeWhenTheListIsTruncated(): void
    {
        $pages = [$this->page('a'), $this->page('b')];
        $this->repository->method('getList')->willReturn($this->results($pages, 310));
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('info')
            ->with($this->stringContains('key pages truncated (llms.txt): 310 active pages, showing the 2 most recently updated'));

        $this->activePages()->getNewestFirst(1, 2, [], 'llms.txt');
    }

    public function testDoesNotLogWhenEveryActivePageFits(): void
    {
        $this->repository->method('getList')->willReturn($this->results([$this->page('a')], 1));
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->never())->method('info');

        $this->activePages()->getNewestFirst(1, 10, [], 'llms.txt');
    }

    public function testReturnsAnEmptyListAndWarnsWhenTheRepositoryThrows(): void
    {
        $this->repository->method('getList')->willThrowException(new \RuntimeException('db down'));
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning')
            ->with($this->stringContains('key pages list failed: db down'));

        $this->assertSame([], $this->activePages()->getNewestFirst(1, 10, [], 'llms.txt'));
    }

    public function testTreatsAZeroOrNegativeLimitAsOne(): void
    {
        $this->repository->method('getList')->willReturn(
            $this->results([$this->page('a'), $this->page('b')], 2)
        );

        $this->assertCount(1, $this->activePages()->getNewestFirst(1, 0, [], 'llms.txt'));
    }
}
