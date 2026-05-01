<?php
/**
 * Panth LLMs.txt — /llms.json controller.
 *
 * @copyright Copyright (c) Panth
 */
declare(strict_types=1);

namespace Panth\LlmsTxt\Controller\Llms;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\LlmsTxt\Model\LlmsTxt\JsonBuilder;

/**
 * Serves the structured JSON variant of the AI index at `/llms.json`.
 *
 * The JSON shape is documented in {@see JsonBuilder} — schema-stable
 * v1 with sections, sitemap entries, store metadata and a generation
 * timestamp. Useful for headless retrieval pipelines that prefer
 * machine-readable input over Markdown.
 */
class Json implements HttpGetActionInterface
{
    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly JsonBuilder $jsonBuilder,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return ResponseInterface|ResultInterface
     */
    public function execute(): ResponseInterface|ResultInterface
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $result  = $this->rawFactory->create();

        $result->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $result->setHeader('X-Robots-Tag', 'noindex', true);
        $result->setHeader('X-Content-Type-Options', 'nosniff', true);

        if (!$this->jsonBuilder->isEnabled($storeId)) {
            $result->setHttpResponseCode(404);
            $result->setHeader('Cache-Control', 'no-store, max-age=0', true);
            $result->setContents('{"error":"llms.json is not enabled for this store"}');
            return $result;
        }

        $result->setHeader('Cache-Control', 'public, max-age=3600', true);
        $result->setHeader('Content-Disposition', 'inline; filename="llms.json"', true);

        $result->setContents($this->jsonBuilder->build($storeId));
        return $result;
    }
}
