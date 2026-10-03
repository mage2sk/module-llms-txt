<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt\Section;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class Collections implements SectionInterface
{
    public const XML_COLLECTIONS = 'panth_llms_txt/llms_txt/collections_categories';

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function render(int $storeId): array
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_COLLECTIONS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $ids = array_filter(array_map(
            static fn ($v) => (int) trim((string) $v),
            explode(',', $raw)
        ));
        if ($ids === []) {
            return [];
        }

        $items = [];
        foreach ($ids as $id) {
            try {
                $category = $this->categoryRepository->get($id, $storeId);
            } catch (NoSuchEntityException) {
                continue;
            } catch (\Throwable $e) {
                $this->logger->info('[panth_llms_txt] collections category lookup failed: ' . $e->getMessage());
                continue;
            }
            if (!$category->getIsActive()) {
                continue;
            }
            $name = trim((string) $category->getName());
            if ($name === '') {
                continue;
            }
            $url = '';
            try {
                $url = (string) $category->getUrl();
            } catch (\Throwable) {
            }
            $items[] = $url !== ''
                ? sprintf('- [%s](%s)', $name, $url)
                : sprintf('- %s', $name);
        }

        if ($items === []) {
            return [];
        }

        array_unshift($items, '## Collections', '');
        $items[] = '';
        return $items;
    }
}
