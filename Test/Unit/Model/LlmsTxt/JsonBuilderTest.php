<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\LlmsTxt;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Api\Data\IndexEntryInterface;
use Panth\LlmsTxt\Api\SitemapFetcherInterface;
use Panth\LlmsTxt\Api\WeightedRankerInterface;
use Panth\LlmsTxt\Model\Cache\Type as LlmsCache;
use Panth\LlmsTxt\Model\Index\Entry;
use Panth\LlmsTxt\Model\LlmsTxt\JsonBuilder;
use Panth\LlmsTxt\Model\LlmsTxt\StructuredIndex;
use Panth\LlmsTxt\Model\Sitemap\Entry as SitemapEntry;
use Panth\LlmsTxt\Model\Summary\SummaryGenerator;
use PHPUnit\Framework\TestCase;

class JsonBuilderTest extends TestCase
{
    private array $saved = [];
    private array $rankedInput = [];

    private function builder(
        array $config = [],
        ?string $cacheHit = null,
        bool $emulationFails = false,
        bool $storeFails = false
    ): JsonBuilder {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        if ($storeFails) {
            $storeManager->method('getStore')->willThrowException(new \RuntimeException('gone'));
        } else {
            $store = $this->createStub(Store::class);
            $store->method('getBaseUrl')->willReturn('https://shop.example/');
            $store->method('getName')->willReturn('English');
            $store->method('getCurrentCurrencyCode')->willReturn('EUR');
            $storeManager->method('getStore')->willReturn($store);
        }
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(static fn (string $p) => $config[$p] ?? null);
        $emulation = $this->createStub(Emulation::class);
        if ($emulationFails) {
            $emulation->method('startEnvironmentEmulation')->willThrowException(new \RuntimeException('x'));
        }
        $cache = $this->createStub(LlmsCache::class);
        $cache->method('load')->willReturn($cacheHit ?? false);
        $cache->method('save')->willReturnCallback(function (...$args) {
            $this->saved[] = $args;
            return true;
        });
        $summary = $this->createStub(SummaryGenerator::class);
        $summary->method('generateStoreSummary')->willReturn('Auto summary');
        $fetcher = $this->createStub(SitemapFetcherInterface::class);
        $fetcher->method('fetchForStore')->willReturn([
            new SitemapEntry('https://shop.example/a/b.html', '2026-02-03', 'weekly', 0.7),
            new SitemapEntry('https://shop.example/', null, null, null),
        ]);
        $fetcher->method('getSitemapUrls')->willReturn(['https://shop.example/sitemap.xml']);
        $ranker = $this->createStub(WeightedRankerInterface::class);
        $ranker->method('rank')->willReturnCallback(function (array $entries) {
            $this->rankedInput = $entries;
            return array_slice($entries, 0, 1);
        });
        $index = $this->createStub(StructuredIndex::class);
        $index->method('collect')->willReturn([[
            'code'    => 'key_pages',
            'label'   => 'Key Pages',
            'summary' => 'CMS',
            'entries' => [new Entry('https://shop.example/about', 'About', IndexEntryInterface::TYPE_CMS, 0.123456, 'Us', ['page_id' => 4])],
        ]]);

        return new JsonBuilder($storeManager, $scopeConfig, $emulation, $cache, $summary, $fetcher, $ranker, $index);
    }

    public function testDocumentContainsStoreCompanySectionsAndRankedSitemap(): void
    {
        $json = $this->builder([
            'general/store_information/name' => 'Acme',
            'general/locale/code'            => 'de_DE',
            'trans_email/ident_general/email' => 'hi@acme.example',
            'general/store_information/country_id' => 'DE',
        ])->build(5);
        $doc = json_decode($json, true);

        $this->assertSame('panth.llms_txt/v1', $doc['schema']);
        $this->assertSame(
            ['name' => 'Acme', 'base_url' => 'https://shop.example/', 'currency' => 'EUR', 'language' => 'de_DE',
             'summary' => 'Auto summary', 'store_view' => 'English'],
            $doc['store']
        );
        $this->assertSame('hi@acme.example', $doc['company']['email']);
        $this->assertSame('DE', $doc['company']['country']);
        $this->assertSame('', $doc['company']['phone']);
        $this->assertSame([[
            'code' => 'key_pages', 'label' => 'Key Pages', 'summary' => 'CMS', 'count' => 1,
            'entries' => [[
                'url' => 'https://shop.example/about', 'label' => 'About', 'type' => 'cms',
                'score' => 0.1235, 'summary' => 'Us', 'metadata' => ['page_id' => 4],
            ]],
        ]], $doc['sections']);
        $this->assertSame(['https://shop.example/sitemap.xml'], $doc['sitemap']['sources']);
        $this->assertSame(1, $doc['sitemap']['count']);
        $this->assertSame('/a/b.html', $doc['sitemap']['entries'][0]['label']);
        $this->assertStringContainsString('https://shop.example/a/b.html', $json);
        $this->assertStringNotContainsString('\/', $json);

        $this->assertSame(['sitemap_priority' => 0.7, 'lastmod' => '2026-02-03'], $this->rankedInput[0]->getMetadata());
        $this->assertSame(['sitemap_priority' => 0.0, 'lastmod' => null], $this->rankedInput[1]->getMetadata());
        $this->assertSame('panth_llms_json_v1_store_5', $this->saved[0][1]);
        $this->assertSame(3600, $this->saved[0][3]);
    }

    public function testConfiguredSummaryAndStoreNameFallback(): void
    {
        $doc = json_decode($this->builder(['panth_llms_txt/llms_txt/summary' => 'Mine'])->build(1), true);

        $this->assertSame('English', $doc['store']['name']);
        $this->assertSame('Mine', $doc['store']['summary']);
    }

    public function testCacheHitIsReturnedVerbatim(): void
    {
        $this->assertSame('{"cached":true}', $this->builder([], '{"cached":true}')->build(1));
        $this->assertSame([], $this->saved);
    }

    public function testUnavailableStoreProducesErrorDocument(): void
    {
        $doc = json_decode($this->builder([], null, true)->build(1), true);
        $this->assertSame('store_not_available', $doc['error']);
        $this->assertSame([], $this->saved);

        $doc = json_decode($this->builder([], null, false, true)->build(1), true);
        $this->assertSame('store_not_available', $doc['error']);
        $this->assertArrayHasKey('generated_at', $doc);
        $this->assertSame([], $this->saved);
    }

    public function testEnabledDefaultsToTrueUnlessExplicitlyOff(): void
    {
        $this->assertTrue($this->builder()->isEnabled(1));
        $this->assertTrue($this->builder([JsonBuilder::XML_ENABLED => ''])->isEnabled(1));
        $this->assertFalse($this->builder([JsonBuilder::XML_ENABLED => '0'])->isEnabled(1));
        $this->assertTrue($this->builder([JsonBuilder::XML_ENABLED => '1'])->isEnabled(1));
    }
}
