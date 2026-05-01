<?php
/**
 * Panth LLMs.txt — sitemap entry value object.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\Sitemap;

use Panth\LlmsTxt\Api\Data\SitemapEntryInterface;

/**
 * Immutable carrier for one parsed `<url>` node.
 */
final class Entry implements SitemapEntryInterface
{
    public function __construct(
        private readonly string $location,
        private readonly ?string $lastModified = null,
        private readonly ?string $changeFrequency = null,
        private readonly ?float $priority = null,
        private readonly string $source = ''
    ) {
    }

    public function getLocation(): string
    {
        return $this->location;
    }

    public function getLastModified(): ?string
    {
        return $this->lastModified;
    }

    public function getChangeFrequency(): ?string
    {
        return $this->changeFrequency;
    }

    public function getPriority(): ?float
    {
        return $this->priority;
    }

    public function getSource(): string
    {
        return $this->source;
    }
}
