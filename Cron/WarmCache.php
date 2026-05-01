<?php
/**
 * Panth LLMs.txt — cache warmer cron.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Api\SitemapFetcherInterface;
use Panth\LlmsTxt\Model\LlmsTxt\Builder;
use Panth\LlmsTxt\Model\LlmsTxt\FullBuilder;
use Panth\LlmsTxt\Model\LlmsTxt\JsonBuilder;
use Psr\Log\LoggerInterface;

/**
 * Pre-warms the three AI index caches (`llms.txt`, `llms-full.txt`,
 * `llms.json`) plus the dependent sitemap parse cache for every
 * enabled store view.
 *
 * Runs nightly at 02:30 by default — see etc/crontab.xml. The crontab
 * group resolves through Magento's standard cron scheduling so this
 * runs on the same worker the merchant already monitors.
 *
 * If cron is disabled or the merchant turns the feature off via the
 * `panth_llms_txt/cron/enabled` flag, the job is a no-op.
 */
class WarmCache
{
    public const XML_ENABLED = 'panth_llms_txt/cron/enabled';

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Builder $builder,
        private readonly FullBuilder $fullBuilder,
        private readonly JsonBuilder $jsonBuilder,
        private readonly SitemapFetcherInterface $sitemapFetcher,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $stores = [];
        try {
            $stores = $this->storeManager->getStores(false);
        } catch (\Throwable $e) {
            $this->logger->warning('[panth_llms_txt] cron store list failed: ' . $e->getMessage());
            return;
        }
        foreach ($stores as $store) {
            $storeId = (int) $store->getId();
            if ($storeId <= 0) {
                continue;
            }
            if (!$this->flag(self::XML_ENABLED, $storeId, true)) {
                continue;
            }

            // Drop the previous caches first so the rebuild lands fresh data.
            try {
                if ($this->sitemapFetcher instanceof \Panth\LlmsTxt\Model\Sitemap\Fetcher) {
                    $this->sitemapFetcher->clearCache($storeId);
                }
            } catch (\Throwable $e) {
                $this->logger->info('[panth_llms_txt] cron sitemap clear failed: ' . $e->getMessage());
            }

            try {
                if ($this->builder->isEnabled($storeId)) {
                    $this->builder->build($storeId);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[panth_llms_txt] cron llms.txt warm failed for store ' . $storeId . ': ' . $e->getMessage());
            }

            try {
                if ($this->fullBuilder->isEnabled($storeId)) {
                    $this->fullBuilder->build($storeId);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[panth_llms_txt] cron llms-full.txt warm failed for store ' . $storeId . ': ' . $e->getMessage());
            }

            try {
                if ($this->jsonBuilder->isEnabled($storeId)) {
                    $this->jsonBuilder->build($storeId);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[panth_llms_txt] cron llms.json warm failed for store ' . $storeId . ': ' . $e->getMessage());
            }
        }
    }

    private function flag(string $path, int $storeId, bool $default): bool
    {
        $raw = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($raw === null || $raw === '') {
            return $default;
        }
        return (bool) (int) $raw;
    }
}
