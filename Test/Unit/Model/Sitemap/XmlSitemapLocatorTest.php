<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Model\Sitemap;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Model\Sitemap\XmlSitemapLocator;
use PHPUnit\Framework\TestCase;

class XmlSitemapLocatorTest extends TestCase
{
    private function locator(
        array $rows,
        array $files,
        array $config = [],
        bool $moduleOn = true,
        bool $tableExists = true,
        bool $filenameColumn = true
    ): XmlSitemapLocator {
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'order'] as $m) {
            $select->method($m)->willReturnSelf();
        }
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('isTableExists')->willReturn($tableExists);
        $connection->method('tableColumnExists')->willReturn($filenameColumn);
        $connection->method('fetchAll')->willReturn($rows);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(2);
        $store->method('getCode')->willReturn('luma');
        $store->method('getBaseUrl')->willReturn('https://luma.test/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(static fn(string $path) => $config[$path] ?? null);

        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturn($moduleOn);

        $pub = $this->createStub(ReadInterface::class);
        $pub->method('isFile')->willReturnCallback(static fn(string $p) => in_array($p, $files, true));
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturn($pub);

        return new XmlSitemapLocator($resource, $storeManager, $scope, $modules, $filesystem);
    }

    public function testReturnsTheConfiguredFileOfEachActiveProfileThatExists(): void
    {
        $rows = [
            ['output_path' => '/', 'sitemap_filename' => 'sitemap_luma.xml'],
            ['output_path' => 'seo/{store_code}/', 'sitemap_filename' => 'map.xml'],
            ['output_path' => '/', 'sitemap_filename' => 'missing.xml'],
            ['output_path' => '../etc', 'sitemap_filename' => 'env.xml'],
        ];
        $urls = $this->locator($rows, ['sitemap_luma.xml', 'seo/luma/map.xml', 'etc/env.xml'])->getSitemapUrls(2);
        $this->assertSame(['https://luma.test/sitemap_luma.xml', 'https://luma.test/seo/luma/map.xml'], $urls);
    }

    public function testLegacyProfilesUseTheIndexFilenameSetting(): void
    {
        $rows = [['output_path' => 'xmlsitemap/{store_code}/', 'sitemap_filename' => null], ['output_path' => '']];
        $config = [XmlSitemapLocator::XML_INDEX_FILENAME => 'index.xml'];
        $this->assertSame(
            ['https://luma.test/xmlsitemap/luma/index.xml'],
            $this->locator($rows, ['xmlsitemap/luma/index.xml'], $config, true, true, false)->getSitemapUrls(2)
        );
    }

    public function testNothingWhenDisabledMissingOrWithoutTable(): void
    {
        $rows = [['output_path' => '/', 'sitemap_filename' => 'sitemap.xml']];
        $files = ['sitemap.xml'];
        $this->assertSame([], $this->locator($rows, $files, [XmlSitemapLocator::XML_ENABLED => '0'])->getSitemapUrls(2));
        $this->assertSame([], $this->locator($rows, $files, [], false)->getSitemapUrls(2));
        $this->assertSame([], $this->locator($rows, $files, [], true, false)->getSitemapUrls(2));
        $this->assertSame(['https://luma.test/sitemap.xml'], $this->locator($rows, $files)->getSitemapUrls(2));
    }

    public function testRelativeFileRejectsUnsafeNames(): void
    {
        $locator = $this->locator([], []);
        $this->assertSame('sitemap.xml', $locator->relativeFile('/', '../x.xml', 'sitemap.xml', 'default'));
        $this->assertNull($locator->relativeFile('a/../b', 'x.xml', 'sitemap.xml', 'default'));
        $this->assertSame('xmlsitemap/default/s.xml', $locator->relativeFile('', 's.xml', 'sitemap.xml', 'default'));
    }
}
