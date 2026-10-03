<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Model\LlmsTxt\Section;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class UseCases implements SectionInterface
{
    public const XML_BUCKETS = 'panth_llms_txt/use_cases/buckets';

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function render(int $storeId): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_BUCKETS, ScopeInterface::SCOPE_STORE, $storeId);
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $blocks = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $label = $parts[0] ?? '';
            $idsRaw = $parts[1] ?? '';
            $summary = $parts[2] ?? '';
            if ($label === '' || $idsRaw === '') {
                continue;
            }

            $ids = array_filter(array_map(
                static fn ($v) => (int) trim((string) $v),
                explode(',', $idsRaw)
            ));
            $links = $this->resolveCategoryLinks($storeId, $ids);
            if ($links === []) {
                continue;
            }

            $block = ['### ' . $label, ''];
            if ($summary !== '') {
                $block[] = '> ' . $summary;
                $block[] = '';
            }
            foreach ($links as $link) {
                $block[] = $link;
            }
            $block[] = '';
            $blocks[] = $block;
        }

        if ($blocks === []) {
            return [];
        }

        $out = ['## Use Cases', '', '> Curated shopper-intent groupings - useful when an AI assistant needs to map a customer query to a relevant set of categories.', ''];
        foreach ($blocks as $block) {
            foreach ($block as $line) {
                $out[] = $line;
            }
        }
        return $out;
    }

    private function resolveCategoryLinks(int $storeId, array $ids): array
    {
        $lines = [];
        foreach ($ids as $id) {
            try {
                $category = $this->categoryRepository->get($id, $storeId);
            } catch (NoSuchEntityException) {
                continue;
            } catch (\Throwable $e) {
                $this->logger->info('[panth_llms_txt] use-case category lookup failed: ' . $e->getMessage());
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
            $lines[] = $url !== ''
                ? sprintf('- [%s](%s)', $name, $url)
                : sprintf('- %s', $name);
        }
        return $lines;
    }
}
