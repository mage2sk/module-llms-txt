<?php
/**
 * Panth LLMs.txt — Key Pages (CMS) section.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt\Section;

use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Helper\Page as CmsPageHelper;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders the "Key Pages" section — CMS pages active for the store,
 * filtered to drop Magento scaffolding / placeholders that have no value
 * to an LLM crawler (`no-route`, `privacy-policy-cookie-restriction-mode`,
 * `enable-cookies` plus merchant-authored additions).
 *
 * Each page resolves through `Magento\Cms\Helper\Page::getPageUrl` so
 * URL rewrites and suffixes are respected.
 */
class KeyPages implements SectionInterface
{
    public const XML_MAX_CMS     = 'panth_llms_txt/llms_txt/max_cms';
    public const XML_EXCLUDE_CMS = 'panth_llms_txt/llms_txt/exclude_cms';

    private const DEFAULT_MAX = 10;

    private const BAKED_IN_EXCLUDES = [
        'no-route',
        'privacy-policy-cookie-restriction-mode',
        'enable-cookies',
    ];

    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly CmsPageHelper $cmsPageHelper,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return string[]
     */
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

        try {
            $criteria = $this->searchCriteriaBuilder
                ->addFilter('is_active', 1)
                ->addFilter('store_id', [$storeId, 0], 'in')
                ->setPageSize($limit + count($excluded))
                ->create();
            $pages = $this->pageRepository->getList($criteria)->getItems();
        } catch (\Throwable $e) {
            $this->logger->warning('[panth_llms_txt] key pages list failed: ' . $e->getMessage());
            return [];
        }

        $base = '';
        try {
            $base = rtrim((string) $this->storeManager->getStore($storeId)->getBaseUrl(), '/');
        } catch (\Throwable) {
            // fall through with empty base — identifier-only URL will stay bare
        }

        $items = [];
        foreach ($pages as $page) {
            $identifier = (string) $page->getIdentifier();
            if ($identifier === '' || in_array($identifier, $excluded, true)) {
                continue;
            }
            $title = trim((string) $page->getTitle());
            if ($title === '') {
                continue;
            }

            $url = '';
            try {
                $url = (string) $this->cmsPageHelper->getPageUrl((int) $page->getId());
            } catch (\Throwable) {
                // fall through to manual URL
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

            if (count($items) >= $limit) {
                break;
            }
        }

        if ($items === []) {
            return [];
        }

        array_unshift($items, '## Key Pages', '');
        $items[] = '';
        return $items;
    }
}
