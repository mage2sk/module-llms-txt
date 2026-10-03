<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\LlmsTxt;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Helper\Page as CmsPageHelper;
use Magento\Cms\Model\Page;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Api\Data\IndexEntryInterface;
use Panth\LlmsTxt\Api\WeightedRankerInterface;
use Panth\LlmsTxt\Model\LlmsTxt\Bestsellers;
use Panth\LlmsTxt\Model\LlmsTxt\Cms\ActivePages;
use Panth\LlmsTxt\Model\LlmsTxt\StructuredIndex;
use Panth\LlmsTxt\Model\Summary\SummaryGenerator;
use Panth\LlmsTxt\Test\Unit\Fixture\CatalogStubTrait;
use Panth\LlmsTxt\Test\Unit\Fixture\DbStubTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StructuredIndexTest extends TestCase
{
    use CatalogStubTrait;
    use DbStubTrait;

    /** Config that disables everything optional so each test opts in. */
    private const QUIET = [
        'panth_llms_txt/llms_txt/max_featured'          => '0',
        'panth_llms_txt/llms_txt/max_bestsellers'       => '0',
        'panth_llms_txt/llms_txt/max_recent'            => '0',
        'panth_llms_txt/optional/include_testimonials'  => '0',
        'panth_llms_txt/optional/include_faqs'          => '0',
        'panth_llms_txt/optional/include_dynamic_forms' => '0',
    ];

    private function index(array $config, array $deps = []): StructuredIndex
    {
        $config += self::QUIET;
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(static fn (string $p) => $config[$p] ?? null);

        $storeManager = $deps['storeManager'] ?? null;
        if ($storeManager === null) {
            $store = $this->createStub(Store::class);
            $store->method('getBaseUrl')->willReturn('https://shop.example/');
            $store->method('getRootCategoryId')->willReturn($deps['rootId'] ?? 0);
            $storeManager = $this->createStub(StoreManagerInterface::class);
            $storeManager->method('getStore')->willReturn($store);
        }
        $ranker = $this->createStub(WeightedRankerInterface::class);
        $ranker->method('rank')->willReturnArgument(0);
        $summaries = $this->createStub(SummaryGenerator::class);
        $summaries->method('generateCategorySummary')->willReturnCallback(
            static fn (int $store, int $cat) => 'summary ' . $cat
        );
        $visibility = $this->createStub(Visibility::class);
        $visibility->method('getVisibleInCatalogIds')->willReturn([4]);
        $currency = $this->createStub(PriceCurrencyInterface::class);
        $currency->method('convert')->willReturnCallback(static fn ($p) => $p * 1.5);
        $bestsellers = $this->createStub(Bestsellers::class);
        $bestsellers->method('getProductIds')->willReturn($deps['bestsellers'] ?? []);

        return new StructuredIndex(
            $storeManager,
            $scopeConfig,
            $deps['categories'] ?? $this->createStub(CategoryRepositoryInterface::class),
            $deps['categoryCollections'] ?? $this->createStub(CategoryCollectionFactory::class),
            $deps['products'] ?? $this->createStub(ProductCollectionFactory::class),
            $visibility,
            $deps['activePages'] ?? $this->createStub(ActivePages::class),
            $deps['pageHelper'] ?? $this->createStub(CmsPageHelper::class),
            $summaries,
            $ranker,
            $deps['resource'] ?? $this->resource($this->connection()),
            $this->createStub(LoggerInterface::class),
            $bestsellers,
            $currency
        );
    }

    private function codes(array $sections): array
    {
        return array_column($sections, 'code');
    }

    public function testNothingConfiguredYieldsNoSections(): void
    {
        $this->assertSame([], $this->index([])->collect(1));
    }

    public function testPriorityUrlsDetectHomepageAndLabels(): void
    {
        $sections = $this->index([
            'panth_llms_txt/llms_txt/priority_urls' => "/\nSale|/sale\nhttps://other.example/new-in.html\nabout",
        ])->collect(1);

        $this->assertSame(['priority_urls'], $this->codes($sections));
        $entries = $sections[0]['entries'];
        $this->assertSame(IndexEntryInterface::TYPE_HOMEPAGE, $entries[0]->getType());
        $this->assertSame('Homepage', $entries[0]->getLabel());
        $this->assertSame(['https://shop.example/sale', 'Sale'], [$entries[1]->getUrl(), $entries[1]->getLabel()]);
        $this->assertSame('New In', $entries[2]->getLabel());
        $this->assertSame('https://shop.example/about', $entries[3]->getUrl());
        $this->assertSame(IndexEntryInterface::TYPE_COLLECTION, $entries[3]->getType());
        $this->assertSame(['source' => 'admin_priority'], $entries[3]->getMetadata());
    }

    public function testCollectionsSkipInactiveUnnamedAndUrlLessCategories(): void
    {
        $make = function (int $id, string $name, bool $active, $url) {
            $cat = $this->createStub(Category::class);
            $cat->method('getId')->willReturn($id);
            $cat->method('getName')->willReturn($name);
            $cat->method('getIsActive')->willReturn($active);
            if ($url instanceof \Throwable) {
                $cat->method('getUrl')->willThrowException($url);
            } else {
                $cat->method('getUrl')->willReturn($url);
            }
            return $cat;
        };
        $map = [
            3 => $make(3, 'Sale', true, 'https://shop.example/sale.html'),
            4 => $make(4, 'Off', false, 'https://shop.example/off.html'),
            5 => $make(5, '', true, 'https://shop.example/x.html'),
            6 => $make(6, 'NoUrl', true, ''),
            7 => $make(7, 'Boom', true, new \RuntimeException('u')),
        ];
        $repo = $this->createStub(CategoryRepositoryInterface::class);
        $repo->method('get')->willReturnCallback(static function (int $id) use ($map) {
            if (!isset($map[$id])) {
                throw new \RuntimeException('missing');
            }
            return $map[$id];
        });

        $sections = $this->index(
            ['panth_llms_txt/llms_txt/collections_categories' => '3,4,5,6,7,8'],
            ['categories' => $repo]
        )->collect(1);

        $this->assertSame(['collections'], $this->codes($sections));
        $this->assertCount(1, $sections[0]['entries']);
        $entry = $sections[0]['entries'][0];
        $this->assertSame('summary 3', $entry->getSummary());
        $this->assertSame(['category_id' => 3], $entry->getMetadata());
        $this->assertSame(0.8, $entry->getScore());
    }

    public function testKeyPagesHonourLimitExcludesAndStripHtmlFromMeta(): void
    {
        $page = function (int $id, string $identifier, ?string $meta) {
            $p = $this->createStub(Page::class);
            $p->method('getId')->willReturn($id);
            $p->method('getIdentifier')->willReturn($identifier);
            $p->method('getTitle')->willReturn(' T' . $id . ' ');
            $p->method('getMetaDescription')->willReturn($meta);
            return $p;
        };
        $activePages = $this->createMock(ActivePages::class);
        $activePages->expects($this->once())->method('getNewestFirst')
            ->with(1, 2, ['no-route', 'privacy-policy-cookie-restriction-mode', 'enable-cookies', 'tmp'], 'llms.json')
            ->willReturn([
                $page(1, 'a', '<p>Hello&amp;<br>bye</p>'),
                $page(2, 'b', null),
                $page(3, 'c', null),
                $page(4, 'd', null),
            ]);
        $helper = $this->createStub(CmsPageHelper::class);
        $helper->method('getPageUrl')->willReturnCallback(static function (int $id) {
            if ($id === 2) {
                throw new \RuntimeException('x');
            }
            return 'https://shop.example/p' . $id;
        });

        $sections = $this->index(
            ['panth_llms_txt/llms_txt/max_cms' => '2', 'panth_llms_txt/llms_txt/exclude_cms' => ' tmp ,'],
            ['activePages' => $activePages, 'pageHelper' => $helper]
        )->collect(1);

        $entries = $sections[0]['entries'];
        $this->assertSame('key_pages', $sections[0]['code']);
        $this->assertCount(2, $entries);
        $this->assertSame('Hello& bye', $entries[0]->getSummary());
        $this->assertSame('T1', $entries[0]->getLabel());
        $this->assertSame(['identifier' => 'c', 'page_id' => 3], $entries[1]->getMetadata());
    }

    public function testCategoryTreeEntriesCarryLevelAndParent(): void
    {
        $cat = function (int $id, string $name, string $url) {
            $c = $this->createStub(Category::class);
            $c->method('getId')->willReturn($id);
            $c->method('getName')->willReturn($name);
            $c->method('getUrl')->willReturn($url);
            $c->method('getLevel')->willReturn(2);
            $c->method('getParentId')->willReturn(9);
            return $c;
        };
        $collection = $this->createStub(CategoryCollection::class);
        foreach (['setStoreId', 'addAttributeToSelect', 'addAttributeToFilter', 'addPathFilter', 'addAttributeToSort'] as $m) {
            $collection->method($m)->willReturnSelf();
        }
        $collection->method('getIterator')->willReturn(new \ArrayIterator([
            $cat(10, 'Men', 'https://shop.example/men.html'),
            $cat(11, '', 'https://shop.example/x.html'),
            $cat(12, 'NoUrl', ''),
        ]));
        $factory = $this->createStub(CategoryCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $sections = $this->index([], ['rootId' => 9, 'categoryCollections' => $factory])->collect(1);

        $this->assertSame(['categories'], $this->codes($sections));
        $this->assertCount(1, $sections[0]['entries']);
        $this->assertSame(
            ['category_id' => 10, 'level' => 2, 'parent_id' => 9],
            $sections[0]['entries'][0]->getMetadata()
        );
    }

    public function testCategoryCollectionFailureSkipsSection(): void
    {
        $factory = $this->createStub(CategoryCollectionFactory::class);
        $factory->method('create')->willThrowException(new \RuntimeException('x'));

        $this->assertSame([], $this->index([], ['rootId' => 9, 'categoryCollections' => $factory])->collect(1));
    }

    public function testProductSectionsCarryPricesRanksAndFeaturedFlag(): void
    {
        $featured = $this->productCollection([
            $this->product('Mug', 'https://shop.example/mug', 'M1', 10.0, '', ['short_description' => '<b>Nice</b> mug']),
            $this->product('', 'https://shop.example/x'),
            $this->product('NoUrl', ''),
        ]);
        $best = $this->productCollection([
            $this->product('A', 'https://shop.example/a', 'A1', 0.0),
            $this->product('B', 'https://shop.example/b', 'B1', 2.0),
        ]);
        $recent = $this->productCollection([$this->product('R', 'https://shop.example/r')]);

        $sections = $this->index(
            [
                'panth_llms_txt/llms_txt/max_featured'    => '3',
                'panth_llms_txt/llms_txt/max_bestsellers' => '2',
                'panth_llms_txt/llms_txt/max_recent'      => '1',
            ],
            ['products' => $this->productFactory($featured, $best, $recent), 'bestsellers' => [5, 6]]
        )->collect(1);

        $this->assertSame(['featured_products', 'bestsellers', 'recent_arrivals'], $this->codes($sections));
        $mug = $sections[0]['entries'][0];
        $this->assertCount(1, $sections[0]['entries']);
        $this->assertSame('Nice mug', $mug->getSummary());
        $this->assertSame(['sku' => 'M1', 'price' => 15.0, 'featured' => true, 'bestseller_rank' => null], $mug->getMetadata());
        $this->assertSame('Products the merchant has explicitly flagged as showcase items.', $sections[0]['summary']);

        $this->assertNull($sections[1]['entries'][0]->getMetadata()['price']);
        $this->assertSame(1, $sections[1]['entries'][0]->getMetadata()['bestseller_rank']);
        $this->assertSame(2, $sections[1]['entries'][1]->getMetadata()['bestseller_rank']);
        $this->assertContains(['FIELD(e.entity_id, 5,6)'], $this->collectionCallsNamed('select.order'));
        $this->assertContains(['created_at', 'DESC'], $this->collectionCallsNamed('setOrder', 2));
        $this->assertContains(['is_featured', 1], $this->collectionCallsNamed('addAttributeToFilter', 2));
    }

    public function testBestsellersAreSkippedWhenDisabledOrWithoutSales(): void
    {
        $factory = $this->createMock(ProductCollectionFactory::class);
        $factory->expects($this->never())->method('create');

        $this->assertSame([], $this->index(
            ['panth_llms_txt/llms_txt/max_bestsellers' => '5', 'panth_llms_txt/llms_txt/show_bestsellers' => '0'],
            ['products' => $factory, 'bestsellers' => [1]]
        )->collect(1));
        $this->assertSame([], $this->index(
            ['panth_llms_txt/llms_txt/max_bestsellers' => '5'],
            ['products' => $factory]
        )->collect(1));
    }

    public function testFeaturedAttributeErrorSkipsSection(): void
    {
        $collection = $this->productCollection([$this->product('X', 'https://s/x')], 'addAttributeToFilter');

        $this->assertSame([], $this->index(
            ['panth_llms_txt/llms_txt/max_featured' => '2'],
            ['products' => $this->productFactory($collection)]
        )->collect(1));
    }

    public function testOptionalIntegrationsBecomeSections(): void
    {
        $connection = $this->connection(
            ['panth_testimonial', 'panth_testimonial_category', 'panth_faq_item', 'panth_faq_category', 'panth_dynamic_form'],
            [
                'panth_testimonial' => ['url_key' => [], 'title' => [], 'short_content' => [], 'status' => [], 'sort_order' => []],
                'panth_testimonial_category' => ['store_id' => []],
                'panth_faq_item' => ['is_active' => []],
                'panth_dynamic_form' => ['url_key' => [], 'name' => [], 'description' => []],
            ],
            [
                'panth_testimonial_category' => [['name' => 'Fans', 'url_key' => 'fans'], ['name' => '', 'url_key' => 'z']],
                'panth_testimonial' => [['title' => 'Wow', 'url_key' => 'wow', 'short_content' => '<i>so</i> good']],
                'panth_faq_category' => [['name' => 'Delivery', 'url_key' => 'delivery']],
                'panth_faq_item' => [['question' => 'How?', 'url_key' => 'how'], ['question' => 'Q', 'url_key' => '']],
                'panth_dynamic_form' => [['url_key' => 'quote', 'name' => 'Quote', 'description' => 'Ask'], ['url_key' => 'k']],
            ]
        );

        $sections = $this->index([
            'panth_llms_txt/optional/include_testimonials'  => '1',
            'panth_llms_txt/optional/include_faqs'          => '1',
            'panth_llms_txt/optional/include_dynamic_forms' => '1',
            'panth_faq/general/faq_route'                   => 'help',
        ], ['resource' => $this->resource($connection)])->collect(1);

        $this->assertSame(['testimonials', 'faqs', 'forms'], $this->codes($sections));
        $urls = static fn (array $s) => array_map(static fn ($e) => $e->getUrl(), $s['entries']);
        $this->assertSame(
            ['https://shop.example/testimonials/category/fans', 'https://shop.example/testimonials/wow'],
            $urls($sections[0])
        );
        $this->assertSame('so good', $sections[0]['entries'][1]->getSummary());
        $this->assertSame(
            ['https://shop.example/help/category/delivery', 'https://shop.example/help/item/how'],
            $urls($sections[1])
        );
        $this->assertSame(['https://shop.example/pages/quote', 'https://shop.example/pages/k'], $urls($sections[2]));
        $this->assertSame(['Quote', 'k'], array_map(static fn ($e) => $e->getLabel(), $sections[2]['entries']));
        $this->assertContains('status = ?', $this->whereConditions());
        $this->assertContains('store_id IN (?)', $this->whereConditions());
    }

    public function testOptionalIntegrationQueryErrorsAreSwallowed(): void
    {
        $connection = $this->connection(
            ['panth_testimonial', 'panth_faq_category', 'panth_dynamic_form'],
            [],
            [],
            [],
            new \RuntimeException('sql')
        );

        $this->assertSame([], $this->index([
            'panth_llms_txt/optional/include_testimonials'  => '1',
            'panth_llms_txt/optional/include_faqs'          => '1',
            'panth_llms_txt/optional/include_dynamic_forms' => '1',
        ], ['resource' => $this->resource($connection)])->collect(1));
    }

    public function testStoreFailureFallsBackToRelativeBase(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new \RuntimeException('x'));

        $sections = $this->index(
            ['panth_llms_txt/llms_txt/priority_urls' => '/contact'],
            ['storeManager' => $storeManager]
        )->collect(1);

        $this->assertSame('/contact', $sections[0]['entries'][0]->getUrl());
    }
}
