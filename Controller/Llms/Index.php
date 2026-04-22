<?php
/**
 * Panth LLMs.txt — /llms.txt controller.
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
use Panth\LlmsTxt\Model\LlmsTxt\Builder;

/**
 * Serves /llms.txt from the Builder.
 */
class Index implements HttpGetActionInterface
{
    /**
     * @param RawFactory $rawFactory
     * @param Builder $builder
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly Builder $builder,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return ResponseInterface|ResultInterface
     */
    public function execute(): ResponseInterface|ResultInterface
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);

        if (!$this->builder->isEnabled($storeId)) {
            $result->setHttpResponseCode(404);
            $result->setContents("# llms.txt\n\nllms.txt is not enabled for this store.\n");
            return $result;
        }

        $result->setContents($this->builder->build($storeId));
        return $result;
    }
}
