<?php
/**
 * Panth LLMs.txt — migrate legacy config paths.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Migrates core_config_data rows from legacy `panth_seo/llms_txt/*`
 * paths to the new module-owned `panth_llms_txt/llms_txt/*` paths.
 */
class MigrateConfigPaths implements DataPatchInterface
{
    private const LEGACY_PREFIX = 'panth_seo/llms_txt/';
    private const NEW_PREFIX    = 'panth_llms_txt/llms_txt/';

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    /**
     * @return self
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');

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

    /**
     * @return array<int,string>
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return array<int,string>
     */
    public function getAliases(): array
    {
        return [];
    }
}
