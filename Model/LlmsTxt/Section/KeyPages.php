<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt\Section;

use Magento\Cms\Helper\Page as CmsPageHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Model\LlmsTxt\Cms\ActivePages;

class KeyPages implements SectionInterface
{
    public const XML_MAX_CMS     = 'panth_llms_txt/llms_txt/max_cms';
    public const XML_EXCLUDE_CMS = 'panth_llms_txt/llms_txt/exclude_cms';

    private const DEFAULT_MAX = 100;

    private const BAKED_IN_EXCLUDES = [
        'no-route',
        'privacy-policy-cookie-restriction-mode',
        'enable-cookies',
    ];

    public function __construct(
        private readonly ActivePages $activePages,
        private readonly CmsPageHelper $cmsPageHelper,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function render(int $storeId): array
    {
        $limit = (int) ($this->scopeConfig->getValue(
            self::XML_MAX_CMS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: self::DEFAULT_MAX);
        $limit = max(1, $limit);

        $extraExcluded = array_filter(array_map(
            'trim',
            explode(',', (string) $this->scopeConfig->getValue(
                self::XML_EXCLUDE_CMS,
                ScopeInterface::SCOPE_STORE,
                $storeId
            ))
        ));
        $excluded = array_merge(self::BAKED_IN_EXCLUDES, $extraExcluded);

        $pages = $this->activePages->getNewestFirst($storeId, $limit, $excluded, 'llms.txt');

        $base = '';
        try {
            $base = rtrim((string) $this->storeManager->getStore($storeId)->getBaseUrl(), '/');
        } catch (\Throwable) {
        }

        $items = [];
        foreach ($pages as $page) {
            $identifier = (string) $page->getIdentifier();
            $title = trim((string) $page->getTitle());

            $url = '';
            try {
                $url = (string) $this->cmsPageHelper->getPageUrl((int) $page->getId());
            } catch (\Throwable) {
            }
            if ($url === '' && $base !== '') {
                $url = $base . '/' . ltrim($identifier, '/');
            }
            if ($url === '') {
                continue;
            }

            $excerpt = trim((string) ($page->getMetaDescription() ?? ''));
            $line    = sprintf('- [%s](%s)', $title, $url);
            if ($excerpt !== '') {
                $line .= ': ' . $excerpt;
            }
            $items[] = $line;
        }

        if ($items === []) {
            return [];
        }

        array_unshift($items, '## Key Pages', '');
        $items[] = '';
        return $items;
    }
}
