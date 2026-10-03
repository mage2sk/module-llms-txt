<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\LlmsTxt\Section;

use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Model\LlmsTxt\Bestsellers;
use Panth\LlmsTxt\Model\LlmsTxt\Section\Products;
use Panth\LlmsTxt\Model\LlmsTxt\Section\ShortDescription;
use Panth\LlmsTxt\Test\Unit\Fixture\CatalogStubTrait;
use Panth\LlmsTxt\Test\Unit\Fixture\DbStubTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProductsTest extends TestCase
{
    use CatalogStubTrait;
    use DbStubTrait;

    private function products(
        ProductCollectionFactory $factory,
        array $config = [],
        array $bestsellerIds = [],
        ?LoggerInterface $logger = null
    ): Products {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(static fn (string $p) => $config[$p] ?? null);
        $visibility = $this->createStub(Visibility::class);
        $visibility->method('getVisibleInCatalogIds')->willReturn([2, 4]);
        $currency = $this->createStub(PriceCurrencyInterface::class);
        $currency->method('convertAndFormat')->willReturnCallback(
            static fn ($amount) => '$' . number_format((float) $amount, 2)
        );
        $bestsellers = $this->createStub(Bestsellers::class);
        $bestsellers->method('getProductIds')->willReturn($bestsellerIds);

        return new Products(
            $factory,
            $this->createStub(StoreManagerInterface::class),
            $scopeConfig,
            $this->resource($this->connection()),
            $visibility,
            new ShortDescription(),
            $currency,
            $logger ?? $this->createStub(LoggerInterface::class),
            $bestsellers
        );
    }

    public function testFeaturedProductsUseConfiguredAttributeAndRenderFullLines(): void
    {
        $collection = $this->productCollection([
            $this->product('Mug', 'https://s/mug.html', 'MUG-1', 12.5, 'Kitchen', ['short_description' => 'Stoneware mug']),
            $this->product('  '),
            $this->product('Plate'),
        ]);

        $lines = $this->products($this->productFactory($collection), [
            Products::XML_FEATURED_ATTRIBUTE => ' is_hot ',
            Products::XML_MAX_FEATURED       => '3',
        ])->renderFeatured(1);

        $this->assertSame([
            '## Featured Products',
            '',
            "- [Mug](https://s/mug.html) - \$12.50 - SKU MUG-1 - Kitchen\n  Stoneware mug",
            "- [Plate](#)\n  Plate - available from our catalog.",
            '',
        ], $lines);
        $this->assertContains(['is_hot', 1], $this->collectionCallsNamed('addAttributeToFilter', 2));
        $this->assertContains([3], $this->collectionCallsNamed('setPageSize'));
        $this->assertContains([[2, 4]], $this->collectionCallsNamed('setVisibility'));
    }

    public function testDescriptionsCanBeSwitchedOff(): void
    {
        $collection = $this->productCollection([$this->product('Mug', 'https://s/mug', '', 0.0)]);

        $lines = $this->products($this->productFactory($collection), [
            Products::XML_INCLUDE_DESCRIPTION => '0',
        ])->renderRecent(1);

        $this->assertSame(['## Recent Arrivals', '', '- [Mug](https://s/mug)', ''], $lines);
        $this->assertContains(['created_at', 'DESC'], $this->collectionCallsNamed('setOrder', 2));
    }

    public function testFeaturedDefaultsToIsFeaturedAndFailureIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with($this->stringContains('featured attribute unavailable'));
        $collection = $this->productCollection([], 'addAttributeToFilter');

        $this->assertSame([], $this->products($this->productFactory($collection), [], [], $logger)->renderFeatured(1));
    }

    public function testZeroLimitsAndDisabledFlagsSkipSections(): void
    {
        $factory = $this->createStub(ProductCollectionFactory::class);
        $products = $this->products($factory, [
            Products::XML_MAX_FEATURED     => '0',
            Products::XML_SHOW_BESTSELLERS => '0',
            Products::XML_SHOW_RECENT      => '0',
        ]);

        $this->assertSame([], $products->renderFeatured(1));
        $this->assertSame([], $products->renderBestsellers(1));
        $this->assertSame([], $products->renderRecent(1));

        $limits = $this->products($factory, [Products::XML_MAX_BESTSELLERS => '0', Products::XML_MAX_RECENT => '0']);
        $this->assertSame([], $limits->renderBestsellers(1));
        $this->assertSame([], $limits->renderRecent(1));
    }

    public function testBestsellersAreFilteredAndOrderedBySalesRank(): void
    {
        $collection = $this->productCollection([$this->product('Top', 'https://s/top')]);

        $lines = $this->products($this->productFactory($collection), [], [7, 3])->renderBestsellers(1);

        $this->assertSame('## Best Sellers', $lines[0]);
        $this->assertContains(['entity_id', ['in' => [7, 3]]], $this->collectionCallsNamed('addFieldToFilter', 2));
        $this->assertSame([['FIELD(e.entity_id, 7,3)']], $this->collectionCallsNamed('select.order'));
    }

    public function testBestsellersWithoutSalesDataRenderNothing(): void
    {
        $this->assertSame([], $this->products($this->createStub(ProductCollectionFactory::class))->renderBestsellers(1));
    }

    public function testCollectionErrorsAreLoggedAsWarnings(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('warning');
        $factory = $this->createStub(ProductCollectionFactory::class);
        $factory->method('create')->willThrowException(new \RuntimeException('down'));

        $products = $this->products($factory, [], [5], $logger);

        $this->assertSame([], $products->renderBestsellers(1));
        $this->assertSame([], $products->renderRecent(1));
    }

    public function testEmptyCollectionRendersNothing(): void
    {
        $collection = $this->productCollection([]);

        $this->assertSame([], $this->products($this->productFactory($collection))->renderRecent(1));
    }
}
