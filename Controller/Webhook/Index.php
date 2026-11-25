<?php
declare(strict_types=1);

namespace Caravel\Pay\Controller\Webhook;

use Caravel\Pay\Model\Orders;
use Caravel\Pay\Model\Service;
use CaravelPay\Connector\WebhookResponse;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * POST /caravelpay/webhook/index: signed webhooks from Caravel Pay. Authenticated by the HMAC
 * signature over the raw request, so Magento's form-key CSRF check doesn't apply.
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /** @var HttpRequest */
    private $request;
    /** @var ResultFactory */
    private $resultFactory;
    /** @var Service */
    private $service;
    /** @var Orders */
    private $orders;

    public function __construct(HttpRequest $request, ResultFactory $resultFactory, Service $service, Orders $orders)
    {
        $this->request = $request;
        $this->resultFactory = $resultFactory;
        $this->service = $service;
        $this->orders = $orders;
    }

    public function execute()
    {
        $server = $this->request->getServer();
        $headers = is_object($server) && method_exists($server, 'toArray') ? $server->toArray() : (array) $server;
        $response = $this->service->processor($this->orders)->handle((string) $this->request->getContent(), $headers, (string) $this->request->getMethod());
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setHttpResponseCode($response->status);
        foreach (WebhookResponse::headers() as $name => $value) {
            if ('Content-Type' !== $name) {
                $result->setHeader($name, $value, true);
            }
        }
        $result->setData($response->body);
        return $result;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
