<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\LlmsTxt;

use Panth\LlmsTxt\Model\LlmsTxt\Markdown;
use PHPUnit\Framework\TestCase;

class MarkdownTest extends TestCase
{
    public function testTextStripsTagsDecodesEntitiesAndCollapsesWhitespace(): void
    {
        $this->assertSame('Shipping & Returns Help', Markdown::text("Shipping &amp; Returns\n  Help <b>"));
        $this->assertSame('alert(1)', Markdown::text('<script>alert(1)</script>'));
        $this->assertSame('', Markdown::text('&lt;img src=x onerror=alert(1)&gt;'));
        $this->assertSame('5" Pocket - it\'s', Markdown::text('5&quot; Pocket - it&#039;s'));
    }

    public function testLinkTextEscapesBracketsAndBackslashes(): void
    {
        $this->assertSame('Tee \\[XL\\] a\\\\b', Markdown::linkText('Tee [XL] a\\b'));
    }

    public function testUrlEncodesCharactersThatBreakLinks(): void
    {
        $this->assertSame(
            'https://shop.example/a%20b%28c%29%3Cx%3E',
            Markdown::url(' https://shop.example/a b(c)<x> ')
        );
    }

    public function testLinkCombinesEscapedLabelAndUrl(): void
    {
        $this->assertSame(
            '[Sale \\[50%\\]](https://shop.example/sale%28now%29.html)',
            Markdown::link('<em>Sale [50%]</em>', 'https://shop.example/sale(now).html')
        );
    }
}
