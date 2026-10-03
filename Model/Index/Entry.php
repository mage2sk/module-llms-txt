<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\Index;

use Panth\LlmsTxt\Api\Data\IndexEntryInterface;

class Entry implements IndexEntryInterface
{
    private float $score;

    public function __construct(
        private readonly string $url,
        private readonly string $label,
        private readonly string $type,
        float $score = 0.5,
        private readonly string $summary = '',
        private readonly array $metadata = []
    ) {
        $this->score = $this->clampScore($score);
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getScore(): float
    {
        return $this->score;
    }

    public function setScore(float $score): void
    {
        $this->score = $this->clampScore($score);
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    private function clampScore(float $score): float
    {
        if ($score < 0.0) {
            return 0.0;
        }
        if ($score > 1.0) {
            return 1.0;
        }
        return $score;
    }
}
