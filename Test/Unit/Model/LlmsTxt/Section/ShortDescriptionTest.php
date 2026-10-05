<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\LlmsTxt\Section;

use Magento\Catalog\Model\Product;
use Panth\LlmsTxt\Model\LlmsTxt\Section\ShortDescription;
use PHPUnit\Framework\TestCase;

class ShortDescriptionTest extends TestCase
{
    private function product(array $data, string $name = 'Blue Mug'): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getData')->willReturnCallback(static fn ($key = '') => $data[$key] ?? null);
        $product->method('getName')->willReturn($name);
        return $product;
    }

    public function testShortDescriptionWinsAndHtmlIsFlattened(): void
    {
        $product = $this->product([
            'short_description' => '<p>Hand   made</p><p>stoneware &amp; glaze</p>',
            'meta_description'  => 'meta',
        ]);

        $this->assertSame('Hand made stoneware & glaze', (new ShortDescription())->resolve($product));
    }

    public function testMetaDescriptionIsUsedWhenShortDescriptionIsBlank(): void
    {
        $product = $this->product(['short_description' => '<p> </p>', 'meta_description' => 'Meta text']);

        $this->assertSame('Meta text', (new ShortDescription())->resolve($product));
    }

    public function testFirstSentenceOfDescriptionIsExtracted(): void
    {
        $product = $this->product([
            'description' => '<div>This mug keeps coffee hot for hours. Dishwasher safe. Gift boxed.</div>',
        ]);

        $this->assertSame('This mug keeps coffee hot for hours.', (new ShortDescription())->resolve($product));
    }

    public function testShortDescriptionWithoutSentenceBreakIsReturnedWhole(): void
    {
        $product = $this->product(['description' => 'Short. Text']);

        $this->assertSame('Short. Text', (new ShortDescription())->resolve($product));
    }

    public function testFallbackUsesNameAndOptionalCategory(): void
    {
        $resolver = new ShortDescription();

        $this->assertSame('Blue Mug - available in the Kitchen category.', $resolver->resolve($this->product([]), 'Kitchen'));
        $this->assertSame('Blue Mug - available from our catalog.', $resolver->resolve($this->product([])));
        $this->assertSame('', $resolver->resolve($this->product([], '  ')));
    }

    public function testLongTextIsClampedAtAWordBoundaryWithEllipsis(): void
    {
        $text = str_repeat('word ', 60);
        $result = (new ShortDescription())->resolve($this->product(['short_description' => $text]));

        $this->assertLessThanOrEqual(ShortDescription::MAX_LENGTH + 2, mb_strlen($result));
        $this->assertStringEndsWith('word...', $result);
    }

    public function testLongTextWithoutSpacesIsHardCut(): void
    {
        $text = str_repeat('x', 400);
        $result = (new ShortDescription())->resolve($this->product(['short_description' => $text]));

        $this->assertSame(str_repeat('x', ShortDescription::MAX_LENGTH - 1) . '...', $result);
    }
}
