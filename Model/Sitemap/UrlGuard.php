<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\Sitemap;

use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class UrlGuard
{
    private ?array $storeHosts = null;

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getStoreHosts(): array
    {
        if ($this->storeHosts !== null) {
            return $this->storeHosts;
        }
        $hosts = [];
        try {
            foreach ($this->storeManager->getStores(true) as $store) {
                foreach ([UrlInterface::URL_TYPE_WEB, UrlInterface::URL_TYPE_LINK] as $type) {
                    foreach ([false, true] as $secure) {
                        $host = parse_url((string) $store->getBaseUrl($type, $secure), PHP_URL_HOST);
                        if (is_string($host) && $host !== '') {
                            $hosts[strtolower($host)] = true;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->info('[panth_llms_txt] could not resolve store hosts: ' . $e->getMessage());
        }
        return $this->storeHosts = $hosts;
    }

    public function isStoreHost(string $host): bool
    {
        return isset($this->getStoreHosts()[strtolower($host)]);
    }

    public function validate(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return 'is not a valid URL';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return 'must use http or https';
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return 'has no host';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'must not contain credentials';
        }
        if ($this->isStoreHost($host)) {
            return null;
        }
        if ($this->getPublicAddresses($host) === []) {
            return 'must point to a store domain or a host that resolves only to public IP addresses';
        }
        return null;
    }

    public function getPublicAddresses(string $host): array
    {
        $host = trim(strtolower($host), '[]');
        if ($host === '') {
            return [];
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($host) ? [$host] : [];
        }
        $addresses = $this->resolveHost($host);
        if ($addresses === []) {
            return [];
        }
        foreach ($addresses as $ip) {
            if (!$this->isPublicIp($ip)) {
                return [];
            }
        }
        return $addresses;
    }

    public function resolveHost(string $host): array
    {
        $addresses = [];
        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            $addresses = $v4;
        }
        try {
            $v6 = dns_get_record($host, DNS_AAAA);
        } catch (\Throwable) {
            $v6 = false;
        }
        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (!empty($record['ipv6'])) {
                    $addresses[] = (string) $record['ipv6'];
                }
            }
        }
        return array_values(array_unique($addresses));
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
