<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Fixture;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\DB\Select;

/**
 * Product / product-collection doubles shared by catalog-driven tests.
 */
trait CatalogStubTrait
{
    /** @var array<int,array{0:string,1:array}> */
    private array $collectionCalls = [];

    private function productCollection(array $products, ?string $throwOn = null): ProductCollection
    {
        $collection = $this->createStub(ProductCollection::class);
        $fluent = [
            'setStoreId', 'addAttributeToSelect', 'addAttributeToFilter', 'setVisibility', 'addPriceData',
            'setPageSize', 'addFieldToFilter', 'setOrder', 'addStoreFilter', 'addCategoriesFilter',
        ];
        foreach ($fluent as $method) {
            $collection->method($method)->willReturnCallback(
                function (...$args) use ($method, $collection, $throwOn) {
                    $this->collectionCalls[] = [$method, $args];
                    if ($throwOn === $method) {
                        throw new \RuntimeException($method . ' failed');
                    }
                    return $collection;
                }
            );
        }
        $select = $this->createStub(Select::class);
        $select->method('order')->willReturnCallback(function (...$args) use ($select) {
            $this->collectionCalls[] = ['select.order', $args];
            return $select;
        });
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($products));
        return $collection;
    }

    private function productFactory(ProductCollection ...$collections): ProductCollectionFactory
    {
        $factory = $this->createStub(ProductCollectionFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls(...$collections);
        return $factory;
    }

    private function product(
        string $name,
        string $url = '',
        string $sku = '',
        float $price = 0.0,
        string $category = '',
        array $data = []
    ): Product {
        $product = $this->createStub(Product::class);
        $product->method('getName')->willReturn($name);
        $product->method('getProductUrl')->willReturn($url);
        $product->method('getSku')->willReturn($sku);
        $product->method('getFinalPrice')->willReturn($price);
        $product->method('getData')->willReturnCallback(static fn ($key = '') => $data[$key] ?? null);

        $categories = [];
        if ($category !== '') {
            $cat = $this->createStub(Category::class);
            $cat->method('getName')->willReturn($category);
            $categories[] = $cat;
        }
        $catCollection = $this->createStub(CategoryCollection::class);
        $catCollection->method('addAttributeToSelect')->willReturnSelf();
        $catCollection->method('setPageSize')->willReturnSelf();
        $catCollection->method('getIterator')->willReturn(new \ArrayIterator($categories));
        $product->method('getCategoryCollection')->willReturn($catCollection);
        return $product;
    }

    private function collectionCallsNamed(string $method, int $width = 1): array
    {
        $out = [];
        foreach ($this->collectionCalls as [$name, $args]) {
            if ($name === $method) {
                $out[] = array_slice($args, 0, $width);
            }
        }
        return $out;
    }
}
