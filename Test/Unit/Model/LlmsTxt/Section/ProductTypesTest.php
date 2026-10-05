<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\LlmsTxt\Section;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Panth\LlmsTxt\Model\LlmsTxt\Section\ProductTypes;
use Panth\LlmsTxt\Model\Summary\SummaryGenerator;
use PHPUnit\Framework\TestCase;

class ProductTypesTest extends TestCase
{
    private function section(array $counts, ?string $enabled = null, string $summary = ''): ProductTypes
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($enabled);
        $generator = $this->createStub(SummaryGenerator::class);
        $generator->method('countProductsByType')->willReturn($counts);
        $generator->method('generateProductTypeSummary')->willReturn($summary);
        return new ProductTypes($scopeConfig, $generator);
    }

    public function testCountsAreListedWithSummary(): void
    {
        $lines = $this->section(['simple' => 1200, 'configurable' => 7], null, 'Catalog mix: lots.')->render(1);

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

    public function testSingleItemUsesSingularNoun(): void
    {
        $this->assertSame(
            ['## Product Types', '', '- **Bundle**: 1 item', ''],
            $this->section(['bundle' => 1])->render(1)
        );
    }

    public function testCountsAreRequestedForTheRenderedStore(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $generator = $this->createMock(SummaryGenerator::class);
        $generator->expects($this->once())
            ->method('countProductsByType')
            ->with(3, $this->containsEqual('configurable'))
            ->willReturn(['virtual' => 2]);
        $generator->method('generateProductTypeSummary')->willReturn('');

        $this->assertSame(
            ['## Product Types', '', '- **Virtual**: 2 items', ''],
            (new ProductTypes($scopeConfig, $generator))->render(3)
        );
    }

    public function testDisabledOrEmptyCatalogRendersNothing(): void
    {
        $this->assertSame([], $this->section(['simple' => 3], '0')->render(1));
        $this->assertSame([], $this->section([])->render(1));
    }
}
