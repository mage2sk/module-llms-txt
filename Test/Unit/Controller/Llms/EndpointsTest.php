<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Controller\Llms;

use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Controller\Llms\Full;
use Panth\LlmsTxt\Controller\Llms\Index;
use Panth\LlmsTxt\Controller\Llms\Json;
use Panth\LlmsTxt\Model\LlmsTxt\Builder;
use Panth\LlmsTxt\Model\LlmsTxt\FullBuilder;
use Panth\LlmsTxt\Model\LlmsTxt\JsonBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EndpointsTest extends TestCase
{
    private array $headers = [];
    private ?int $code = null;
    private ?string $contents = null;

    private function rawFactory(): RawFactory
    {
        $raw = $this->createStub(Raw::class);
        $raw->method('setHeader')->willReturnCallback(function ($name, $value) use ($raw) {
            $this->headers[$name] = $value;
            return $raw;
        });
        $raw->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($raw) {
            $this->code = $code;
            return $raw;
        });
        $raw->method('setContents')->willReturnCallback(function ($contents) use ($raw) {
            $this->contents = $contents;
            return $raw;
        });
        $factory = $this->createStub(RawFactory::class);
        $factory->method('create')->willReturn($raw);
        return $factory;
    }

    private function storeManager(): StoreManagerInterface
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn('7');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        return $storeManager;
    }

    public static function endpoints(): array
    {
        return [
            'llms.txt'      => [Index::class, Builder::class, 'text/plain; charset=utf-8', 'llms.txt', 'llms.txt is not enabled'],
            'llms-full.txt' => [Full::class, FullBuilder::class, 'text/plain; charset=utf-8', 'llms-full.txt', 'Expanded LLM content is not enabled'],
            'llms.json'     => [Json::class, JsonBuilder::class, 'application/json; charset=utf-8', 'llms.json', 'llms.json is not enabled'],
        ];
    }

    #[DataProvider('endpoints')]
    public function testEnabledEndpointServesBuiltBodyWithCacheHeaders(
        string $controller,
        string $builderClass,
        string $type,
        string $filename,
        string $disabledText
    ): void {
        $builder = $this->createMock($builderClass);
        $builder->method('isEnabled')->with(7)->willReturn(true);
        $builder->expects($this->once())->method('build')->with(7)->willReturn('BODY');

        $result = (new $controller($this->rawFactory(), $builder, $this->storeManager()))->execute();

        $this->assertInstanceOf(Raw::class, $result);
        $this->assertSame('BODY', $this->contents);
        $this->assertNull($this->code);
        $this->assertSame($type, $this->headers['Content-Type']);
        $this->assertSame('noindex', $this->headers['X-Robots-Tag']);
        $this->assertSame('nosniff', $this->headers['X-Content-Type-Options']);
        $this->assertSame('public, max-age=3600', $this->headers['Cache-Control']);
        $this->assertSame('inline; filename="' . $filename . '"', $this->headers['Content-Disposition']);
    }

    #[DataProvider('endpoints')]
    public function testDisabledEndpointReturnsUncached404(
        string $controller,
        string $builderClass,
        string $type,
        string $filename,
        string $disabledText
    ): void {
        $builder = $this->createMock($builderClass);
        $builder->method('isEnabled')->willReturn(false);
        $builder->expects($this->never())->method('build');

        (new $controller($this->rawFactory(), $builder, $this->storeManager()))->execute();

        $this->assertSame(404, $this->code);
        $this->assertStringContainsString($disabledText, (string) $this->contents);
        $this->assertSame('no-store, max-age=0', $this->headers['Cache-Control']);
        $this->assertSame($type, $this->headers['Content-Type']);
        $this->assertArrayNotHasKey('Content-Disposition', $this->headers);
    }
}
