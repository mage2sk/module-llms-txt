<?php
/**
 * Panth LLMs.txt — weighted ranker contract.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Api;

use Panth\LlmsTxt\Api\Data\IndexEntryInterface;

/**
 * Combines sitemap priority, admin-authored weights and built-in
 * business heuristics into a single 0.0 – 1.0 score per entry.
 *
 * Sort order is determined by score DESC; entries below the configured
 * cut-off are dropped so the LLM output stays focused on signal-rich
 * URLs.
 */
interface WeightedRankerInterface
{
    /**
     * Score and sort the supplied entries for the given store.
     *
     * @param IndexEntryInterface[] $entries
     * @param int                   $storeId
     * @return IndexEntryInterface[] sorted, score-stamped, possibly truncated
     */
    public function rank(array $entries, int $storeId): array;
}
