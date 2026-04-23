<?php
/**
 * Panth LLMs.txt — dedicated cache type.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\Cache;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

/**
 * A small dedicated cache type for /llms.txt and /llms-full.txt output.
 *
 * Pulling this into its own cache type (rather than piggy-backing on
 * `default` or `full_page`) means:
 *   - Merchants see it listed as `panth_llms_txt` under System → Cache
 *     Management, can flush it independently without nuking FPC.
 *   - Tags emitted by the builders (CATEGORY, PRODUCT, CMS_PAGE, STORE,
 *     CONFIG) are namespaced inside this type so they never collide with
 *     the same tag names used by the catalog FPC surface.
 *
 * Tag-scoped via the standard FrontendPool pattern so any save event
 * that fires a CATEGORY / PRODUCT / CMS_PAGE / STORE / CONFIG tag clean
 * (which Magento does automatically on admin save) invalidates the
 * cached llms.txt output. No custom observer required.
 */
class Type extends TagScope
{
    /**
     * Cache type code — must match the `id` declared in etc/cache.xml.
     */
    public const TYPE_IDENTIFIER = 'panth_llms_txt';

    /**
     * Cache tag shared by every entry this type stores.
     */
    public const CACHE_TAG = 'PANTH_LLMS_TXT';

    public function __construct(FrontendPool $cacheFrontendPool)
    {
        parent::__construct(
            $cacheFrontendPool->get(self::TYPE_IDENTIFIER),
            self::CACHE_TAG
        );
    }
}
