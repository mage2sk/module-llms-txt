<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\Config\Backend;

use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Model\Config\Backend\SitemapUrls;
use Panth\LlmsTxt\Test\Unit\Fixture\FakeDnsUrlGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SitemapUrlsTest extends TestCase
{
    private function model(string $value): SitemapUrls
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.example/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store]);

        $guard = new FakeDnsUrlGuard(
            $storeManager,
            $this->createStub(LoggerInterface::class),
            static fn (string $host): array => match ($host) {
                'cdn.example' => ['93.184.216.34'],
                'internal.example' => ['192.168.1.20'],
                default => [],
            }
        );

        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(EventManager::class));

        $model = new SitemapUrls(
            $context,
            $this->createStub(Registry::class),
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(TypeListInterface::class),
            $guard
        );
        $model->setValue($value);
        return $model;
    }

    public function testStoreRelativeAndPublicUrlsAreAccepted(): void
    {
        $model = $this->model("# comment\n/sitemap.xml\nhttps://shop.example/media/sitemap.xml\nhttps://cdn.example/sitemap.xml\n");
        $this->assertSame($model, $model->beforeSave());
    }

    public static function rejected(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/sitemap.xml'],
            'metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'private dns' => ['https://internal.example/sitemap.xml'],
            'unresolvable' => ['https://nowhere.example/sitemap.xml'],
            'ipv6 loopback' => ['http://[::1]/sitemap.xml'],
            'scheme' => ['ftp://cdn.example/sitemap.xml'],
            'credentials' => ['https://user:secret@cdn.example/sitemap.xml'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejected')]
    public function testInternalOrInvalidUrlsAreRejected(string $url): void
    {
        $this->expectException(LocalizedException::class);
        $this->model("/sitemap.xml\n" . $url)->beforeSave();
    }
}
