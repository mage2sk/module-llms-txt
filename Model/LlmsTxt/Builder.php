<?php
/**
 * Panth LLMs.txt — llms.txt body builder.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt;

use Magento\Catalog\Api\CategoryListInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Helper\Page as CmsPageHelper;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Model\Cache\Type as LlmsCache;
use Psr\Log\LoggerInterface;

/**
 * Builds the body of `/llms.txt` — the emerging standard proposed at
 * https://llmstxt.org/ that gives LLM crawlers a curated site map in
 * Markdown.
 *
 * v1.1 structure:
 *   1. H1 title (store/store-info name) and a one-sentence summary.
 *   2. Company info: name, email, phone, address (from
 *      general/store_information), rendered only when populated.
 *   3. Key Pages — top CMS pages, filtered to exclude non-public
 *      placeholders (`no-route`, the EU cookie-restriction notice, and
 *      any identifier listed in the admin exclude setting).
 *   4. Top Categories — up to N top-level categories sorted by position.
 *   5. Top Products — up to N most-recently-updated products.
 *   6. Sitemaps — references to robots / XML / llms-full sitemaps.
 *
 * The rendered Markdown is cached under the dedicated `panth_llms_txt`
 * cache type, keyed by store + schema version, and tagged with the
 * CATEGORY / PRODUCT / CMS_PAGE / STORE / CONFIG entity tags so Magento's
 * normal admin-save cache-clean calls invalidate the llms.txt output
 * automatically. No custom observer needed.
 */
class Builder
{
    public const XML_ENABLED        = 'panth_llms_txt/llms_txt/enabled';
    public const XML_SUMMARY        = 'panth_llms_txt/llms_txt/summary';
    public const XML_MAX_CATEGORIES = 'panth_llms_txt/llms_txt/max_categories';
    public const XML_MAX_PRODUCTS   = 'panth_llms_txt/llms_txt/max_products';
    public const XML_MAX_CMS        = 'panth_llms_txt/llms_txt/max_cms';
    public const XML_EXCLUDE_CMS    = 'panth_llms_txt/llms_txt/exclude_cms';

    /**
     * Schema version — bump to invalidate every cached llms.txt when the
     * output format changes without a manual cache flush.
     */
    private const SCHEMA_VERSION = 'v2';

    /**
     * Cache TTL (seconds) — default 1 hour. The cache is also tag-invalidated
     * on catalog / CMS / store / config saves, so this TTL is an upper bound
     * for passive regeneration.
     */
    private const CACHE_LIFETIME = 3600;

