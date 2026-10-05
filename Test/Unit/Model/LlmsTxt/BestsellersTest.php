<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\LlmsTxt;

use Magento\Framework\App\ResourceConnection;
use Panth\LlmsTxt\Model\LlmsTxt\Bestsellers;
use Panth\LlmsTxt\Test\Unit\Fixture\DbStubTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BestsellersTest extends TestCase
{
    use DbStubTrait;

    public function testNonPositiveLimitReturnsNothingWithoutQuerying(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');

        $this->assertSame([], (new Bestsellers($resource, $this->createStub(LoggerInterface::class)))->getProductIds(1, 0));
    }

    public function testAggregatedSalesAreUsedAndChildrenMappedToParents(): void
    {
        $connection = $this->connection(
            ['sales_bestsellers_aggregated_yearly', 'catalog_product_relation'],
            [],
            ['catalog_product_relation' => [
                ['child_id' => '11', 'parent_id' => '100'],
                ['child_id' => '11', 'parent_id' => '200'],
                ['child_id' => '12', 'parent_id' => '100'],
            ]],
            ['sales_bestsellers_aggregated_yearly' => ['11', '5', '12', '0']]
        );

        $ids = (new Bestsellers($this->resource($connection), $this->createStub(LoggerInterface::class)))
            ->getProductIds(1, 2);

        $this->assertSame([11, 100, 5, 12], $ids);
        $limits = [];
        foreach ($this->selectCalls as [$method, $args]) {
            if ($method === 'limit') {
                $limits[] = $args[0];
            }
        }
        $this->assertSame([10], $limits);
    }

    public function testOrderItemsAreUsedWhenAggregationIsMissingAndCancelledOrdersExcluded(): void
    {
        $connection = $this->connection(
            ['sales_order_item', 'sales_order'],
            [],
            [],
            ['sales_order_item' => ['8', '9']]
        );

        $ids = (new Bestsellers($this->resource($connection), $this->createStub(LoggerInterface::class)))
            ->getProductIds(3, 1);

        $this->assertSame([8, 9], $ids);
        $this->assertContains('o.state <> ?', $this->whereConditions());
        $this->assertContains('oi.parent_item_id IS NULL', $this->whereConditions());
    }

    public function testNoSalesDataIsLoggedAndReturnsEmpty(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')
            ->with('[panth_llms_txt] no sales data for store 4, best sellers section omitted');

        $this->assertSame([], (new Bestsellers($this->resource($this->connection()), $logger))->getProductIds(4, 5));
    }

    public function testQueryErrorsAreLoggedAndFallThrough(): void
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willThrowException(new \RuntimeException('no db'));
        $resource->method('getTableName')->willReturnArgument(0);
        $messages = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $this->assertSame([], (new Bestsellers($resource, $logger))->getProductIds(1, 1));
        $this->assertCount(3, $messages);
        $this->assertStringContainsString('aggregation query failed: no db', $messages[0]);
        $this->assertStringContainsString('order item query failed: no db', $messages[1]);
    }
}
