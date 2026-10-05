<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\LlmsTxt\Section;

use Magento\Cms\Helper\Page as CmsPageHelper;
use Magento\Cms\Model\Page;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Model\LlmsTxt\Cms\ActivePages;
use Panth\LlmsTxt\Model\LlmsTxt\Section\KeyPages;
use PHPUnit\Framework\TestCase;

class KeyPagesTest extends TestCase
{
    private function page(int $id, string $identifier, string $title, ?string $meta = null): Page
    {
        $page = $this->createStub(Page::class);
        $page->method('getId')->willReturn($id);
        $page->method('getIdentifier')->willReturn($identifier);
        $page->method('getTitle')->willReturn($title);
        $page->method('getMetaDescription')->willReturn($meta);
        return $page;
    }

    private function config(array $values): ScopeConfigInterface
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(static fn (string $p) => $values[$p] ?? null);
        return $scopeConfig;
    }

    private function storeManager(bool $fails = false): StoreManagerInterface
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        if ($fails) {
            $storeManager->method('getStore')->willThrowException(new \RuntimeException('x'));
        } else {
            $store = $this->createStub(Store::class);
            $store->method('getBaseUrl')->willReturn('https://shop.example/');
            $storeManager->method('getStore')->willReturn($store);
        }
        return $storeManager;
    }

    public function testPagesAreLinkedWithExcerptsAndBaseUrlFallback(): void
    {
        $activePages = $this->createMock(ActivePages::class);
        $activePages->expects($this->once())->method('getNewestFirst')
            ->with(
                1,
                25,
                ['no-route', 'privacy-policy-cookie-restriction-mode', 'enable-cookies', 'home', 'old'],
                'llms.txt'
            )
            ->willReturn([
                $this->page(1, 'about', 'About', ' Who we are '),
                $this->page(2, 'faq', 'FAQ'),
                $this->page(3, 'contact', 'Contact'),
            ]);
        $helper = $this->createStub(CmsPageHelper::class);
        $helper->method('getPageUrl')->willReturnCallback(static function (int $id) {
            if ($id === 2) {
                return '';
            }
            if ($id === 3) {
                throw new \RuntimeException('url');
            }
            return 'https://shop.example/about';
        });

        $section = new KeyPages(
            $activePages,
            $helper,
            $this->config([KeyPages::XML_MAX_CMS => '25', KeyPages::XML_EXCLUDE_CMS => ' home, ,old ']),
            $this->storeManager()
        );

        $this->assertSame([
            '## Key Pages',
            '',
            '- [About](https://shop.example/about): Who we are',
            '- [FAQ](https://shop.example/faq)',
            '- [Contact](https://shop.example/contact)',
            '',
        ], $section->render(1));
    }

    public function testDefaultLimitAndPagesWithoutAnyUrlAreSkipped(): void
    {
        $activePages = $this->createMock(ActivePages::class);
        $activePages->expects($this->once())->method('getNewestFirst')
            ->with(1, 100, $this->anything(), 'llms.txt')
            ->willReturn([$this->page(9, 'x', 'X')]);
        $helper = $this->createStub(CmsPageHelper::class);
        $helper->method('getPageUrl')->willReturn('');

        $section = new KeyPages($activePages, $helper, $this->config([]), $this->storeManager(true));

        $this->assertSame([], $section->render(1));
    }
}
