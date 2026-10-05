<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt;

class Markdown
{
    public static function text(string $value): string
    {
        $text = strip_tags($value);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    public static function linkText(string $value): string
    {
        return strtr(self::text($value), ['\\' => '\\\\', '[' => '\\[', ']' => '\\]']);
    }

    public static function url(string $url): string
    {
        return strtr(trim($url), [' ' => '%20', '(' => '%28', ')' => '%29', '<' => '%3C', '>' => '%3E']);
    }

    public static function link(string $label, string $url): string
    {
        return sprintf('[%s](%s)', self::linkText($label), self::url($url));
    }
}
