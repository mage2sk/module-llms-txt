<?php
/**
 * Panth LLMs.txt — sitemap entry contract.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Api\Data;

/**
 * One row extracted from a `<url>` element of an XML sitemap.
 *
 * Modelled as a pure value contract so the parser can hand them to the
 * weighted ranker, the JSON builder and the Markdown sections without
 * any of those collaborators reaching into XML internals.
 */
interface SitemapEntryInterface
{
    public function getLocation(): string;

    public function getLastModified(): ?string;

    public function getChangeFrequency(): ?string;

    /**
     * 0.0 – 1.0 priority taken from the sitemap row, or null when the
     * source omitted the field.
     */
    public function getPriority(): ?float;

    /**
     * Origin sitemap URL — useful when a merchant maps several sitemaps
     * and wants to weight them differently.
     */
    public function getSource(): string;
}
