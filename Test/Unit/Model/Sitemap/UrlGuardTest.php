<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\Sitemap;

use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Test\Unit\Fixture\FakeDnsUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UrlGuardTest extends TestCase
{
    private array $dns = [];

    private function guard(?StoreManagerInterface $storeManager = null, ?LoggerInterface $logger = null): FakeDnsUrlGuard
    {
        if ($storeManager === null) {
            $store = $this->createStub(Store::class);
            $store->method('getBaseUrl')->willReturnCallback(
                static function (string $type = UrlInterface::URL_TYPE_LINK, ?bool $secure = null): string {
                    if ($type === UrlInterface::URL_TYPE_WEB && $secure) {
                        return 'https://SECURE.Shop.Example/';
                    }
                    return $secure ? 'https://shop.example/' : 'http://shop.example/';
                }
            );
            $storeManager = $this->createStub(StoreManagerInterface::class);
            $storeManager->method('getStores')->willReturn([$store]);
        }
        return new FakeDnsUrlGuard(
            $storeManager,
            $logger ?? $this->createStub(LoggerInterface::class),
            fn (string $host): array => $this->dns[$host] ?? []
        );
    }

    public function testStoreHostsAreCollectedLowercasedFromEveryUrlType(): void
    {
        $hosts = $this->guard()->getStoreHosts();

        $this->assertSame(['shop.example' => true, 'secure.shop.example' => true], $hosts);
    }

    public function testStoreHostsAreResolvedOnlyOnce(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.example/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects($this->once())->method('getStores')->with(true)->willReturn([$store]);

        $guard = $this->guard($storeManager);
        $guard->getStoreHosts();
        $guard->getStoreHosts();

        $this->assertTrue($guard->isStoreHost('SHOP.example'));
    }

    public function testStoreHostLookupFailureIsLoggedAndYieldsNoHosts(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willThrowException(new \RuntimeException('boom'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with($this->stringContains('could not resolve store hosts: boom'));

        $guard = $this->guard($storeManager, $logger);

        $this->assertSame([], $guard->getStoreHosts());
        $this->assertFalse($guard->isStoreHost('shop.example'));
    }

    public function testStoreUrlIsValidWithoutAnyDnsLookup(): void
    {
        $guard = $this->guard();

        $this->assertNull($guard->validate('https://shop.example/sitemap.xml'));
        $this->assertSame([], $guard->resolved);
    }

    public function testPublicExternalHostIsValid(): void
    {
        $this->dns['cdn.example'] = ['93.184.216.34'];

        $this->assertNull($this->guard()->validate('https://cdn.example/sitemap.xml'));
    }

    public static function invalidUrls(): array
    {
        return [
            'no scheme'       => ['shop.example/sitemap.xml', 'must use http or https'],
            'ftp'             => ['ftp://shop.example/sitemap.xml', 'must use http or https'],
            'no host'         => ['http:///sitemap.xml', 'is not a valid URL'],
            'credentials'     => ['https://u:p@shop.example/x.xml', 'must not contain credentials'],
            'user only'       => ['https://u@shop.example/x.xml', 'must not contain credentials'],
            'private ip'      => ['http://10.1.2.3/x.xml', 'must point to a store domain'],
            'loopback'        => ['http://127.0.0.1/x.xml', 'must point to a store domain'],
            'unresolvable'    => ['https://nowhere.example/x.xml', 'must point to a store domain'],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function testInvalidUrlsReturnAnExplanation(string $url, string $expected): void
    {
        $error = $this->guard()->validate($url);

        $this->assertNotNull($error);
        $this->assertStringContainsString($expected, $error);
    }

    public function testHostWithOnePrivateAddressIsRejected(): void
    {
        $this->dns['mixed.example'] = ['93.184.216.34', '192.168.0.10'];

        $this->assertSame([], $this->guard()->getPublicAddresses('mixed.example'));
    }

    public function testPublicAddressesAreReturnedWhenAllArePublic(): void
    {
        $this->dns['cdn.example'] = ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'];

        $this->assertSame(
            ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
            $this->guard()->getPublicAddresses('CDN.example')
        );
    }

    public function testLiteralIpsAreCheckedWithoutDns(): void
    {
        $guard = $this->guard();

        $this->assertSame(['8.8.8.8'], $guard->getPublicAddresses('8.8.8.8'));
        $this->assertSame([], $guard->getPublicAddresses('[::1]'));
        $this->assertSame([], $guard->getPublicAddresses('169.254.169.254'));
        $this->assertSame([], $guard->resolved);
    }

    public function testEmptyHostHasNoPublicAddresses(): void
    {
        $this->assertSame([], $this->guard()->getPublicAddresses('[]'));
    }
}
