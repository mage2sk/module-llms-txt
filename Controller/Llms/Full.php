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
 * Serves the expanded `/llms-full.txt` — same contract as `/llms.txt`
 * but with CMS page bodies + product descriptions + prices inline for
 * LLMs that ingest the longer document.
 */
class Full implements HttpGetActionInterface
{
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
        $result  = $this->rawFactory->create();

        $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $result->setHeader('X-Robots-Tag', 'noindex', true);
        $result->setHeader('X-Content-Type-Options', 'nosniff', true);

        if (!$this->fullBuilder->isEnabled($storeId)) {
            $result->setHttpResponseCode(404);
            $result->setHeader('Cache-Control', 'no-store, max-age=0', true);
            $result->setContents("# llms-full.txt\n\nExpanded LLM content is not enabled for this store.\n");
            return $result;
        }

        $result->setHeader('Cache-Control', 'public, max-age=3600', true);
        $result->setHeader('Content-Disposition', 'inline; filename="llms-full.txt"', true);

        $result->setContents($this->fullBuilder->build($storeId));
        return $result;
    }
}
