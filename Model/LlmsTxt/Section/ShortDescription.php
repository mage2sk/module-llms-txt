<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt\Section;

use Magento\Catalog\Api\Data\ProductInterface;

class ShortDescription
{
    public const MAX_LENGTH = 180;

    public function resolve(ProductInterface $product, string $fallbackCategory = ''): string
    {
        foreach ([
            (string) ($product->getData('short_description') ?? ''),
            (string) ($product->getData('meta_description') ?? ''),
            $this->firstSentence((string) ($product->getData('description') ?? '')),
        ] as $candidate) {
            $clean = $this->sanitize($candidate);
            if ($clean !== '') {
                return $this->clamp($clean);
            }
        }

        $name = trim((string) $product->getName());
        if ($name === '') {
            return '';
        }
        $auto = $fallbackCategory !== ''
            ? sprintf('%s - available in the %s category.', $name, $fallbackCategory)
            : sprintf('%s - available from our catalog.', $name);

        return $this->clamp($auto);
    }

    private function firstSentence(string $source): string
    {
        $clean = $this->sanitize($source);
        if ($clean === '') {
            return '';
        }

        if (preg_match('/^(.{20,}?[.!?])\s/u', $clean, $m) === 1) {
            return trim($m[1]);
        }
        return $clean;
    }

    private function sanitize(string $source): string
    {
        if ($source === '') {
            return '';
        }

        $source = (string) preg_replace('/<br\s*\/?>|<\/(p|div|li|tr|h[1-6])>/i', ' ', $source);
        $text = strip_tags($source);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    private function clamp(string $text): string
    {
        if (mb_strlen($text, 'UTF-8') <= self::MAX_LENGTH) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::MAX_LENGTH - 1, 'UTF-8');
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > self::MAX_LENGTH * 0.6) {
            $cut = mb_substr($cut, 0, $lastSpace, 'UTF-8');
        }
        return rtrim($cut, " \t.,;:-") . '...';
    }
}
