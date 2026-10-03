<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\LlmsTxt\Section;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Model\LlmsTxt\Section\OptionalIntegrations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OptionalIntegrationsTest extends TestCase
{
    public function testTestimonialsWithoutApprovalColumnAreNotListed(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturnCallback(
            static fn (string $table): bool => $table === 'panth_testimonial'
        );
        $connection->method('describeTable')->willReturn([
            'testimonial_id' => [],
            'url_key' => [],
            'title' => [],
            'store_id' => [],
        ]);
        $connection->expects($this->never())->method('fetchAll');

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.example/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $section = new OptionalIntegrations(
            $resource,
            $storeManager,
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame([], $section->render(1));
    }
}
