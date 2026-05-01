<?php
/**
 * Panth LLMs.txt — Use Cases section.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt\Section;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders the "Use Cases" section — admin-authored buckets that map a
 * shopper intent ("daily wear", "bridal", "festive", "gift under
 * £50") to one or more category IDs.
 *
 * Stored as one bucket per line in
 * `panth_llms_txt/use_cases/buckets`. Format:
 *
 *     <Label> | <category_id_csv> [| <one-line summary>]
 *
 * Examples:
 *
 *     Daily Wear | 12,18,33
 *     Bridal | 41 | Hand-embroidered bridal lehengas and saris
 *     Festive | 14,27 | Diwali and wedding-season collections
 *
 * The summary column is optional; when omitted the section just lists
 * the categories under the label heading. The format is intentionally
 * shaped like a Markdown list so the output stays scannable for a
 * crawler:
 *
 *     ## Use Cases
 *
 *     ### Daily Wear
 *     > Hand-embroidered bridal lehengas and saris
 *     - [Cotton Tops](.../cotton-tops)
 *     - [Linen Pants](.../linen-pants)
 */
class UseCases implements SectionInterface
{
    public const XML_BUCKETS = 'panth_llms_txt/use_cases/buckets';

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int $storeId
     * @return string[]
     */
    public function render(int $storeId): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_BUCKETS, ScopeInterface::SCOPE_STORE, $storeId);
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $blocks = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $label = $parts[0] ?? '';
            $idsRaw = $parts[1] ?? '';
            $summary = $parts[2] ?? '';
            if ($label === '' || $idsRaw === '') {
                continue;
            }

            $ids = array_filter(array_map(
                static fn ($v) => (int) trim((string) $v),
                explode(',', $idsRaw)
            ));
            $links = $this->resolveCategoryLinks($storeId, $ids);
            if ($links === []) {
                continue;
            }

            $block = ['### ' . $label, ''];
            if ($summary !== '') {
                $block[] = '> ' . $summary;
                $block[] = '';
            }
            foreach ($links as $link) {
                $block[] = $link;
            }
            $block[] = '';
            $blocks[] = $block;
        }

        if ($blocks === []) {
            return [];
        }

        $out = ['## Use Cases', '', '> Curated shopper-intent groupings — useful when an AI assistant needs to map a customer query to a relevant set of categories.', ''];
        foreach ($blocks as $block) {
            foreach ($block as $line) {
                $out[] = $line;
            }
        }
        return $out;
    }

    /**
     * @param int[] $ids
     * @return string[] Markdown list lines
     */
    private function resolveCategoryLinks(int $storeId, array $ids): array
    {
        $lines = [];
        foreach ($ids as $id) {
            try {
                $category = $this->categoryRepository->get($id, $storeId);
            } catch (NoSuchEntityException) {
                continue;
            } catch (\Throwable $e) {
                $this->logger->info('[panth_llms_txt] use-case category lookup failed: ' . $e->getMessage());
                continue;
            }
            if (!$category->getIsActive()) {
                continue;
            }
            $name = trim((string) $category->getName());
            if ($name === '') {
                continue;
            }
            $url = '';
            try {
                $url = (string) $category->getUrl();
            } catch (\Throwable) {
                // emit unlinked rather than skipping
            }
            $lines[] = $url !== ''
                ? sprintf('- [%s](%s)', $name, $url)
                : sprintf('- %s', $name);
        }
        return $lines;
    }
}
