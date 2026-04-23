<?php
/**
 * Panth LLMs.txt — Section interface.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt\Section;

/**
 * Contract for a single rendered block of /llms.txt or /llms-full.txt.
 *
 * Implementors emit an ordered list of Markdown lines for the supplied
 * store context. Returning an empty array is the polite way to opt out
 * (e.g. when the feature flag is off or the data source is empty) — the
 * orchestrator drops the section header + trailing blank line in that
 * case so the file doesn't carry empty section markers.
 */
interface SectionInterface
{
    /**
     * Render the section's Markdown body for the given store.
     *
     * @param int $storeId
     * @return string[] Ordered list of lines, WITHOUT trailing newline characters.
     *                  Return [] to signal "skip this section".
     */
    public function render(int $storeId): array;
}
