<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\LlmsTxt\Section;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Model\LlmsTxt\Section\PriorityUrls;
use PHPUnit\Framework\TestCase;

class PriorityUrlsTest extends TestCase
{
    private function section(?string $raw, bool $storeFails = false): PriorityUrls
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($raw);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        if ($storeFails) {
            $storeManager->method('getStore')->willThrowException(new \RuntimeException('x'));
        } else {
            $store = $this->createStub(Store::class);
            $store->method('getBaseUrl')->willReturn('https://shop.example/');
            $storeManager->method('getStore')->willReturn($store);
        }
        return new PriorityUrls($scopeConfig, $storeManager);
    }

    public function testEmptyConfigRendersNothing(): void
    {
        $this->assertSame([], $this->section(null)->render(1));
        $this->assertSame([], $this->section("  \n ")->render(1));
    }

    public function testUrlsAreResolvedAndLabelledOrGuessed(): void
    {
        $lines = $this->section(
            "Sale | /sale.html\n/\n/new-arrivals_2026.html\nhttps://other.example/x\nabout-us\n | \n"
        )->render(1);

        $this->assertSame([
            '## Priority URLs',
            '',
            '- [Sale](https://shop.example/sale.html)',
            '- [Homepage](https://shop.example/)',
            '- [New Arrivals 2026](https://shop.example/new-arrivals_2026.html)',
            '- [X](https://other.example/x)',
            '- [About Us](https://shop.example/about-us)',
            '',
        ], $lines);
    }

    public function testRelativeUrlsStillRenderWhenStoreIsUnavailable(): void
    {
        $lines = $this->section('Contact|/contact', true)->render(1);

        $this->assertSame('- [Contact](/contact)', $lines[2]);
    }
}
