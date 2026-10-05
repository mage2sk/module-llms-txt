<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\Ranker;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Panth\LlmsTxt\Api\Data\IndexEntryInterface;
use Panth\LlmsTxt\Api\WeightedRankerInterface;
use Panth\LlmsTxt\Model\Index\Entry;

class WeightedRanker implements WeightedRankerInterface
{
    public const XML_TYPE_WEIGHTS = 'panth_llms_txt/weighting/type_weights';
    public const XML_PINNED       = 'panth_llms_txt/weighting/pinned_urls';
    public const XML_MIN_SCORE    = 'panth_llms_txt/weighting/min_score';
    public const XML_MAX_ENTRIES  = 'panth_llms_txt/weighting/max_entries_per_section';

    private const DEFAULT_TYPE_WEIGHTS = [
        IndexEntryInterface::TYPE_HOMEPAGE   => 1.0,
        IndexEntryInterface::TYPE_COLLECTION => 0.85,
        IndexEntryInterface::TYPE_CATEGORY   => 0.75,
        IndexEntryInterface::TYPE_CMS        => 0.65,
        IndexEntryInterface::TYPE_PRODUCT    => 0.55,
        IndexEntryInterface::TYPE_SITEMAP    => 0.45,
        IndexEntryInterface::TYPE_EXTERNAL   => 0.40,
    ];

    private const DEFAULT_MIN_SCORE       = 0.20;
    private const DEFAULT_MAX_PER_SECTION = 200;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function rank(array $entries, int $storeId): array
    {
        if ($entries === []) {
            return [];
        }
        $typeWeights = $this->resolveTypeWeights($storeId);
        $pinned      = $this->resolvePinned($storeId);
        $minScore    = $this->floatConfig(self::XML_MIN_SCORE, $storeId, self::DEFAULT_MIN_SCORE);
        $cap         = $this->intConfig(self::XML_MAX_ENTRIES, $storeId, self::DEFAULT_MAX_PER_SECTION);

        $scored = [];
        foreach ($entries as $entry) {
            $score = $this->computeScore($entry, $typeWeights, $pinned);
            if ($score < $minScore) {
                continue;
            }
            if ($entry instanceof Entry) {
                $entry->setScore($score);
            }
            $scored[] = $entry;
        }

        usort(
            $scored,
            static fn (IndexEntryInterface $a, IndexEntryInterface $b): int =>
                $b->getScore() <=> $a->getScore()
        );

        if ($cap > 0 && count($scored) > $cap) {
            $scored = array_slice($scored, 0, $cap);
        }
        return $scored;
    }

    private function computeScore(
        IndexEntryInterface $entry,
        array $typeWeights,
        array $pinned
    ): float {
        $url  = $entry->getUrl();
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (isset($pinned[$url])) {
            return $this->clamp($pinned[$url]);
        }
        if ($path !== '' && isset($pinned[$path])) {
            return $this->clamp($pinned[$path]);
        }
        $bestPrefixLen   = -1;
        $bestPrefixScore = null;
        foreach ($pinned as $prefix => $score) {
            if (!str_ends_with($prefix, '*')) {
                continue;
            }
            $clean = rtrim($prefix, '*');
            $clean = rtrim($clean, '/') !== '' ? $clean : $clean;
            if ($clean === '') {
                continue;
            }

            $hit = str_starts_with($url, $clean)
                || ($path !== '' && str_starts_with($path, $clean));
            if ($hit && strlen($clean) > $bestPrefixLen) {
                $bestPrefixLen   = strlen($clean);
                $bestPrefixScore = $score;
            }
        }
        if ($bestPrefixScore !== null) {
            return $this->clamp($bestPrefixScore);
        }

        $base = $typeWeights[$entry->getType()] ?? 0.5;

        $sitemapPriority = (float) ($entry->getMetadata()['sitemap_priority'] ?? 0.0);
        if ($sitemapPriority > 0.0) {
            $base += min(0.15, ($sitemapPriority - 0.5) * 0.30);
        }

        if (isset($entry->getMetadata()['bestseller_rank'])) {
            $rank = (int) $entry->getMetadata()['bestseller_rank'];
            if ($rank > 0) {
                $base += max(0.0, 0.10 - (($rank - 1) * 0.005));
            }
        }

        if (!empty($entry->getMetadata()['featured'])) {
            $base += 0.05;
        }

        return $this->clamp($base);
    }

    private function resolveTypeWeights(int $storeId): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_TYPE_WEIGHTS, ScopeInterface::SCOPE_STORE, $storeId);
        $resolved = self::DEFAULT_TYPE_WEIGHTS;
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$code, $weight] = array_map('trim', explode('=', $line, 2));
            if ($code === '') {
                continue;
            }
            $resolved[$code] = $this->clamp((float) $weight);
        }
        return $resolved;
    }

    private function resolvePinned(int $storeId): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_PINNED, ScopeInterface::SCOPE_STORE, $storeId);
        $resolved = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$urlOrPrefix, $score] = array_map('trim', explode('=', $line, 2));
            if ($urlOrPrefix === '') {
                continue;
            }
            $resolved[$urlOrPrefix] = $this->clamp((float) $score);
        }
        return $resolved;
    }

    private function clamp(float $score): float
    {
        if ($score < 0.0) {
            return 0.0;
        }
        if ($score > 1.0) {
            return 1.0;
        }
        return $score;
    }

    private function floatConfig(string $path, int $storeId, float $default): float
    {
        $raw = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($raw === null || $raw === '') {
            return $default;
        }
        return (float) $raw;
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
