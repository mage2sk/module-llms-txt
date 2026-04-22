<?php
/**
 * Panth LLMs.txt — /llms-full.txt controller.
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
use Panth\LlmsTxt\Model\LlmsTxt\FullBuilder;

/**
 * Serves /panth_llms/llms/full as `llms-full.txt` (expanded LLM content).
 */
class Full implements HttpGetActionInterface
{
    /**
     * @param RawFactory $rawFactory
     * @param FullBuilder $fullBuilder
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly FullBuilder $fullBuilder,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return ResponseInterface|ResultInterface
     */
    public function execute(): ResponseInterface|ResultInterface
    {
        $storeId = (int) $this->storeManager->getStore()->getId();

        if (!$this->fullBuilder->isEnabled($storeId)) {
            $result = $this->rawFactory->create();
            $result->setHttpResponseCode(404);
            $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
            $result->setContents("# llms-full.txt\n\nExpanded LLM content is not enabled for this store.\n");
            return $result;
        }

        $body = $this->fullBuilder->build($storeId);
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $result->setHeader('X-Robots-Tag', 'noindex', true);
        $result->setContents($body);
        return $result;
    }
}
