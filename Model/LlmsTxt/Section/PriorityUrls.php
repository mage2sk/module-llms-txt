<?php
/**
 * Panth LLMs.txt — Priority URLs section.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt\Section;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Renders an admin-authored list of "if an AI crawler reads nothing else,
 * read these" URLs.
 *
 * Stored as one URL per line in the config textarea, with an optional
 * `Label | url` syntax so the merchant can prepend a human label:
 *
 *     Homepage | /
 *     Sale | /sale
 *     New Arrivals | /collections/new
 *
 * Plain URLs without a label get auto-labelled from the path segments.
 * Relative paths (starting with `/`) are resolved against the store's
 * base URL; absolute URLs are passed through as-is so merchants can
 * link external things like help centres or social profiles.
 */
class PriorityUrls implements SectionInterface
{
    public const XML_PRIORITY_URLS = 'panth_llms_txt/llms_txt/priority_urls';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return string[]
     */
    public function render(int $storeId): array
    {
        $raw = trim((string) $this->scopeConfig->getValue(
            self::XML_PRIORITY_URLS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
        if ($raw === '') {
            return [];
        }

        $baseUrl = '';
        try {
            $baseUrl = rtrim((string) $this->storeManager->getStore($storeId)->getBaseUrl(), '/');
        } catch (\Throwable) {
            // falling back to empty base means relative URLs skip through raw
        }

        $items = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // "Label | url" or just "url"
            $label = '';
            $url   = $line;
            if (str_contains($line, '|')) {
                [$label, $url] = array_map('trim', explode('|', $line, 2));
            }
            if ($url === '') {
                continue;
            }

            if (str_starts_with($url, '/')) {
                $url = $baseUrl . $url;
            } elseif (!preg_match('#^https?://#i', $url)) {
                // treat bare "about-us" style entries as relative paths too
                $url = $baseUrl . '/' . ltrim($url, '/');
            }

            if ($label === '') {
                $label = $this->guessLabel($url);
            }

            $items[] = sprintf('- [%s](%s)', $label, $url);
        }

        if ($items === []) {
            return [];
        }

        array_unshift($items, '## Priority URLs', '');
        $items[] = '';
        return $items;
    }

    /**
     * Best-effort label from a URL path: `/collections/new-arrivals`
     * becomes "New Arrivals".
     */
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
        return ucwords(trim($segment)) ?: $url;
    }
}
