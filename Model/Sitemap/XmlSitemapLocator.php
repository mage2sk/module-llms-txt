<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\Sitemap;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class XmlSitemapLocator
{
    public const MODULE = 'Panth_XmlSitemap';

    public const XML_ENABLED = 'panth_xml_sitemap/general/enabled';

    public const XML_INDEX_FILENAME = 'panth_xml_sitemap/generation/index_filename';

    private const PROFILE_TABLE = 'panth_seo_sitemap_profile';

    private const LEGACY_OUTPUT_PATH = 'xmlsitemap/{store_code}/';

    private const FILENAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]*(?:\.[A-Za-z0-9_-]+)*\.xml$/';

    private const SEGMENT_PATTERN = '/^[A-Za-z0-9._-]+$/';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ModuleManager $moduleManager,
        private readonly Filesystem $filesystem
    ) {
    }

    public function getSitemapUrls(int $storeId): array
    {
        if (!$this->moduleManager->isEnabled(self::MODULE) || !$this->isEnabled($storeId)) {
            return [];
        }
        try {
            $store = $this->storeManager->getStore($storeId);
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName(self::PROFILE_TABLE);
            if (!$connection->isTableExists($table)) {
                return [];
            }
            $columns = ['output_path'];
            if ($connection->tableColumnExists($table, 'sitemap_filename')) {
                $columns[] = 'sitemap_filename';
            }
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($table, $columns)
                    ->where('store_id = ?', (int) $store->getId())
                    ->where('is_active = ?', 1)
                    ->order('profile_id ASC')
            );
            $webBase = rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/');
            $pub = $this->filesystem->getDirectoryRead(DirectoryList::PUB);
        } catch (\Throwable) {
            return [];
        }

        $fallback = $this->fallbackFilename($storeId);
        $urls = [];
        foreach ($rows as $row) {
            $relative = $this->relativeFile(
                (string) ($row['output_path'] ?? ''),
                (string) ($row['sitemap_filename'] ?? ''),
                $fallback,
                (string) $store->getCode()
            );
            if ($relative === null) {
                continue;
            }
            try {
                if (!$pub->isFile($relative)) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }
            $urls[] = $webBase . '/' . $relative;
        }
        return array_values(array_unique($urls));
    }

    public function relativeFile(string $outputPath, string $filename, string $fallback, string $storeCode): ?string
    {
        $path = trim($outputPath);
        if ($path === '') {
            $path = self::LEGACY_OUTPUT_PATH;
        }
        $path = strtr($path, ['{store_code}' => $storeCode]);
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..' || preg_match(self::SEGMENT_PATTERN, $segment) !== 1) {
                return null;
            }
            $segments[] = $segment;
        }
        $filename = trim($filename);
        if ($filename === '' || preg_match(self::FILENAME_PATTERN, $filename) !== 1) {
            $filename = $fallback;
        }
        return ($segments !== [] ? implode('/', $segments) . '/' : '') . $filename;
    }

    private function isEnabled(int $storeId): bool
    {
        $value = $this->scopeConfig->getValue(self::XML_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
        return $value === null || $value === '' || (bool) (int) $value;
    }

    private function fallbackFilename(int $storeId): string
    {
        $name = trim((string) $this->scopeConfig->getValue(self::XML_INDEX_FILENAME, ScopeInterface::SCOPE_STORE, $storeId));
        return preg_match(self::FILENAME_PATTERN, $name) === 1 ? $name : 'sitemap.xml';
    }
}
