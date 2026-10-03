<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt\Section;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Panth\LlmsTxt\Api\Data\IndexEntryInterface;
use Panth\LlmsTxt\Api\SitemapFetcherInterface;
use Panth\LlmsTxt\Api\WeightedRankerInterface;
use Panth\LlmsTxt\Model\Index\Entry as IndexEntry;

class Sitemap implements SectionInterface
{
    public const XML_ENABLED  = 'panth_llms_txt/sitemap/render_section';
    public const XML_MAX_ROWS = 'panth_llms_txt/sitemap/max_rendered';

    private const DEFAULT_MAX = 50;

    public function __construct(
        private readonly SitemapFetcherInterface $fetcher,
        private readonly WeightedRankerInterface $ranker,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function render(int $storeId): array
    {
        if (!$this->flag(self::XML_ENABLED, $storeId, true)) {
            return [];
        }

        $rows = $this->fetcher->fetchForStore($storeId);
        if ($rows === []) {
            return [];
        }

        $entries = [];
        foreach ($rows as $row) {
            $url = $row->getLocation();
            $entries[] = new IndexEntry(
                $url,
                $this->guessLabel($url),
                IndexEntryInterface::TYPE_SITEMAP,
                0.5,
                '',
                [
                    'sitemap_priority' => $row->getPriority() ?? 0.0,
                    'lastmod'          => $row->getLastModified(),
                ]
            );
        }

        $ranked = $this->ranker->rank($entries, $storeId);
        $max    = $this->intConfig(self::XML_MAX_ROWS, $storeId, self::DEFAULT_MAX);
        if ($max > 0) {
            $ranked = array_slice($ranked, 0, $max);
        }
        if ($ranked === []) {
            return [];
        }

        $lines = ['## Sitemap Highlights', '', '> The merchant\'s most important indexed URLs, ranked by combined sitemap priority and admin weighting.', ''];
        foreach ($ranked as $entry) {
            $lines[] = sprintf('- [%s](%s)', $entry->getLabel(), $entry->getUrl());
        }
        $lines[] = '';
        return $lines;
    }

    private function guessLabel(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = trim($path, '/');
        if ($path === '') {
            return 'Homepage';
        }
        $segment = basename($path);
        $segment = (string) preg_replace('/\.(html?|php)$/i', '', $segment);
        $segment = str_replace(['-', '_'], ' ', $segment);
        $label   = ucwords(trim($segment));
        return $label !== '' ? $label : $url;
    }

    private function flag(string $path, int $storeId, bool $default): bool
    {
        $raw = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($raw === null || $raw === '') {
            return $default;
        }
        return (bool) (int) $raw;
    }

    private function intConfig(string $path, int $storeId, int $default): int
    {
        $raw = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($raw === null || $raw === '') {
            return $default;
        }
        return max(0, (int) $raw);
    }
}
