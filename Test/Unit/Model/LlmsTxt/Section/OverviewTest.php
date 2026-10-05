<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\LlmsTxt\Section;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Model\LlmsTxt\Section\Overview;
use PHPUnit\Framework\TestCase;

class OverviewTest extends TestCase
{
    private function overview(array $config, ?StoreManagerInterface $storeManager = null): Overview
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(static fn (string $p) => $config[$p] ?? null);
        if ($storeManager === null) {
            $store = $this->createStub(Store::class);
            $store->method('getName')->willReturn('English');
            $store->method('getCurrentCurrencyCode')->willReturn('EUR');
            $storeManager = $this->createStub(StoreManagerInterface::class);
            $storeManager->method('getStore')->willReturn($store);
        }
        return new Overview($scopeConfig, $storeManager, $this->createStub(ResolverInterface::class));
    }

    public function testHeaderListsTitleSummaryStoreViewCurrencyAndLanguage(): void
    {
        $lines = $this->overview(['general/locale/code' => 'en_GB'])
            ->renderHeader(1, 'Acme', 'We sell things.', 'https://shop.example/');

        $this->assertSame('# Acme', $lines[0]);
        $this->assertSame('> We sell things.', $lines[2]);
        $this->assertContains('## Store Overview', $lines);
        $this->assertContains('- URL: https://shop.example/', $lines);
        $this->assertContains('- Store View: English', $lines);
        $this->assertContains('- Currency: EUR', $lines);
        $this->assertContains('- Language: en_GB', $lines);
        $this->assertMatchesRegularExpression('/^- Generated: \d{4}-\d\d-\d\d \d\d:\d\d:\d\d UTC$/', $lines[count($lines) - 2]);
        $this->assertSame('', end($lines));
    }

    public function testHeaderSkipsStoreDetailsWhenStoreLookupFails(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new \RuntimeException('gone'));

        $lines = $this->overview([], $storeManager)->renderHeader(1, 'T', 'S', 'https://s/');

        $this->assertEmpty(preg_grep('/^- (Store View|Currency|Language):/', $lines));
        $this->assertContains('- Type: E-commerce catalog (Magento 2)', $lines);
    }

    public function testCompanyBlockListsOnlyFilledFieldsInOrder(): void
    {
        $lines = $this->overview([
            'trans_email/ident_general/email'              => 'hi@shop.example',
            'general/store_information/city'               => 'Leeds',
            'general/store_information/merchant_vat_number' => 'GB123',
        ])->renderCompany(1);

        $this->assertSame(
            ['## Company', '', '- Email: hi@shop.example', '- City: Leeds', '- VAT: GB123', ''],
            $lines
        );
    }

    public function testCompanyBlockIsOmittedWhenNothingIsConfigured(): void
    {
        $this->assertSame([], $this->overview([])->renderCompany(1));
    }
}
