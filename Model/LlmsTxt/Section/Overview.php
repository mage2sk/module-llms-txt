<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt\Section;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Overview
{
    public function renderHeader(
        int $storeId,
        string $title,
        string $summary,
        string $baseUrl
    ): array {
        $lines = [];
        $lines[] = '# ' . $title;
        $lines[] = '';
        $lines[] = '> ' . $summary;
        $lines[] = '';

        $lines[] = '## Store Overview';
        $lines[] = '';
        $lines[] = '- URL: ' . $baseUrl;
        $lines[] = '- Type: E-commerce catalog (Magento 2)';

        try {
            $store = $this->storeManager->getStore($storeId);
            $storeViewName = (string) $store->getName();
            if ($storeViewName !== '') {
                $lines[] = '- Store View: ' . $storeViewName;
            }
            $currency = (string) $store->getCurrentCurrencyCode();
            if ($currency !== '') {
                $lines[] = '- Currency: ' . $currency;
            }
        } catch (\Throwable) {
        }

        $locale = (string) $this->scopeConfig->getValue(
            'general/locale/code',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($locale !== '') {
            $lines[] = '- Language: ' . $locale;
        }

        $lines[] = '- Generated: ' . gmdate('Y-m-d H:i:s') . ' UTC';
        $lines[] = '';

        return $lines;
    }

    public function renderCompany(int $storeId): array
    {
        $info = [
            'Email'   => (string) $this->scopeConfig->getValue('trans_email/ident_general/email', ScopeInterface::SCOPE_STORE, $storeId),
            'Phone'   => (string) $this->scopeConfig->getValue('general/store_information/phone', ScopeInterface::SCOPE_STORE, $storeId),
            'Address' => (string) $this->scopeConfig->getValue('general/store_information/street_line1', ScopeInterface::SCOPE_STORE, $storeId),
            'City'    => (string) $this->scopeConfig->getValue('general/store_information/city', ScopeInterface::SCOPE_STORE, $storeId),
            'Zip'     => (string) $this->scopeConfig->getValue('general/store_information/postcode', ScopeInterface::SCOPE_STORE, $storeId),
            'Country' => (string) $this->scopeConfig->getValue('general/store_information/country_id', ScopeInterface::SCOPE_STORE, $storeId),
            'VAT'     => (string) $this->scopeConfig->getValue('general/store_information/merchant_vat_number', ScopeInterface::SCOPE_STORE, $storeId),
        ];
        $info = array_filter($info, static fn ($v) => $v !== '');
        if ($info === []) {
            return [];
        }

        $lines = ['## Company', ''];
        foreach ($info as $label => $value) {
            $lines[] = sprintf('- %s: %s', $label, $value);
        }
        $lines[] = '';
        return $lines;
    }

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LocaleResolver $localeResolver
    ) {
    }
}
