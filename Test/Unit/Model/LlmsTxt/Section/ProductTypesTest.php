<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\LlmsTxt\Section;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Panth\LlmsTxt\Model\LlmsTxt\Section\ProductTypes;
use Panth\LlmsTxt\Model\Summary\SummaryGenerator;
use Panth\LlmsTxt\Test\Unit\Fixture\DbStubTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProductTypesTest extends TestCase
{
    use DbStubTrait;

    private function section(
        ResourceConnection $resource,
        ?string $enabled = null,
        string $summary = '',
        ?LoggerInterface $logger = null
    ): ProductTypes {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($enabled);
        $generator = $this->createStub(SummaryGenerator::class);
        $generator->method('generateProductTypeSummary')->willReturn($summary);
        return new ProductTypes($resource, $scopeConfig, $generator, $logger ?? $this->createStub(LoggerInterface::class));
    }

    public function testCountsAreListedWithSummary(): void
    {
        $connection = $this->connection([], [], ['catalog_product_entity' => [
            ['type_id' => 'simple', 'cnt' => '1200'],
            ['type_id' => 'bundle', 'cnt' => '0'],
            ['type_id' => 'configurable', 'cnt' => '7'],
        ]]);

        $lines = $this->section($this->resource($connection), null, 'Catalog mix: lots.')->render(1);

        $this->assertSame([
            '## Product Types',
            '',
            '> Catalog mix: lots.',
            '',
            '- **Simple**: 1,200 items',
            '- **Configurable**: 7 items',
            '',
        ], $lines);
    }

    public function testSummaryLineIsOmittedWhenGeneratorReturnsNothing(): void
    {
        $connection = $this->connection([], [], ['catalog_product_entity' => [['type_id' => 'virtual', 'cnt' => 2]]]);

        $this->assertSame(
            ['## Product Types', '', '- **Virtual**: 2 items', ''],
            $this->section($this->resource($connection))->render(1)
        );
    }

    public function testDisabledOrEmptyCatalogRendersNothing(): void
    {
        $connection = $this->connection([], [], ['catalog_product_entity' => [['type_id' => 'simple', 'cnt' => 3]]]);

        $this->assertSame([], $this->section($this->resource($connection), '0')->render(1));
        $this->assertSame([], $this->section($this->resource($this->connection()))->render(1));
    }

    public function testQueryFailureIsLoggedAndRendersNothing(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with($this->stringContains('product type query failed: sql'));
        $connection = $this->connection([], [], [], [], new \RuntimeException('sql'));

        $this->assertSame([], $this->section($this->resource($connection), '1', '', $logger)->render(1));
    }
}
