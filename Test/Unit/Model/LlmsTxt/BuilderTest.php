<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\LlmsTxt;

use Magento\Cms\Api\Data\PageSearchResultsInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page;
use Magento\Cms\Model\Template\FilterProvider;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Filter\Template as TemplateFilter;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Api\SitemapFetcherInterface;
use Panth\LlmsTxt\Model\Cache\Type as LlmsCache;
use Panth\LlmsTxt\Model\LlmsTxt\Builder;
use Panth\LlmsTxt\Model\LlmsTxt\FullBuilder;
use Panth\LlmsTxt\Model\LlmsTxt\Section\CategoryTree;
use Panth\LlmsTxt\Model\LlmsTxt\Section\Collections;
use Panth\LlmsTxt\Model\LlmsTxt\Section\KeyPages;
use Panth\LlmsTxt\Model\LlmsTxt\Section\OptionalIntegrations;
use Panth\LlmsTxt\Model\LlmsTxt\Section\Overview;
use Panth\LlmsTxt\Model\LlmsTxt\Section\PriorityUrls;
use Panth\LlmsTxt\Model\LlmsTxt\Section\ProductTypes;
use Panth\LlmsTxt\Model\LlmsTxt\Section\Products;
use Panth\LlmsTxt\Model\LlmsTxt\Section\Sitemap;
use Panth\LlmsTxt\Model\LlmsTxt\Section\UseCases;
use Panth\LlmsTxt\Model\Summary\SummaryGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers both the llms.txt and llms-full.txt builders, which share the
 * same section pipeline, caching and emulation contract.
 */
class BuilderTest extends TestCase
{
    private array $saved = [];
    private array $headerArgs = [];
    private array $config = [];
    private string $autoSummary = '';
    private int $emulationStops = 0;

    private function cache(?string $hit = null): LlmsCache
    {
        $cache = $this->createStub(LlmsCache::class);
        $cache->method('load')->willReturn($hit ?? false);
        $cache->method('save')->willReturnCallback(function ($data, $id, $tags, $lifetime) {
            $this->saved[] = [$data, $id, $tags, $lifetime];
            return true;
        });
        return $cache;
    }

    private function emulation(bool $fails = false): Emulation
    {
        $emulation = $this->createStub(Emulation::class);
        if ($fails) {
            $emulation->method('startEnvironmentEmulation')->willThrowException(new \RuntimeException('no store'));
        }
        $emulation->method('stopEnvironmentEmulation')->willReturnCallback(function () use ($emulation) {
            $this->emulationStops++;
            return $emulation;
        });
        return $emulation;
    }

