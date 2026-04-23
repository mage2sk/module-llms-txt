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
 * Serves the curated `/llms.txt` Markdown site map used by LLM crawlers
 * (ChatGPT, Claude, Perplexity, Gemini). Output is built + cached by
 * Builder; this controller just stamps the right HTTP response headers.
 */
class Index implements HttpGetActionInterface
{
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
        $result  = $this->rawFactory->create();

        // Base set of headers applied to both the 200 and 404 responses so
        // callers always get the same MIME type and crawler hints.
        $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $result->setHeader('X-Robots-Tag', 'noindex', true);
        $result->setHeader('X-Content-Type-Options', 'nosniff', true);

        if (!$this->builder->isEnabled($storeId)) {
            $result->setHttpResponseCode(404);
            $result->setHeader('Cache-Control', 'no-store, max-age=0', true);
            $result->setContents("# llms.txt\n\nllms.txt is not enabled for this store.\n");
            return $result;
        }

        // Tell crawlers + CDNs the output is safe to cache for an hour.
        // The application-level cache inside Builder is tag-invalidated on
        // catalog / CMS / store / config saves, so content stays fresh.
        $result->setHeader('Cache-Control', 'public, max-age=3600', true);
        $result->setHeader('Content-Disposition', 'inline; filename="llms.txt"', true);

        $result->setContents($this->builder->build($storeId));
        return $result;
    }
}
