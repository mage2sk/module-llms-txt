<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Setup\Patch\Data;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Setup\Patch\Data\AddLlmsJsonUrlRewrite;
use Panth\LlmsTxt\Setup\Patch\Data\InstallLlmsFullUrlRewrite;
use Panth\LlmsTxt\Setup\Patch\Data\MigrateConfigPaths;
use PHPUnit\Framework\TestCase;

class PatchesTest extends TestCase
{
    private array $deleted = [];
    private array $inserted = [];
    private array $updates = [];
    private array $setupCalls = [];
    private array $configRows = [];

    private function dataSetup(bool $failWrites = false): ModuleDataSetupInterface
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->createStub(\Magento\Framework\DB\Select::class));
        $connection->method('fetchAll')->willReturnCallback(fn () => $this->configRows);
        $connection->method('delete')->willReturnCallback(function ($table, $where) use ($failWrites) {
            if ($failWrites) {
                throw new \RuntimeException('locked');
            }
            $this->deleted[] = [$table, $where];
            return 1;
        });
        $connection->method('insert')->willReturnCallback(function ($table, $row) use ($failWrites) {
            if ($failWrites) {
                throw new \RuntimeException('dup');
            }
            $this->inserted[] = [$table, $row];
            return 1;
        });
        $connection->method('quote')->willReturnCallback(static fn ($v) => "'" . $v . "'");
        $connection->method('quoteInto')->willReturnCallback(
            static fn ($text, $v) => str_replace('?', "'" . $v . "'", $text)
        );
        $connection->method('update')->willReturnCallback(function ($table, $bind, $where) {
            $this->updates[] = [$table, $bind, $where];
            return 2;
        });

        $setup = $this->createStub(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnCallback(static fn (string $t) => 'pfx_' . $t);
        $setup->method('startSetup')->willReturnCallback(function () use ($setup) {
            $this->setupCalls[] = 'start';
            return $setup;
        });
        $setup->method('endSetup')->willReturnCallback(function () use ($setup) {
            $this->setupCalls[] = 'end';
            return $setup;
        });
        return $setup;
    }

    private function storeManager(array $ids): StoreManagerInterface
    {
        $stores = [];
        foreach ($ids as $id) {
            $store = $this->createStub(Store::class);
            $store->method('getId')->willReturn($id);
            $stores[] = $store;
        }
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);
        return $storeManager;
    }

    public function testInstallPatchRewritesAllThreeEndpointsPerFrontendStore(): void
    {
        $patch = new InstallLlmsFullUrlRewrite($this->dataSetup(), $this->storeManager([0, 1, 2]));

        $this->assertSame($patch, $patch->apply());

        $this->assertCount(6, $this->inserted);
        $this->assertSame(['start', 'end'], $this->setupCalls);
        $pairs = array_map(
            static fn ($i) => $i[1]['store_id'] . ':' . $i[1]['request_path'] . '=>' . $i[1]['target_path'],
            $this->inserted
        );
        $this->assertSame([
            '1:llms.txt=>panth_llms/llms/index', '1:llms-full.txt=>panth_llms/llms/full', '1:llms.json=>panth_llms/llms/json',
            '2:llms.txt=>panth_llms/llms/index', '2:llms-full.txt=>panth_llms/llms/full', '2:llms.json=>panth_llms/llms/json',
        ], $pairs);
        $this->assertSame('pfx_url_rewrite', $this->inserted[0][0]);
        $this->assertSame('custom', $this->inserted[0][1]['entity_type']);
        $this->assertSame(0, $this->inserted[0][1]['redirect_type']);
        $this->assertSame(
            ['request_path = ?' => 'llms.txt', 'store_id = ?' => 1, 'entity_type = ?' => 'custom'],
            $this->deleted[0][1]
        );
        $this->assertSame([], InstallLlmsFullUrlRewrite::getDependencies());
        $this->assertSame([], $patch->getAliases());
    }

    public function testJsonPatchAddsOnlyTheJsonRewriteAndDependsOnInstallPatch(): void
    {
        $patch = new AddLlmsJsonUrlRewrite($this->dataSetup(), $this->storeManager([3]));
        $patch->apply();

        $this->assertCount(1, $this->inserted);
        $this->assertSame('llms.json', $this->inserted[0][1]['request_path']);
        $this->assertSame('panth_llms/llms/json', $this->inserted[0][1]['target_path']);
        $this->assertSame(3, $this->inserted[0][1]['store_id']);
        $this->assertSame([InstallLlmsFullUrlRewrite::class], AddLlmsJsonUrlRewrite::getDependencies());
        $this->assertSame([], $patch->getAliases());
    }

    public function testWriteErrorsAreSwallowedAndSetupIsClosed(): void
    {
        (new InstallLlmsFullUrlRewrite($this->dataSetup(true), $this->storeManager([1])))->apply();
        (new AddLlmsJsonUrlRewrite($this->dataSetup(true), $this->storeManager([1])))->apply();

        $this->assertSame([], $this->inserted);
        $this->assertSame(['start', 'end', 'start', 'end'], $this->setupCalls);
    }

    public function testConfigPathsAreRenamedFromTheLegacyPrefix(): void
    {
        $patch = new MigrateConfigPaths($this->dataSetup());

        $this->assertSame($patch, $patch->apply());

        $this->assertCount(1, $this->updates);
        [$table, $bind, $where] = $this->updates[0];
        $this->assertSame('pfx_core_config_data', $table);
        $this->assertInstanceOf(\Zend_Db_Expr::class, $bind['path']);
        $this->assertSame("REPLACE(path, 'panth_seo/llms_txt/', 'panth_llms_txt/llms_txt/')", (string) $bind['path']);
        $this->assertSame("path LIKE 'panth_seo/llms_txt/%'", $where);
        $this->assertSame([], MigrateConfigPaths::getDependencies());
        $this->assertSame([], $patch->getAliases());
        $this->assertSame([], $this->deleted);
    }

    public function testLegacyRowsThatWouldDuplicateAnExistingPathAreDroppedBeforeRename(): void
    {
        $this->configRows = [
            ['config_id' => 1, 'scope' => 'default', 'scope_id' => 0, 'path' => 'panth_seo/llms_txt/enabled'],
            ['config_id' => 2, 'scope' => 'default', 'scope_id' => 0, 'path' => 'panth_llms_txt/llms_txt/enabled'],
            ['config_id' => 3, 'scope' => 'stores', 'scope_id' => 1, 'path' => 'panth_seo/llms_txt/enabled'],
            ['config_id' => 4, 'scope' => 'default', 'scope_id' => 0, 'path' => 'panth_seo/llms_txt/title'],
        ];

        (new MigrateConfigPaths($this->dataSetup()))->apply();

        $this->assertSame([['pfx_core_config_data', ['config_id IN (?)' => [1]]]], $this->deleted);
        $this->assertCount(1, $this->updates);
    }
}
