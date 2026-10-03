<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\LlmsTxt\Section;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Model\LlmsTxt\Section\OptionalIntegrations;
use Panth\LlmsTxt\Test\Unit\Fixture\DbStubTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OptionalIntegrationsRenderTest extends TestCase
{
    use DbStubTrait;

    private function section(
        ResourceConnection $resource,
        array $config = [],
        ?StoreManagerInterface $storeManager = null,
        ?LoggerInterface $logger = null
    ): OptionalIntegrations {
        if ($storeManager === null) {
            $store = $this->createStub(Store::class);
            $store->method('getBaseUrl')->willReturn('https://shop.example');
            $storeManager = $this->createStub(StoreManagerInterface::class);
            $storeManager->method('getStore')->willReturn($store);
        }
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(static fn (string $p) => $config[$p] ?? null);
        return new OptionalIntegrations(
            $resource,
            $storeManager,
            $scopeConfig,
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    public function testTestimonialCategoriesAndApprovedItemsAreListed(): void
    {
        $connection = $this->connection(
            ['panth_testimonial', 'panth_testimonial_category'],
            [
                'panth_testimonial_category' => ['url_key' => [], 'name' => [], 'store_id' => [], 'sort_order' => []],
                'panth_testimonial' => [
                    'url_key' => [], 'title' => [], 'is_approved' => [], 'short_content' => [], 'store_id' => [],
                ],
            ],
            [
                'panth_testimonial_category' => [
                    ['name' => 'Happy', 'url_key' => 'happy'],
                    ['name' => '', 'url_key' => 'blank'],
                ],
                'panth_testimonial' => [
                    ['title' => 'Great', 'url_key' => 'great', 'short_content' => ' Loved it '],
                    ['title' => 'Plain', 'url_key' => 'plain'],
                    ['title' => 'No key', 'url_key' => ''],
                ],
            ]
        );

        $lines = $this->section($this->resource($connection), ['panth_testimonials/general/route' => '/reviews/'])
            ->render(1);

        $this->assertSame('## Testimonials', $lines[0]);
        $this->assertSame([
            '- [Happy](https://shop.example/reviews/category/happy) - category',
            '- [Great](https://shop.example/reviews/great): Loved it',
            '- [Plain](https://shop.example/reviews/plain)',
            '',
        ], array_slice($lines, 4));
        $this->assertContains('t.is_approved = ?', $this->whereConditions());
        $this->assertContains('t.store_id IN (?)', $this->whereConditions());
    }

    public function testTestimonialStoreLinkTableIsUsedWhenThereIsNoStoreColumn(): void
    {
        $connection = $this->connection(
            ['panth_testimonial', 'panth_testimonial_store'],
            ['panth_testimonial' => ['url_key' => [], 'title' => [], 'status' => []]],
            ['panth_testimonial' => [['title' => 'T', 'url_key' => 't']]]
        );

        $lines = $this->section($this->resource($connection))->render(1);

        $this->assertContains('- [T](https://shop.example/testimonials/t)', $lines);
        $this->assertContains('EXISTS (?)', $this->whereConditions());
        $this->assertContains('t.status = ?', $this->whereConditions());
    }

    public function testFaqCategoriesUseStoreValuesAndItemsJoinStoreTable(): void
    {
        $connection = $this->connection(
            [
                'panth_faq_item', 'panth_faq_item_store', 'panth_faq_category',
                'panth_faq_category_value', 'panth_faq_category_store',
            ],
            [
                'panth_faq_category' => ['url_key' => [], 'name' => [], 'is_active' => [], 'sort_order' => []],
                'panth_faq_category_value' => ['name' => [], 'url_key' => []],
                'panth_faq_item' => ['url_key' => [], 'question' => [], 'is_active' => []],
            ],
            [
                'panth_faq_category' => [['name' => 'Shipping', 'url_key' => 'shipping']],
                'panth_faq_item' => [['question' => 'When?', 'url_key' => 'when'], ['question' => '', 'url_key' => 'x']],
            ]
        );

        $lines = $this->section($this->resource($connection), [
            OptionalIntegrations::XML_INCLUDE_TESTIMONIALS => '0',
            OptionalIntegrations::XML_INCLUDE_DYNAMIC_FORMS => '0',
        ])->render(1);

        $this->assertSame([
            '## FAQs',
            '',
            '> Merchant-authored questions and answers - direct grounding material for AI assistants.',
            '',
            '- [Shipping](https://shop.example/faq/category/shipping) - category',
            '- [When?](https://shop.example/faq/item/when)',
            '',
        ], $lines);
        $this->assertContains('COALESCE(v.url_key, c.url_key) IS NOT NULL', $this->whereConditions());
        $this->assertContains('c.is_active = ?', $this->whereConditions());
        $this->assertContains('EXISTS (?)', $this->whereConditions());
    }

    public function testFaqCategoryStoreColumnIsUsedWithoutLinkTable(): void
    {
        $connection = $this->connection(
            ['panth_faq_category'],
            ['panth_faq_category' => ['url_key' => [], 'name' => [], 'store_id' => []]],
            ['panth_faq_category' => [['name' => 'A', 'url_key' => 'a']]]
        );

        $lines = $this->section($this->resource($connection))->render(1);

        $this->assertContains('- [A](https://shop.example/faq/category/a) - category', $lines);
        $this->assertContains('c.store_id IN (?)', $this->whereConditions());
        $this->assertContains('c.url_key IS NOT NULL', $this->whereConditions());
    }

    public function testFormsFallBackFromTitleToNameToKey(): void
    {
        $connection = $this->connection(
            ['panth_dynamic_form'],
            ['panth_dynamic_form' => [
                'url_key' => [], 'title' => [], 'name' => [], 'description' => [],
                'is_active' => [], 'form_type' => [], 'store_id' => [],
            ]],
            ['panth_dynamic_form' => [
                ['url_key' => 'quote', 'title' => 'Get a quote', 'description' => 'Fast reply'],
                ['url_key' => 'call', 'title' => '', 'name' => 'Callback'],
                ['url_key' => 'raw', 'title' => '', 'name' => ''],
                ['url_key' => ''],
            ]]
        );

        $lines = $this->section($this->resource($connection))->render(1);

        $this->assertSame([
            '- [Get a quote](https://shop.example/pages/quote): Fast reply',
            '- [Callback](https://shop.example/pages/call)',
            '- [raw](https://shop.example/pages/raw)',
            '',
        ], array_slice($lines, 4));
        $this->assertContains('form_type IN (?)', $this->whereConditions());
    }

    public function testQueryFailuresAreLoggedAndSectionsOmitted(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(4))->method('info');
        $connection = $this->connection(
            ['panth_testimonial_category', 'panth_faq_category', 'panth_faq_item', 'panth_dynamic_form'],
            ['panth_faq_item' => ['url_key' => []]],
            [],
            [],
            new \RuntimeException('sql')
        );

        $this->assertSame([], $this->section($this->resource($connection), [], null, $logger)->render(1));
    }

    public function testStoreFailureAndDisabledFlagsRenderNothing(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new \RuntimeException('x'));
        $this->assertSame([], $this->section($this->resource($this->connection()), [], $storeManager)->render(1));

        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');
        $this->assertSame([], $this->section($resource, [
            OptionalIntegrations::XML_INCLUDE_TESTIMONIALS => '0',
            OptionalIntegrations::XML_INCLUDE_FAQS => '0',
            OptionalIntegrations::XML_INCLUDE_DYNAMIC_FORMS => '0',
        ])->render(1));
    }

    public function testMissingTablesRenderNothing(): void
    {
        $this->assertSame([], $this->section($this->resource($this->connection()))->render(1));
    }
}
