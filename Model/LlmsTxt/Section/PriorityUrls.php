<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt\Section;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class PriorityUrls implements SectionInterface
{
    public const XML_PRIORITY_URLS = 'panth_llms_txt/llms_txt/priority_urls';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

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
        }

        $items = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

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
