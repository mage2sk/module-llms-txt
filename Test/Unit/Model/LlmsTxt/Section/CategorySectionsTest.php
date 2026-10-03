<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\LlmsTxt\Section;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\LlmsTxt\Model\LlmsTxt\Section\Collections;
use Panth\LlmsTxt\Model\LlmsTxt\Section\UseCases;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Collections and UseCases both resolve configured category ids to links.
 */
class CategorySectionsTest extends TestCase
{
    private function category(string $name, bool $active = true, ?string $url = null, bool $urlThrows = false): Category
    {
        $cat = $this->createStub(Category::class);
        $cat->method('getName')->willReturn($name);
        $cat->method('getIsActive')->willReturn($active);
        if ($urlThrows) {
            $cat->method('getUrl')->willThrowException(new \RuntimeException('no url'));
        } else {
            $cat->method('getUrl')->willReturn($url ?? 'https://s/' . strtolower($name) . '.html');
        }
        return $cat;
    }

    private function repository(): CategoryRepositoryInterface
    {
        $map = [
            3 => $this->category('Shoes'),
            4 => $this->category('Hidden', false),
            5 => $this->category('  '),
            6 => $this->category('Bags', true, null, true),
        ];
        $repo = $this->createStub(CategoryRepositoryInterface::class);
        $repo->method('get')->willReturnCallback(static function (int $id) use ($map) {
            if ($id === 99) {
                throw new \RuntimeException('db down');
            }
            if (!isset($map[$id])) {
                throw new NoSuchEntityException(__('missing'));
            }
            return $map[$id];
        });
        return $repo;
    }

    private function config(string $path, ?string $value): ScopeConfigInterface
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $p) => $p === $path ? $value : null
        );
        return $scopeConfig;
    }

    public function testCollectionsRenderActiveNamedCategoriesAndSkipMissingOnes(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with($this->stringContains('collections category lookup failed: db down'));

        $section = new Collections(
            $this->repository(),
            $this->config(Collections::XML_COLLECTIONS, '3, 4,5,6,7,99,abc'),
            $logger
        );

        $this->assertSame(
            ['## Collections', '', '- [Shoes](https://s/shoes.html)', '- Bags', ''],
            $section->render(1)
        );
    }

    public function testCollectionsWithNoIdsOrNoSurvivorsRenderNothing(): void
    {
        $logger = $this->createStub(LoggerInterface::class);

        $this->assertSame([], (new Collections($this->repository(), $this->config(Collections::XML_COLLECTIONS, ''), $logger))->render(1));
        $this->assertSame([], (new Collections($this->repository(), $this->config(Collections::XML_COLLECTIONS, '4,7'), $logger))->render(1));
    }

    public function testUseCasesRenderBucketsWithOptionalSummary(): void
    {
        $raw = "# comment\nGifts | 3,6 | Ideas for presents\nNo ids |  \n|3\nEmpty | 4,7\nFootwear|3";
        $section = new UseCases(
            $this->repository(),
            $this->config(UseCases::XML_BUCKETS, $raw),
            $this->createStub(LoggerInterface::class)
        );

        $lines = $section->render(1);

        $this->assertSame('## Use Cases', $lines[0]);
        $this->assertSame(
            [
                '### Gifts', '', '> Ideas for presents', '', '- [Shoes](https://s/shoes.html)', '- Bags', '',
                '### Footwear', '', '- [Shoes](https://s/shoes.html)', '',
            ],
            array_slice($lines, 4)
        );
    }

    public function testUseCasesLogUnexpectedLookupErrors(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with($this->stringContains('use-case category lookup failed'));

        $section = new UseCases($this->repository(), $this->config(UseCases::XML_BUCKETS, 'Broken|99'), $logger);

        $this->assertSame([], $section->render(1));
    }

    public function testUseCasesEmptyConfigRendersNothing(): void
    {
        $section = new UseCases(
            $this->repository(),
            $this->config(UseCases::XML_BUCKETS, "  \n"),
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame([], $section->render(1));
    }
}
