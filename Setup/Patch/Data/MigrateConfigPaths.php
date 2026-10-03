<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class MigrateConfigPaths implements DataPatchInterface
{
    private const LEGACY_PREFIX = 'panth_seo/llms_txt/';
    private const NEW_PREFIX    = 'panth_llms_txt/llms_txt/';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');

        $select = $connection->select();
        $select->from($table, ['config_id', 'scope', 'scope_id', 'path']);
        $select->where('path LIKE ?', self::LEGACY_PREFIX . '%');
        $select->orWhere('path LIKE ?', self::NEW_PREFIX . '%');

        $existing = [];
        $legacy = [];
        foreach ($connection->fetchAll($select) as $row) {
            $path = (string) $row['path'];
            if (strpos($path, self::NEW_PREFIX) === 0) {
                $existing[$row['scope'] . '|' . $row['scope_id'] . '|' . $path] = true;
            } elseif (strpos($path, self::LEGACY_PREFIX) === 0) {
                $legacy[] = $row;
            }
        }

        $duplicates = [];
        foreach ($legacy as $row) {
            $target = self::NEW_PREFIX . substr((string) $row['path'], strlen(self::LEGACY_PREFIX));
            if (isset($existing[$row['scope'] . '|' . $row['scope_id'] . '|' . $target])) {
                $duplicates[] = (int) $row['config_id'];
            }
        }

        if ($duplicates !== []) {
            $connection->delete($table, ['config_id IN (?)' => $duplicates]);
        }

        $connection->update(
            $table,
            [
                'path' => new \Zend_Db_Expr(
                    sprintf(
                        'REPLACE(path, %s, %s)',
                        $connection->quote(self::LEGACY_PREFIX),
                        $connection->quote(self::NEW_PREFIX)
                    )
                ),
            ],
            $connection->quoteInto('path LIKE ?', self::LEGACY_PREFIX . '%')
        );

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
