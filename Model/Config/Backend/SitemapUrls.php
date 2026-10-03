<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Panth\LlmsTxt\Model\Sitemap\UrlGuard;

class SitemapUrls extends Value
{
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly UrlGuard $urlGuard,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function beforeSave()
    {
        $errors = [];
        foreach (preg_split('/\R/', (string) $this->getValue()) ?: [] as $line) {
            $url = trim($line);
            if ($url === '' || str_starts_with($url, '#')) {
                continue;
            }
            if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
                continue;
            }
            $error = $this->urlGuard->validate($url);
            if ($error !== null) {
                $errors[] = sprintf('%s %s', $url, $error);
            }
        }
        if ($errors !== []) {
            throw new LocalizedException(
                __('Sitemap URLs were not saved. %1', implode('; ', $errors))
            );
        }
        return parent::beforeSave();
    }
}
