<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\LlmsTxt\Section;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Model\LlmsTxt\Section\CategoryTree;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CategoryTreeTest extends TestCase
{
    private array $filters = [];

    private function cat(int $id, int $parent, string $name, string $url = ''): Category
    {
        $cat = $this->createStub(Category::class);
        $cat->method('getId')->willReturn($id);
        $cat->method('getParentId')->willReturn($parent);
        $cat->method('getName')->willReturn($name);
        $cat->method('getUrl')->willReturn($url);
        return $cat;
    }

    private function collection(array $items): Collection
    {
        $collection = $this->createStub(Collection::class);
        foreach (['setStoreId', 'addAttributeToSelect', 'addPathFilter', 'addAttributeToSort'] as $m) {
            $collection->method($m)->willReturnSelf();
        }
        $collection->method('addAttributeToFilter')->willReturnCallback(
            function ($attr, $cond) use ($collection) {
                $this->filters[] = [$attr, $cond];
                return $collection;
            }
        );
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));
        return $collection;
    }

    private function tree(int $rootId, ?Collection $collection, $depth = null, ?LoggerInterface $logger = null, bool $storeFails = false): CategoryTree
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        if ($storeFails) {
            $storeManager->method('getStore')->willThrowException(new \RuntimeException('nope'));
        } else {
            $store = $this->createStub(Store::class);
            $store->method('getRootCategoryId')->willReturn($rootId);
            $storeManager->method('getStore')->willReturn($store);
        }
        $factory = $this->createStub(CollectionFactory::class);
        if ($collection === null) {
            $factory->method('create')->willThrowException(new \RuntimeException('bad'));
        } else {
            $factory->method('create')->willReturn($collection);
        }
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($depth);
        return new CategoryTree(
            $factory,
            $this->createStub(CategoryFactory::class),
            $storeManager,
            $scopeConfig,
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    public function testNestedTreeIsIndentedAndUnnamedNodesSkipped(): void
    {
        $collection = $this->collection([
            $this->cat(10, 2, 'Men', 'https://s/men.html'),
            $this->cat(11, 2, 'Women'),
            $this->cat(12, 2, ' '),
            $this->cat(20, 10, 'Shirts', 'https://s/men/shirts.html'),
            $this->cat(30, 20, 'Linen'),
            $this->cat(40, 12, 'Orphan of unnamed'),
        ]);

        $lines = $this->tree(2, $collection)->render(1);

        $this->assertSame([
            '## Category Tree',
            '',
            '- [Men](https://s/men.html)',
            '  - [Shirts](https://s/men/shirts.html)',
            '    - Linen',
            '- Women',
            '',
        ], $lines);
    }

    public function testDepthIsClampedBetweenOneAndFive(): void
    {
        $this->tree(2, $this->collection([]), '9')->render(1);
        $this->assertContains(['level', ['lteq' => 6]], $this->filters);

        $this->filters = [];
        $this->tree(2, $this->collection([]), null)->render(1);
        $this->assertContains(['level', ['lteq' => 4]], $this->filters);
    }

    public function testMissingRootOrStoreRendersNothing(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('category tree store load failed'));

        $this->assertSame([], $this->tree(0, $this->collection([]))->render(1));
        $this->assertSame([], $this->tree(2, $this->collection([]), null, $logger, true)->render(1));
    }

    public function testCollectionFailureIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('category collection build failed: bad'));

        $this->assertSame([], $this->tree(2, null, null, $logger)->render(1));
    }

    public function testEmptyCollectionRendersNothing(): void
    {
        $this->assertSame([], $this->tree(2, $this->collection([]))->render(1));
    }
}
