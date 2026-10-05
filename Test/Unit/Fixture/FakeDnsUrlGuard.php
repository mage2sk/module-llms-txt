<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Fixture;

use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Model\Sitemap\UrlGuard;
use Psr\Log\LoggerInterface;

/**
 * UrlGuard with an in-memory resolver so tests never hit real DNS.
 */
class FakeDnsUrlGuard extends UrlGuard
{
    /** @var callable */
    private $resolver;

    /** @var string[] */
    public array $resolved = [];

    public function __construct(
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        callable $resolver
    ) {
        parent::__construct($storeManager, $logger);
        $this->resolver = $resolver;
    }

    public function resolveHost(string $host): array
    {
        $this->resolved[] = $host;
        return ($this->resolver)($host);
    }
}
