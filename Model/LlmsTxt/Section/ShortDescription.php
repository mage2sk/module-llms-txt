<?php
/**
 * Panth LLMs.txt — short-description resolver.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt\Section;

use Magento\Catalog\Api\Data\ProductInterface;

/**
 * Resolves a single-line, LLM-friendly description for a product.
 *
 * Fallback chain (first non-empty wins):
 *   1. `short_description` attribute
 *   2. `meta_description` attribute
 *   3. First sentence of the long `description` (capped at 160 chars)
 *   4. Auto-generated from "$name — $categoryName" when we still have nothing
 *
 * HTML is stripped, whitespace collapsed, entities decoded, length clamped.
 * Output never exceeds {@see self::MAX_LENGTH} characters so the final
 * Markdown stays under a reasonable token budget per product.
 */
class ShortDescription
{
    /**
     * Maximum length of the returned single-line description.
     */
    public const MAX_LENGTH = 180;

    /**
     * @param ProductInterface $product
     * @param string           $fallbackCategory Category name to plug into the
     *                                            auto-generated fallback.
     */
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

        // All real sources were empty — fall back to a deterministic sentence.
        $name = trim((string) $product->getName());
        if ($name === '') {
            return '';
        }
        $auto = $fallbackCategory !== ''
            ? sprintf('%s — available in the %s category.', $name, $fallbackCategory)
            : sprintf('%s — available from our catalog.', $name);

        return $this->clamp($auto);
    }

    /**
     * Return the first sentence of a long-form description, stripped of HTML.
     */
    private function firstSentence(string $source): string
    {
        $clean = $this->sanitize($source);
        if ($clean === '') {
            return '';
        }
        // Split on the first sentence terminator followed by whitespace or
        // end of string. Keeps us safe when the description is one paragraph.
        if (preg_match('/^(.{20,}?[.!?])\s/u', $clean, $m) === 1) {
            return trim($m[1]);
        }
        return $clean;
    }

    /**
     * Strip HTML + collapse whitespace + decode entities.
     */
    private function sanitize(string $source): string
    {
        if ($source === '') {
            return '';
        }
        // Replace block-level closers with spaces so running <p>'s don't jam.
        $source = (string) preg_replace('/<br\s*\/?>|<\/(p|div|li|tr|h[1-6])>/i', ' ', $source);
        $text = strip_tags($source);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    /**
     * Clamp the returned description to {@see self::MAX_LENGTH} with an
     * ellipsis suffix. Breaks at the nearest preceding word boundary so we
     * don't split mid-word.
     */
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
        return rtrim($cut, " \t.,;:-") . '…';
    }
}
