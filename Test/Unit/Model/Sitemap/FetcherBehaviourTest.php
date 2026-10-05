<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\Sitemap;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Model\Cache\Type as LlmsCache;
use Panth\LlmsTxt\Model\Sitemap\Fetcher;
use Panth\LlmsTxt\Model\Sitemap\Parser;
use Panth\LlmsTxt\Test\Unit\Fixture\FakeDnsUrlGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Caching, limits, configuration and HTTP error handling of the fetcher.
 */
class FetcherBehaviourTest extends TestCase
{
    private array $requested = [];
    private array $responses = [];
    private array $current = [];
    private array $config = [];
    private array $saved = [];
    private array $removed = [];
    private array $logs = [];
    private $cacheHit = false;
    private bool $curlThrows = false;

    private function fetcher(bool $storeFails = false): Fetcher
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('get')->willReturnCallback(function (string $url): void {
            if ($this->curlThrows) {
                throw new \RuntimeException('timeout');
            }
            $this->requested[] = $url;
            $this->current = $this->responses[$url] ?? ['status' => 404, 'body' => '', 'headers' => []];
        });
        $curl->method('getStatus')->willReturnCallback(fn (): int => $this->current['status']);
        $curl->method('getBody')->willReturnCallback(fn (): string => $this->current['body']);
        $curl->method('getHeaders')->willReturnCallback(fn (): array => $this->current['headers']);

        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.example/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store]);
        if ($storeFails) {
            $storeManager->method('getStore')->willThrowException(new \RuntimeException('no store'));
        } else {
            $storeManager->method('getStore')->willReturn($store);
        }

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn (string $path) => $this->config[$path] ?? null);

        $cache = $this->createStub(LlmsCache::class);
        $cache->method('load')->willReturnCallback(fn () => $this->cacheHit);
        $cache->method('save')->willReturnCallback(function (...$args) {
            $this->saved[] = $args;
            return true;
        });
        $cache->method('remove')->willReturnCallback(function ($id) {
            $this->removed[] = $id;
            return true;
        });

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function (string $m): void {
            $this->logs[] = $m;
        });

        $guard = new FakeDnsUrlGuard($storeManager, $logger, static fn (): array => []);

        return new Fetcher($curl, new Parser($logger), $scopeConfig, $storeManager, $cache, $logger, $guard);
    }

    private function urlset(array $locs): array
    {
        $rows = '';
        foreach ($locs as $loc) {
            $rows .= '<url><loc>' . $loc . '</loc><priority>0.5</priority></url>';
        }
        return [
            'status' => 200,
            'headers' => [],
            'body' => '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $rows . '</urlset>',
        ];
    }

    public function testCachedPayloadIsHydratedWithoutHttp(): void
    {
        $this->cacheHit = json_encode([
            ['loc' => 'https://shop.example/a', 'lastmod' => '2026-01-01', 'cf' => 'daily', 'priority' => 0.4, 'src' => 's'],
            ['loc' => '', 'priority' => 1],
            ['loc' => 'https://shop.example/b', 'priority' => null],
        ]);

        $entries = $this->fetcher()->fetchForStore(1);

        $this->assertSame([], $this->requested);
        $this->assertCount(2, $entries);
        $this->assertSame(['https://shop.example/a', '2026-01-01', 'daily', 0.4, 's'], [
            $entries[0]->getLocation(), $entries[0]->getLastModified(), $entries[0]->getChangeFrequency(),
            $entries[0]->getPriority(), $entries[0]->getSource(),
        ]);
        $this->assertNull($entries[1]->getPriority());
        $this->assertNull($entries[1]->getLastModified());
        $this->assertSame('', $entries[1]->getSource());
    }

    public function testCorruptCacheFallsBackToFetching(): void
    {
        $this->cacheHit = 'not json';
        $this->responses['https://shop.example/sitemap.xml'] = $this->urlset(['https://shop.example/x']);

        $this->assertCount(1, $this->fetcher()->fetchForStore(1));
        $this->assertSame(['https://shop.example/sitemap.xml'], $this->requested);
    }

    public function testEntriesAreDedupedCappedAndCachedWithMinimumTtl(): void
    {
        $this->config = [Fetcher::XML_MAX_ENTRIES => '2', Fetcher::XML_TTL => '5'];
        $this->responses['https://shop.example/sitemap.xml'] = $this->urlset([
            'https://shop.example/a', 'https://shop.example/a', 'mailto:x@y', 'https://shop.example/b', 'https://shop.example/c',
        ]);

        $entries = $this->fetcher()->fetchForStore(9);

        $this->assertSame(['https://shop.example/a', 'https://shop.example/b'], array_map(
            static fn ($e) => $e->getLocation(),
            $entries
        ));
        $this->assertSame('panth_llms_sitemap_v1_store_9', $this->saved[0][1]);
        $this->assertSame([LlmsCache::CACHE_TAG, 'config_scopes'], $this->saved[0][2]);
        $this->assertSame(60, $this->saved[0][3]);
        $payload = json_decode($this->saved[0][0], true);
        $this->assertSame(['loc' => 'https://shop.example/a', 'lastmod' => null, 'cf' => null, 'priority' => 0.5,
            'src' => 'https://shop.example/sitemap.xml'], $payload[0]);
    }

    public function testConfiguredUrlsAreTrimmedResolvedAndDeduplicated(): void
    {
        $this->config = [Fetcher::XML_URLS => "# note\n\n/media/sitemap.xml\nmedia/sitemap.xml\nhttps://shop.example/s2.xml"];

        $this->assertSame(
            ['https://shop.example/media/sitemap.xml', 'https://shop.example/s2.xml'],
            $this->fetcher()->getSitemapUrls(1)
        );
    }

    public function testAutoSitemapCanBeDisabledAndStoreFailureYieldsNoUrls(): void
    {
        $this->assertSame(['https://shop.example/sitemap.xml'], $this->fetcher()->getSitemapUrls(1));

        $this->config = [Fetcher::XML_AUTO => '0'];
        $this->assertSame([], $this->fetcher()->getSitemapUrls(1));
        $this->assertSame([], $this->fetcher()->fetchForStore(1));

        $this->config = [];
        $this->assertSame([], $this->fetcher(true)->getSitemapUrls(1));
    }

    public function testClearCacheRemovesTheStoreKey(): void
    {
        $this->fetcher()->clearCache(4);

        $this->assertSame(['panth_llms_sitemap_v1_store_4'], $this->removed);
    }

    public function testHttpErrorsAndExceptionsYieldNoEntries(): void
    {
        $this->responses['https://shop.example/sitemap.xml'] = ['status' => 500, 'body' => 'x', 'headers' => []];
        $this->assertSame([], $this->fetcher()->fetchForStore(1));
        $this->assertStringContainsString('-> HTTP 500', implode("\n", $this->logs));

        $this->curlThrows = true;
        $this->assertSame([], $this->fetcher()->fetchForStore(1));
        $this->assertStringContainsString('sitemap GET failed for https://shop.example/sitemap.xml: timeout', implode("\n", $this->logs));
    }

    public function testNonSitemapBodyIsLoggedAndIgnored(): void
    {
        $this->responses['https://shop.example/sitemap.xml'] = ['status' => 200, 'body' => '<html/>', 'headers' => []];

        $this->assertSame([], $this->fetcher()->fetchForStore(1));
        $this->assertStringContainsString('not urlset/sitemapindex', implode("\n", $this->logs));
    }

    public function testRedirectLoopsStopAfterTheHopLimit(): void
    {
        $this->responses['https://shop.example/sitemap.xml'] = [
            'status' => 302, 'body' => '', 'headers' => ['Location' => ['/sitemap.xml']],
        ];

        $this->assertSame([], $this->fetcher()->fetchForStore(1));
        $this->assertCount(4, $this->requested);
        $this->assertStringContainsString('too many redirects', implode("\n", $this->logs));
    }

    public function testRedirectWithoutUsableLocationStops(): void
    {
        $this->responses['https://shop.example/sitemap.xml'] = [
            'status' => 301, 'body' => '', 'headers' => ['Location' => '//evil.example/x'],
        ];
        $this->assertSame([], $this->fetcher()->fetchForStore(1));

        $this->requested = [];
        $this->responses['https://shop.example/sitemap.xml'] = ['status' => 301, 'body' => '', 'headers' => []];
        $this->assertSame([], $this->fetcher()->fetchForStore(1));
        $this->assertCount(1, $this->requested);
    }

    public function testNestedIndexSkipsNonUrlsetChildren(): void
    {
        $this->responses['https://shop.example/sitemap.xml'] = [
            'status' => 200,
            'headers' => [],
            'body' => '<sitemapindex><sitemap><loc>https://shop.example/1.xml</loc></sitemap>'
                . '<sitemap><loc>https://shop.example/2.xml</loc></sitemap>'
                . '<sitemap><loc>https://shop.example/3.xml</loc></sitemap></sitemapindex>',
        ];
        $this->responses['https://shop.example/1.xml'] = ['status' => 200, 'headers' => [], 'body' => '<sitemapindex/>'];
        $this->responses['https://shop.example/3.xml'] = $this->urlset(['https://shop.example/z']);

        $entries = $this->fetcher()->fetchForStore(1);

        $this->assertCount(1, $entries);
        $this->assertSame('https://shop.example/3.xml', $entries[0]->getSource());
    }
}