    /**
     * CMS identifiers that are never useful in an llms.txt index — Magento
     * sample data + default EU-compliance pages.
     */
    private const DEFAULT_EXCLUDED_CMS = [
        'no-route',
        'privacy-policy-cookie-restriction-mode',
        'enable-cookies',
    ];

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CategoryListInterface $categoryList,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly LoggerInterface $logger,
        private readonly AppEmulation $appEmulation,
        private readonly CmsPageHelper $cmsPageHelper,
        private readonly LlmsCache $cache,
        private readonly \Magento\Framework\Serialize\SerializerInterface $serializer
    ) {
    }

    /**
     * Render the Markdown body for /llms.txt, using the dedicated cache
     * when available. Tag set includes every entity that can change the
     * output so Magento's standard admin-save cache-clean calls will
     * invalidate this entry automatically.
     */
    public function build(int $storeId): string
    {
        $cacheKey = $this->cacheKey($storeId);
        $hit = $this->cache->load($cacheKey);
        if (is_string($hit) && $hit !== '') {
            return $hit;
        }

        $body = $this->renderInEmulatedStore($storeId);
        $this->cache->save($body, $cacheKey, $this->cacheTags(), self::CACHE_LIFETIME);

        return $body;
    }

    /**
     * Whether llms.txt generation is enabled for the given store.
     */
    public function isEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Return the cache tags this type emits. Matches the standard Magento
     * catalog / CMS / store / config tags so any of those tag-clean calls
     * will invalidate us without extra wiring.
     *
     * @return string[]
     */
    public function cacheTags(): array
    {
        return [
            LlmsCache::CACHE_TAG,
            \Magento\Catalog\Model\Category::CACHE_TAG,
            \Magento\Catalog\Model\Product::CACHE_TAG,
            \Magento\Cms\Model\Page::CACHE_TAG,
            \Magento\Store\Model\Store::CACHE_TAG,
            'config_scopes',
        ];
    }

    /**
     * Run the entire render inside an App\Emulation block so
     * `$category->getUrl()`, `$product->getProductUrl()` and the CMS
     * helper return URLs for the target store rather than leaking the
     * store context the request came in on.
     */
    private function renderInEmulatedStore(int $storeId): string
    {
        $this->appEmulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            return $this->renderBody($storeId);
        } finally {
            $this->appEmulation->stopEnvironmentEmulation();
        }
    }

    /**
     * The actual Markdown assembly. Always called under emulation.
     */
    private function renderBody(int $storeId): string
    {
        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (\Throwable) {
            return "# llms.txt\n\nStore not available.\n";
        }

        $base    = rtrim((string) $store->getBaseUrl(), '/') . '/';
        $title   = $this->resolveTitle($storeId, $store);
        $summary = $this->resolveSummary($storeId);

        $lines   = [];
        $lines[] = '# ' . $title;
        $lines[] = '';
        $lines[] = '> ' . $summary;
        $lines[] = '';
        $lines[] = '- URL: ' . $base;
        $lines[] = '- Generated: ' . gmdate('Y-m-d H:i:s') . ' UTC';
        $lines[] = '';

        $this->appendCompanyInfo($lines, $storeId);

        $lines[] = '## Key Pages';
        $lines[] = '';
        foreach ($this->loadTopCmsPages($storeId, $this->intConfig(self::XML_MAX_CMS, $storeId, 10)) as $row) {
            $lines[] = sprintf(
                '- [%s](%s)%s',
                $row['title'],
                $row['url'],
                $row['excerpt'] !== '' ? ': ' . $row['excerpt'] : ''
            );
        }
        $lines[] = '';

        $lines[] = '## Top Categories';
        $lines[] = '';
        foreach ($this->loadTopCategories($storeId, $this->intConfig(self::XML_MAX_CATEGORIES, $storeId, 20)) as $row) {
            $lines[] = sprintf('- [%s](%s)', $row['name'], $row['url']);
        }
        $lines[] = '';

        $lines[] = '## Top Products';
        $lines[] = '';
        foreach ($this->loadTopProducts($storeId, $this->intConfig(self::XML_MAX_PRODUCTS, $storeId, 50)) as $row) {
            $lines[] = sprintf(
                '- [%s](%s)%s',
                $row['name'],
                $row['url'],
                $row['sku'] !== '' ? ' (SKU ' . $row['sku'] . ')' : ''
            );
        }
        $lines[] = '';

        $lines[] = '## Sitemaps';
        $lines[] = '';
        $lines[] = '- ' . $base . 'sitemap.xml';
        $lines[] = '- ' . $base . 'robots.txt';
        $lines[] = '- ' . $base . 'llms-full.txt';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Prefer general/store_information/name (the merchant-facing brand
     * name) over the store's internal getName() which defaults to the
     * store-view label like "Default Store View".
     */
    private function resolveTitle(int $storeId, \Magento\Store\Api\Data\StoreInterface $store): string
    {
        $brand = (string) $this->scopeValue('general/store_information/name', $storeId);
        if ($brand !== '') {
            return $brand;
        }
        $name = (string) $store->getName();
        return $name !== '' ? $name : 'Store';
    }

    /**
     * Summary fallback chain: admin textarea → head default description →
     * boilerplate.
     */
    private function resolveSummary(int $storeId): string
    {
        $summary = (string) $this->scopeValue(self::XML_SUMMARY, $storeId);
        if ($summary !== '') {
            return $summary;
        }
        $head = (string) $this->scopeValue('design/head/default_description', $storeId);
        if ($head !== '') {
            return $head;
        }
        return 'Online store catalog, products and editorial content.';
    }

    /**
     * Append a "Company Info" block with the contact details the merchant
     * has populated under Stores → Configuration → General → Store Information.
     * Renders nothing when all fields are empty so the file stays lean.
     */
    private function appendCompanyInfo(array &$lines, int $storeId): void
    {
        $info = [
            'Email'     => (string) $this->scopeValue('trans_email/ident_general/email', $storeId),
            'Phone'     => (string) $this->scopeValue('general/store_information/phone', $storeId),
            'Address'   => (string) $this->scopeValue('general/store_information/street_line1', $storeId),
            'Address 2' => (string) $this->scopeValue('general/store_information/street_line2', $storeId),
            'City'      => (string) $this->scopeValue('general/store_information/city', $storeId),
            'Zip'       => (string) $this->scopeValue('general/store_information/postcode', $storeId),
            'Country'   => (string) $this->scopeValue('general/store_information/country_id', $storeId),
            'VAT'       => (string) $this->scopeValue('general/store_information/merchant_vat_number', $storeId),
        ];
        $info = array_filter($info, static fn ($v) => $v !== '');
        if ($info === []) {
            return;
        }

        $lines[] = '## Company Info';
        $lines[] = '';
        foreach ($info as $label => $value) {
            $lines[] = sprintf('- **%s:** %s', $label, $value);
        }
        $lines[] = '';
    }

    /**
     * @return array<int,array{title:string,url:string,excerpt:string}>
     */
    private function loadTopCmsPages(int $storeId, int $limit): array
    {
        $excluded = array_map(
            'trim',
            explode(',', (string) $this->scopeValue(self::XML_EXCLUDE_CMS, $storeId))
        );
        $excluded = array_filter(array_merge(self::DEFAULT_EXCLUDED_CMS, $excluded));

        try {
            $criteria = $this->searchCriteriaBuilder
                ->addFilter('is_active', 1)
                ->addFilter('store_id', [$storeId, 0], 'in')
                ->setPageSize($limit + count($excluded))
                ->create();
            $result = $this->pageRepository->getList($criteria);
        } catch (\Throwable $e) {
            $this->logger->warning('[panth_llms_txt] cms list failed: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($result->getItems() as $page) {
            $identifier = (string) $page->getIdentifier();
            if ($identifier === '' || in_array($identifier, $excluded, true)) {
                continue;
            }
            $url = $this->resolveCmsUrl((int) $page->getId(), $identifier, $storeId);
            if ($url === '') {
                continue;
            }
            $out[] = [
                'title'   => (string) $page->getTitle(),
                'url'     => $url,
                'excerpt' => trim((string) ($page->getMetaDescription() ?? '')),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Resolve a CMS page URL through Magento's helper so URL rewrites and
     * configured suffixes are respected. Falls back to base+identifier if
     * the helper fails.
     */
    private function resolveCmsUrl(int $pageId, string $identifier, int $storeId): string
    {
        if ($pageId > 0) {
            try {
                $url = (string) $this->cmsPageHelper->getPageUrl($pageId);
                if ($url !== '') {
                    return $url;
                }
            } catch (\Throwable) {
                // Fall through to base+identifier
            }
        }

        try {
            $base = rtrim((string) $this->storeManager->getStore($storeId)->getBaseUrl(), '/');
            return $base . '/' . ltrim($identifier, '/');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array<int,array{name:string,url:string}>
     */
    private function loadTopCategories(int $storeId, int $limit): array
    {
        try {
            $sortOrder = $this->sortOrderBuilder->setField('position')->setDirection('ASC')->create();
            $criteria  = $this->searchCriteriaBuilder
                ->addFilter('is_active', 1)
                ->addFilter('level', 2, 'gteq')
                ->addFilter('level', 3, 'lteq')
                ->addSortOrder($sortOrder)
                ->setPageSize($limit)
                ->create();
            $result = $this->categoryList->getList($criteria);
        } catch (\Throwable $e) {
            $this->logger->warning('[panth_llms_txt] category list failed: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($result->getItems() as $cat) {
            $url = method_exists($cat, 'getUrl') ? (string) $cat->getUrl() : '';
            if ($url === '') {
                continue;
            }
            $out[] = [
                'name' => (string) $cat->getName(),
                'url'  => $url,
            ];
        }
        return $out;
    }

    /**
     * @return array<int,array{name:string,url:string,sku:string}>
     */
    private function loadTopProducts(int $storeId, int $limit): array
    {
        try {
            $sortOrder = $this->sortOrderBuilder->setField('updated_at')->setDirection('DESC')->create();
            $criteria  = $this->searchCriteriaBuilder
                ->addFilter('status', 1)
                ->addFilter('visibility', 1, 'neq')
                ->addSortOrder($sortOrder)
                ->setPageSize($limit)
                ->create();
            $result = $this->productRepository->getList($criteria);
        } catch (\Throwable $e) {
            $this->logger->warning('[panth_llms_txt] product list failed: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($result->getItems() as $product) {
            $url = method_exists($product, 'getProductUrl') ? (string) $product->getProductUrl() : '';
            if ($url === '') {
                continue;
            }
            $out[] = [
                'name' => (string) $product->getName(),
                'url'  => $url,
                'sku'  => (string) $product->getSku(),
            ];
        }
        return $out;
    }

    private function cacheKey(int $storeId): string
    {
        return sprintf('panth_llms_txt_%s_store_%d', self::SCHEMA_VERSION, $storeId);
    }

    private function scopeValue(string $path, int $storeId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function intConfig(string $path, int $storeId, int $default): int
    {
        $val = $this->scopeValue($path, $storeId);
        return ($val === null || $val === '') ? $default : max(1, (int) $val);
    }
}
