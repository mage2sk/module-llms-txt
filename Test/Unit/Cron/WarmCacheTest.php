<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Api\SitemapFetcherInterface;
use Panth\LlmsTxt\Cron\WarmCache;
use Panth\LlmsTxt\Model\LlmsTxt\Builder;
use Panth\LlmsTxt\Model\LlmsTxt\FullBuilder;
use Panth\LlmsTxt\Model\LlmsTxt\JsonBuilder;
use Panth\LlmsTxt\Model\Sitemap\Fetcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WarmCacheTest extends TestCase
{
    private array $built = [];

    private function store(int $id): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn($id);
        return $store;
    }

    private function builderStub(string $class, string $tag, bool $enabled = true, ?\Throwable $error = null)
    {
        $builder = $this->createStub($class);
        $builder->method('isEnabled')->willReturn($enabled);
        $builder->method('build')->willReturnCallback(function (int $storeId) use ($tag, $error) {
            if ($error !== null) {
                throw $error;
            }
            $this->built[] = $tag . ':' . $storeId;
            return 'x';
        });
        return $builder;
    }

    private function cron(
        array $stores,
        array $config = [],
        ?SitemapFetcherInterface $fetcher = null,
        ?LoggerInterface $logger = null,
        array $builders = []
    ): WarmCache {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $p, string $scope, $storeId) => $config[$storeId] ?? null
        );
        return new WarmCache(
            $storeManager,
            $scopeConfig,
            $builders['txt'] ?? $this->builderStub(Builder::class, 'txt'),
            $builders['full'] ?? $this->builderStub(FullBuilder::class, 'full'),
            $builders['json'] ?? $this->builderStub(JsonBuilder::class, 'json'),
            $fetcher ?? $this->createStub(SitemapFetcherInterface::class),
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    public function testEveryEnabledStoreIsWarmedAndAdminOrDisabledStoresSkipped(): void
    {
        $fetcher = $this->createMock(Fetcher::class);
        $fetcher->expects($this->exactly(2))->method('clearCache')
            ->willReturnCallback(static function (int $storeId): void {
                self::assertContains($storeId, [1, 3]);
            });

        $this->cron(
            [$this->store(0), $this->store(1), $this->store(2), $this->store(3)],
            [2 => '0', 3 => '1'],
            $fetcher,
            null,
            ['full' => $this->builderStub(FullBuilder::class, 'full', false)]
        )->execute();

        $this->assertSame(['txt:1', 'json:1', 'txt:3', 'json:3'], $this->built);
    }

    public function testOneFailingBuilderDoesNotStopTheOthers(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('[panth_llms_txt] cron llms.txt warm failed for store 1: boom');
        $logger->expects($this->once())->method('info')
            ->with($this->stringContains('cron sitemap clear failed: cache down'));
        $fetcher = $this->createStub(Fetcher::class);
        $fetcher->method('clearCache')->willThrowException(new \RuntimeException('cache down'));

        $this->cron(
            [$this->store(1)],
            [],
            $fetcher,
            $logger,
            ['txt' => $this->builderStub(Builder::class, 'txt', true, new \RuntimeException('boom'))]
        )->execute();

        $this->assertSame(['full:1', 'json:1'], $this->built);
    }

    public function testFullAndJsonFailuresAreLoggedPerStore(): void
    {
        $messages = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $this->cron([$this->store(4)], [], null, $logger, [
            'full' => $this->builderStub(FullBuilder::class, 'full', true, new \RuntimeException('f')),
            'json' => $this->builderStub(JsonBuilder::class, 'json', true, new \RuntimeException('j')),
        ])->execute();

        $this->assertSame([
            '[panth_llms_txt] cron llms-full.txt warm failed for store 4: f',
            '[panth_llms_txt] cron llms.json warm failed for store 4: j',
        ], $messages);
        $this->assertSame(['txt:4'], $this->built);
    }

    public function testStoreListFailureIsLoggedAndAborts(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willThrowException(new \RuntimeException('db'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('[panth_llms_txt] cron store list failed: db');

        (new WarmCache(
            $storeManager,
            $this->createStub(ScopeConfigInterface::class),
            $this->builderStub(Builder::class, 'txt'),
            $this->builderStub(FullBuilder::class, 'full'),
            $this->builderStub(JsonBuilder::class, 'json'),
            $this->createStub(SitemapFetcherInterface::class),
            $logger
        ))->execute();

        $this->assertSame([], $this->built);
    }
}
