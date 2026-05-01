<?php
/**
 * Panth LLMs.txt — section provider contract.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Api;

use Panth\LlmsTxt\Api\Data\IndexEntryInterface;

/**
 * Pluggable section provider — third-party modules implement this
 * interface and add themselves to the `Panth\LlmsTxt\Model\LlmsTxt\SectionPool`
 * via di.xml to inject custom data sources (loyalty programmes,
 * external APIs, headless CMS, etc.) into the AI index.
 *
 * Each provider returns a heading + summary + ordered list of entries.
 * The orchestrator turns that into Markdown for txt output and into a
 * JSON object for `/llms.json`.
 */
interface SectionProviderInterface
{
    /**
     * Stable machine identifier used as the JSON key.
     */
    public function getCode(): string;

    /**
     * Human-readable section heading rendered as `## {label}`.
     */
    public function getLabel(int $storeId): string;

    /**
     * 2 – 3 line summary describing what this section contains.
     */
    public function getSummary(int $storeId): string;

    /**
     * The actual entries — already filtered to the supplied store
     * scope. Return [] to signal "skip this section".
     *
     * @return IndexEntryInterface[]
     */
    public function getEntries(int $storeId): array;

    /**
     * Lower numbers render first.
     */
    public function getSortOrder(): int;
}
