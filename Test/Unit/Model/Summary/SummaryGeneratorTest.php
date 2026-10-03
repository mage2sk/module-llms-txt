<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\Summary;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Directory\Model\Currency;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Model\Summary\SummaryGenerator;
use Panth\LlmsTxt\Test\Unit\Fixture\DbStubTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SummaryGeneratorTest extends TestCase
{
    use DbStubTrait;

    private function store(string $name = 'Main', string $currency = 'USD', int $rootId = 2, float $rate = 1.0): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getName')->willReturn($name);
        $store->method('getCurrentCurrencyCode')->willReturn($currency);
        $store->method('getRootCategoryId')->willReturn($rootId);
        $base = $this->createStub(Currency::class);
        $base->method('convert')->willReturnCallback(static fn ($price) => $price * $rate);
        $store->method('getBaseCurrency')->willReturn($base);
        return $store;
    }

    private function sizeCollection(int $size, ?array $priceRow = null): ProductCollection
    {
        $collection = $this->createStub(ProductCollection::class);
        foreach (['setStoreId', 'addStoreFilter', 'addAttributeToFilter', 'addCategoriesFilter', 'addPriceData'] as $m) {
            $collection->method($m)->willReturnSelf();
        }
        $collection->method('getSize')->willReturn($size);
        $collection->method('getSelect')->willReturn($this->fluentSelect());
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchRow')->willReturn($priceRow ?? ['min_price' => null, 'max_price' => null]);
        $collection->method('getConnection')->willReturn($connection);
        return $collection;
    }

    private function generator(
        ?Store $store,
        ?ProductCollection $collection,
        ?AdapterInterface $connection = null,
        ?CategoryRepositoryInterface $categories = null,
        array $config = []
    ): SummaryGenerator {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        if ($store === null) {
            $storeManager->method('getStore')->willThrowException(new \RuntimeException('no store'));
        } else {
            $storeManager->method('getStore')->willReturn($store);
        }
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(static fn (string $p) => $config[$p] ?? null);
        $factory = $this->createStub(ProductCollectionFactory::class);
        if ($collection === null) {
            $factory->method('create')->willThrowException(new \RuntimeException('no collection'));
        } else {
            $factory->method('create')->willReturn($collection);
        }
        return new SummaryGenerator(
            $storeManager,
            $scopeConfig,
            $this->resource($connection ?? $this->connection()),
            $categories ?? $this->activeCategories(),
            $factory,
            $this->createStub(LoggerInterface::class)
        );
    }

    private function activeCategories(array $inactive = []): CategoryRepositoryInterface
    {
        $repo = $this->createStub(CategoryRepositoryInterface::class);
        $repo->method('get')->willReturnCallback(function (int $id) use ($inactive) {
            $cat = $this->createStub(Category::class);
            $cat->method('getIsActive')->willReturn(!in_array($id, $inactive, true));
            $cat->method('getName')->willReturn('Cat ' . $id);
            return $cat;
        });
        return $repo;
    }

    private function categoryConnection(array $rows, int $attrId = 45): AdapterInterface
    {
        $connection = $this->connection([], [], ['catalog_category_entity' => $rows]);
        $connection->method('fetchOne')->willReturn((string) $attrId);
        return $connection;
    }

    public function testStoreSummaryCombinesBrandCountCategoriesAndCurrency(): void
    {
        $connection = $this->categoryConnection([
            ['entity_id' => 3, 'name' => 'Men'],
            ['entity_id' => 4, 'name' => 'Men'],
            ['entity_id' => 5, 'name' => 'Hidden'],
            ['entity_id' => 6, 'name' => ''],
            ['entity_id' => 7, 'name' => 'Women'],
            ['entity_id' => 8, 'name' => 'Kids'],
            ['entity_id' => 9, 'name' => 'Extra'],
        ]);

        $summary = $this->generator(
            $this->store(),
            $this->sizeCollection(12345),
            $connection,
            $this->activeCategories([5]),
            ['general/store_information/name' => 'Acme']
        )->generateStoreSummary(1);

        $this->assertSame('Acme offers 12,345 products across categories such as Men, Women and Kids (priced in USD).', $summary);
    }

    public function testStoreSummaryFallsBackToStoreNameAndHandlesTwoCategories(): void
    {
        $connection = $this->categoryConnection([['entity_id' => 3, 'name' => 'A'], ['entity_id' => 4, 'name' => 'B']]);

        $summary = $this->generator($this->store('Shop View', ''), $this->sizeCollection(0), $connection)
            ->generateStoreSummary(1);

        $this->assertSame('Shop View across categories such as A and B.', $summary);
    }

    public function testStoreSummaryIsEmptyWithoutProductsOrCategories(): void
    {
        $this->assertSame('', $this->generator($this->store('S', 'USD', 0), $this->sizeCollection(0))->generateStoreSummary(1));
        $this->assertSame('', $this->generator(null, $this->sizeCollection(5))->generateStoreSummary(1));
    }

    public function testStoreSummaryWithMissingNameAttributeUsesCountOnly(): void
    {
        $summary = $this->generator($this->store('S', 'GBP'), $this->sizeCollection(1), $this->categoryConnection([], 0))
            ->generateStoreSummary(1);

        $this->assertSame('S offers 1 products (priced in GBP).', $summary);
    }

    public function testCategorySummaryIncludesConvertedPriceRange(): void
    {
        $summary = $this->generator(
            $this->store('S', 'EUR', 2, 2.0),
            $this->sizeCollection(42, ['min_price' => '5', 'max_price' => '1500.5'])
        )->generateCategorySummary(1, 7);

        $this->assertSame('Cat 7 contains 42 products priced from 10.00 to 3,001.00.', $summary);
    }

    public function testCategorySummaryWithoutPricesOrProducts(): void
    {
        $this->assertSame(
            'Cat 7 contains 3 products.',
            $this->generator($this->store(), $this->sizeCollection(3))->generateCategorySummary(1, 7)
        );
        $this->assertSame('', $this->generator($this->store(), $this->sizeCollection(0))->generateCategorySummary(1, 7));
        $this->assertSame('', $this->generator($this->store(), null)->generateCategorySummary(1, 7));
    }

    public function testCategorySummaryIsEmptyForMissingOrUnnamedCategory(): void
    {
        $missing = $this->createStub(CategoryRepositoryInterface::class);
        $missing->method('get')->willThrowException(new \RuntimeException('404'));
        $unnamed = $this->createStub(CategoryRepositoryInterface::class);
        $cat = $this->createStub(Category::class);
        $cat->method('getName')->willReturn(' ');
        $unnamed->method('get')->willReturn($cat);

        $this->assertSame('', $this->generator($this->store(), $this->sizeCollection(3), null, $missing)->generateCategorySummary(1, 7));
        $this->assertSame('', $this->generator($this->store(), $this->sizeCollection(3), null, $unnamed)->generateCategorySummary(1, 7));
    }

    public function testProductTypeSummaryListsNonZeroTypes(): void
    {
        $connection = $this->connection([], [], ['catalog_product_entity' => [
            ['type_id' => 'simple', 'cnt' => '2000'],
            ['type_id' => 'bundle', 'cnt' => '0'],
            ['type_id' => 'virtual', 'cnt' => '3'],
            ['type_id' => 'grouped', 'cnt' => '1'],
        ]]);

        $this->assertSame(
            'Catalog mix: 2,000 simple, 3 virtual and 1 grouped products.',
            $this->generator($this->store(), null, $connection)->generateProductTypeSummary(1, ['simple', 'virtual'])
        );
    }

    public function testProductTypeSummaryEdgeCases(): void
    {
        $generator = $this->generator($this->store(), null);
        $this->assertSame('', $generator->generateProductTypeSummary(1, []));
        $this->assertSame('', $generator->generateProductTypeSummary(1, ['simple']));

        $zero = $this->connection([], [], ['catalog_product_entity' => [['type_id' => 'simple', 'cnt' => 0]]]);
        $this->assertSame('', $this->generator($this->store(), null, $zero)->generateProductTypeSummary(1, ['simple']));

        $broken = $this->connection([], [], [], [], new \RuntimeException('sql'));
        $this->assertSame('', $this->generator($this->store(), null, $broken)->generateProductTypeSummary(1, ['simple']));
    }
}