    private function scopeConfig(): ScopeConfigInterface
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn (string $p) => $this->config[$p] ?? null);
        $scopeConfig->method('isSetFlag')->willReturnCallback(fn (string $p) => !empty($this->config[$p]));
        return $scopeConfig;
    }

    private function storeManager(bool $fails = false): StoreManagerInterface
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        if ($fails) {
            $storeManager->method('getStore')->willThrowException(new \RuntimeException('gone'));
        } else {
            $store = $this->createStub(Store::class);
            $store->method('getBaseUrl')->willReturn('https://shop.example');
            $store->method('getName')->willReturn('Default View');
            $storeManager->method('getStore')->willReturn($store);
        }
        return $storeManager;
    }

    /**
     * @return array section doubles keyed by constructor argument name
     */
    private function sections(): array
    {
        $overview = $this->createStub(Overview::class);
        $overview->method('renderHeader')->willReturnCallback(function (...$args) {
            $this->headerArgs = $args;
            return ['# ' . $args[1], ''];
        });
        $overview->method('renderCompany')->willReturn(['[company]']);
        $out = ['overview' => $overview];
        foreach ([
            'priorityUrls' => PriorityUrls::class, 'collections' => Collections::class, 'keyPages' => KeyPages::class,
            'categoryTree' => CategoryTree::class, 'productTypes' => ProductTypes::class, 'useCases' => UseCases::class,
            'sitemap' => Sitemap::class, 'optionalIntegrations' => OptionalIntegrations::class,
        ] as $name => $class) {
            $stub = $this->createStub($class);
            $stub->method('render')->willReturn(['[' . $name . ']']);
            $out[$name] = $stub;
        }
        $products = $this->createStub(Products::class);
        $products->method('renderFeatured')->willReturn(['[featured]']);
        $products->method('renderBestsellers')->willReturn(['[bestsellers]']);
        $products->method('renderRecent')->willReturn(['[recent]']);
        $out['products'] = $products;
        return $out;
    }

    private function fetcher(): SitemapFetcherInterface
    {
        $fetcher = $this->createStub(SitemapFetcherInterface::class);
        $fetcher->method('getSitemapUrls')->willReturn(['https://shop.example/sitemap.xml']);
        return $fetcher;
    }

    private function summary(): SummaryGenerator
    {
        $generator = $this->createStub(SummaryGenerator::class);
        $generator->method('generateStoreSummary')->willReturnCallback(fn () => $this->autoSummary);
        return $generator;
    }

    private function builder(?LlmsCache $cache = null, bool $emulationFails = false, bool $storeFails = false): Builder
    {
        $s = $this->sections();
        return new Builder(
            $this->storeManager($storeFails),
            $this->scopeConfig(),
            $this->emulation($emulationFails),
            $cache ?? $this->cache(),
            $s['overview'],
            $s['priorityUrls'],
            $s['collections'],
            $s['keyPages'],
            $s['categoryTree'],
            $s['products'],
            $s['productTypes'],
            $s['useCases'],
            $s['sitemap'],
            $this->summary(),
            $this->fetcher(),
            $s['optionalIntegrations']
        );
    }

    private function fullBuilder(
        array $pages = [],
        ?\Throwable $listError = null,
        ?LoggerInterface $logger = null,
        bool $storeFails = false
    ): FullBuilder {
        $s = $this->sections();
        $criteriaBuilder = $this->createStub(SearchCriteriaBuilder::class);
        $criteriaBuilder->method('addFilter')->willReturnSelf();
        $criteriaBuilder->method('setPageSize')->willReturnSelf();
        $criteriaBuilder->method('create')->willReturn($this->createStub(SearchCriteria::class));
        $repository = $this->createStub(PageRepositoryInterface::class);
        if ($listError !== null) {
            $repository->method('getList')->willThrowException($listError);
        } else {
            $repository->method('getList')->willReturnCallback(function () use (&$pages) {
                $results = $this->createStub(PageSearchResultsInterface::class);
                $results->method('getItems')->willReturn(array_shift($pages) ?? []);
                return $results;
            });
        }
        $filter = $this->createStub(TemplateFilter::class);
        $filter->method('filter')->willReturnCallback(static fn ($v) => str_replace('{{var x}}', 'X', (string) $v));
        $filterProvider = $this->createStub(FilterProvider::class);
        $filterProvider->method('getPageFilter')->willReturn($filter);

        return new FullBuilder(
            $this->storeManager($storeFails),
            $this->scopeConfig(),
            $repository,
            $criteriaBuilder,
            $filterProvider,
            $logger ?? $this->createStub(LoggerInterface::class),
            $this->emulation(),
            $this->cache(),
            $s['overview'],
            $s['priorityUrls'],
            $s['collections'],
            $s['keyPages'],
            $s['categoryTree'],
            $s['products'],
            $s['productTypes'],
            $s['useCases'],
            $s['sitemap'],
            $this->summary(),
            $this->fetcher(),
            $s['optionalIntegrations']
        );
    }

    private function cmsPage(string $content): Page
    {
        $page = $this->createStub(Page::class);
        $page->method('getContent')->willReturn($content);
        return $page;
    }

    public function testCachedBodyIsReturnedWithoutRendering(): void
    {
        $emulation = $this->createMock(Emulation::class);
        $emulation->expects($this->never())->method('startEnvironmentEmulation');
        $s = $this->sections();
        $builder = new Builder(
            $this->storeManager(),
            $this->scopeConfig(),
            $emulation,
            $this->cache('cached body'),
            $s['overview'], $s['priorityUrls'], $s['collections'], $s['keyPages'], $s['categoryTree'],
            $s['products'], $s['productTypes'], $s['useCases'], $s['sitemap'],
            $this->summary(), $this->fetcher(), $s['optionalIntegrations']
        );

        $this->assertSame('cached body', $builder->build(3));
    }

    public function testBodyRendersSectionsInOrderAndIsCached(): void
    {
        $this->config = ['general/store_information/name' => 'Acme', Builder::XML_SUMMARY => 'Curated summary'];

        $body = $this->builder()->build(3);

        $this->assertSame(implode("\n", [
            '# Acme', '', '[company]', '[priorityUrls]', '[collections]', '[keyPages]', '[categoryTree]',
            '[productTypes]', '[useCases]', '[featured]', '[bestsellers]', '[recent]', '[optionalIntegrations]',
            '[sitemap]', '## Index Formats', '', '- https://shop.example/sitemap.xml',
            '- https://shop.example/robots.txt', '- https://shop.example/llms-full.txt',
            '- https://shop.example/llms.json', '',
        ]), $body);
        $this->assertSame([3, 'Acme', 'Curated summary', 'https://shop.example/'], $this->headerArgs);
        $this->assertSame('panth_llms_txt_v7_store_3', $this->saved[0][1]);
        $this->assertSame(3600, $this->saved[0][3]);
        $this->assertContains(LlmsCache::CACHE_TAG, $this->saved[0][2]);
        $this->assertSame(1, $this->emulationStops);
    }

    public function testTitleAndSummaryFallbackChain(): void
    {
        $this->autoSummary = 'Auto summary';
        $this->builder()->build(1);
        $this->assertSame('Default View', $this->headerArgs[1]);
        $this->assertSame('Auto summary', $this->headerArgs[2]);

        $this->autoSummary = '';
        $this->config = ['design/head/default_description' => 'Head description'];
        $this->builder()->build(1);
        $this->assertSame('Head description', $this->headerArgs[2]);

        $this->config = [];
        $this->builder()->build(1);
        $this->assertSame('Online store catalog, products and editorial content.', $this->headerArgs[2]);
    }

    public function testEmulationFailureReturnsPlaceholderWithoutCaching(): void
    {
        $this->assertSame("# llms.txt\n\nStore not available.\n", $this->builder(null, true)->build(1));
        $this->assertSame([], $this->saved);
    }

    public function testMissingStoreRendersPlaceholderAndStopsEmulation(): void
    {
        $this->assertSame("# llms.txt\n\nStore not available.\n", $this->builder(null, false, true)->build(1));
        $this->assertSame(1, $this->emulationStops);
        $this->assertSame([], $this->saved);
    }

    public function testFullBuilderMissingStoreIsNotCached(): void
    {
        $this->assertSame(
            "# llms-full.txt\n\nStore not available.\n",
            $this->fullBuilder([], null, null, true)->build(1)
        );
        $this->assertSame([], $this->saved);
    }

    public function testEnabledFlagAndCacheTags(): void
    {
        $this->assertFalse($this->builder()->isEnabled(1));
        $this->config = [Builder::XML_ENABLED => '1'];
        $this->assertTrue($this->builder()->isEnabled(1));
        $this->assertSame(
            [LlmsCache::CACHE_TAG, 'cat_c', 'cat_p', 'cms_p', 'store', 'config_scopes'],
            $this->builder()->cacheTags()
        );
    }

    public function testFullBuilderAppendsPolicyPagesAsPlainText(): void
    {
        $this->config = [
            'general/store_information/name' => 'Acme',
            FullBuilder::XML_ABOUT_PAGE      => 'about-us',
            FullBuilder::XML_SHIPPING_PAGE   => ' shipping ',
            FullBuilder::XML_RETURNS_PAGE    => 'returns',
            FullBuilder::XML_FAQ_PAGE        => '',
        ];
        $about = '<style>.a{}</style><h2>About {{var x}}</h2><p>We &amp; you</p><!-- hidden --><script>bad()</script>'
            . '<p>  line   two </p><br><br><br><div>end</div>';

        $body = $this->fullBuilder([[$this->cmsPage($about)], [$this->cmsPage('<p>Ships fast</p>')], []])->build(2);

        $this->assertStringStartsWith("# Acme (Full)\n", $body);
        $this->assertStringContainsString("[priorityUrls]\n## About Us\n\nAbout X\nWe & you\nline two\n\nend\n\n[collections]", $body);
        $this->assertStringContainsString("[sitemap]\n## Shipping Policy\n\nShips fast\n\n## Index Formats", $body);
        $this->assertStringNotContainsString('Return Policy', $body);
        $this->assertStringNotContainsString('Frequently Asked Questions', $body);
        $this->assertStringContainsString('- https://shop.example/llms.txt', $body);
        $this->assertSame('panth_llms_full_txt_v7_store_2', $this->saved[0][1]);
    }

    public function testFullBuilderSkipsPolicyWhenPageIsEmptyOrLookupFails(): void
    {
        $this->config = [FullBuilder::XML_ABOUT_PAGE => 'about'];
        $body = $this->fullBuilder([[$this->cmsPage('')]])->build(2);
        $this->assertStringNotContainsString('## About Us', $body);
        $this->assertStringStartsWith('# Default View (Full)', $body);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('CMS page load failed for "about": db'));
        $this->saved = [];
        $body = $this->fullBuilder([], new \RuntimeException('db'), $logger)->build(2);
        $this->assertStringNotContainsString('## About Us', $body);
    }

    public function testFullBuilderSummaryFallbacksAndFlag(): void
    {
        $this->config = [FullBuilder::XML_ENABLED => '1', 'design/head/default_description' => 'Head'];
        $builder = $this->fullBuilder();
        $builder->build(1);

        $this->assertSame('Head', $this->headerArgs[2]);
        $this->assertTrue($builder->isEnabled(1));
        $this->assertSame($this->builder()->cacheTags(), $builder->cacheTags());

        $this->config = [];
        $this->fullBuilder()->build(1);
        $this->assertSame('Online store catalog, products and editorial content.', $this->headerArgs[2]);
    }
}
