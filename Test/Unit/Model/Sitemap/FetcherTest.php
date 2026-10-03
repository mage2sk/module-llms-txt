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

class FetcherTest extends TestCase
{
    private array $requested = [];
    private array $responses = [];
    private array $current = [];
    private array $options = [];
    private array $dns = [];

    private function createFetcher(string $configuredUrls = ''): Fetcher
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('setOptions')->willReturnCallback(function (array $options): void {
            $this->options = $options;
        });
        $curl->method('get')->willReturnCallback(function (string $url): void {
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
        $storeManager->method('getStore')->willReturn($store);

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path) => $path === Fetcher::XML_URLS ? $configuredUrls : null
        );

        $cache = $this->createStub(LlmsCache::class);
        $cache->method('load')->willReturn(false);

        $logger = $this->createStub(LoggerInterface::class);

        $guard = new FakeDnsUrlGuard(
            $storeManager,
            $logger,
            fn (string $host): array => $this->dns[$host] ?? []
        );

        return new Fetcher($curl, new Parser($logger), $scopeConfig, $storeManager, $cache, $logger, $guard);
    }

    private function urlset(array $locs): string
    {
        $rows = '';
        foreach ($locs as $loc) {
            $rows .= '<url><loc>' . $loc . '</loc></url>';
        }
        return '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . $rows . '</urlset>';
    }

    public function testNestedSitemapsOnOtherHostsAreNotFetched(): void
    {
        $this->responses = [
            'https://shop.example/sitemap.xml' => [
                'status' => 200,
                'headers' => [],
                'body' => '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                    . '<sitemap><loc>https://shop.example/sitemap-1.xml</loc></sitemap>'
                    . '<sitemap><loc>http://169.254.169.254/latest/meta-data/</loc></sitemap>'
                    . '<sitemap><loc>file:///etc/passwd</loc></sitemap>'
                    . '</sitemapindex>',
            ],
            'https://shop.example/sitemap-1.xml' => [
                'status' => 200,
                'headers' => [],
                'body' => $this->urlset(['https://shop.example/a.html', 'javascript:alert(1)']),
            ],
        ];

        $entries = $this->createFetcher()->fetchForStore(1);

        $this->assertSame(
            ['https://shop.example/sitemap.xml', 'https://shop.example/sitemap-1.xml'],
            $this->requested
        );
        $this->assertCount(1, $entries);
        $this->assertSame('https://shop.example/a.html', $entries[0]->getLocation());
        $this->assertFalse($this->options[CURLOPT_FOLLOWLOCATION]);
        $this->assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $this->options[CURLOPT_PROTOCOLS]);
    }

    public function testRedirectToOtherHostIsNotFollowed(): void
    {
        $this->responses = [
            'https://shop.example/sitemap.xml' => [
                'status' => 302,
                'headers' => ['Location' => 'http://127.0.0.1:8080/admin'],
                'body' => '',
            ],
        ];

        $entries = $this->createFetcher()->fetchForStore(1);

        $this->assertSame(['https://shop.example/sitemap.xml'], $this->requested);
        $this->assertSame([], $entries);
    }

    public function testRedirectOnSameHostIsFollowed(): void
    {
        $this->responses = [
            'https://shop.example/sitemap.xml' => [
                'status' => 301,
                'headers' => ['location' => '/media/sitemap.xml'],
                'body' => '',
            ],
            'https://shop.example/media/sitemap.xml' => [
                'status' => 200,
                'headers' => [],
                'body' => $this->urlset(['https://shop.example/b.html']),
            ],
        ];

        $entries = $this->createFetcher()->fetchForStore(1);

        $this->assertSame(
            ['https://shop.example/sitemap.xml', 'https://shop.example/media/sitemap.xml'],
            $this->requested
        );
        $this->assertCount(1, $entries);
    }

    public function testConfiguredAbsoluteSitemapHostIsAllowed(): void
    {
        $this->dns = ['cdn.example' => ['93.184.216.34']];
        $this->responses = [
            'https://cdn.example/sitemap.xml' => [
                'status' => 200,
                'headers' => [],
                'body' => $this->urlset(['https://shop.example/c.html']),
            ],
        ];

        $entries = $this->createFetcher("https://cdn.example/sitemap.xml\n")->fetchForStore(1);

        $this->assertSame(['https://cdn.example/sitemap.xml'], $this->requested);
        $this->assertCount(1, $entries);
        $this->assertSame(['cdn.example:443:93.184.216.34'], $this->options[CURLOPT_RESOLVE]);
    }

    public function testConfiguredInternalSitemapHostsAreNotFetched(): void
    {
        $this->dns = ['intranet.example' => ['10.0.0.5'], 'mixed.example' => ['93.184.216.34', '127.0.0.1']];
        $entries = $this->createFetcher(
            "http://127.0.0.1:8080/sitemap.xml\nhttp://169.254.169.254/latest/\nhttps://intranet.example/sitemap.xml\n"
            . "https://mixed.example/sitemap.xml\nhttps://unresolvable.example/sitemap.xml\nhttp://[::1]/sitemap.xml\n"
        )->fetchForStore(1);

        $this->assertSame([], $this->requested);
        $this->assertSame([], $entries);
    }
}
