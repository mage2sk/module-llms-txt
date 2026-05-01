<?php
/**
 * Panth LLMs.txt — index entry contract.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Api\Data;

/**
 * A single ranked, labelled URL inside the AI index.
 *
 * Index entries are the unit the {@see \Panth\LlmsTxt\Api\WeightedRankerInterface}
 * sorts and the JSON builder serialises. They carry the merged signal
 * from sitemap data, admin weights and Magento entity context so
 * downstream consumers don't need to look anywhere else.
 */
interface IndexEntryInterface
{
    public const TYPE_HOMEPAGE   = 'homepage';
    public const TYPE_CATEGORY   = 'category';
    public const TYPE_COLLECTION = 'collection';
    public const TYPE_PRODUCT    = 'product';
    public const TYPE_CMS        = 'cms';
    public const TYPE_SITEMAP    = 'sitemap';
    public const TYPE_EXTERNAL   = 'external';

    public function getUrl(): string;

    public function getLabel(): string;

    /**
     * One of the TYPE_* constants.
     */
    public function getType(): string;

    /**
     * Final 0.0 – 1.0 score after combining sitemap priority, admin
     * weighting and business heuristics. Higher is more important.
     */
    public function getScore(): float;

    /**
     * Optional one-line summary suitable for placement under the link
     * in Markdown output or as a `summary` field in JSON.
     */
    public function getSummary(): string;

    /**
     * @return array<string,mixed> additional flat metadata for JSON output
     */
    public function getMetadata(): array;
}
