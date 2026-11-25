<?php
declare(strict_types=1);

namespace Caravel\Pay\Controller\Checkout;

use Caravel\Pay\Model\Orders;
use Caravel\Pay\Model\Service;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;

/**
 * After "Place Order": send the buyer to the hosted checkout of the order just placed.
 */
class Start implements HttpGetActionInterface
{
    /** @var CheckoutSession */
    private $session;
    /** @var ResultFactory */
    private $resultFactory;
    /** @var Service */
    private $service;
    /** @var Orders */
    private $orders;
    /** @var ManagerInterface */
    private $messages;
    /** @var UrlInterface */
    private $url;

    public function __construct(CheckoutSession $session, ResultFactory $resultFactory, Service $service, Orders $orders, ManagerInterface $messages, UrlInterface $url)
    {
        $this->session = $session;
        $this->resultFactory = $resultFactory;
        $this->service = $service;
        $this->orders = $orders;
        $this->messages = $messages;
        $this->url = $url;
    }

    public function execute()
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $order = $this->session->getLastRealOrder();
        if (!$order || !$order->getId() || !$order->getPayment() || Service::CODE !== $order->getPayment()->getMethod()) {
            return $redirect->setPath('checkout/cart');
        }
        $orderId = (int) $order->getId();
        $token = $this->service->orderToken($orderId);
        $back = ['_secure' => true, '_nosid' => true, '_query' => ['order' => $orderId, 't' => $token]];
        $cancel = $back;
        $cancel['_query']['cancel'] = 1;
        $result = $this->service->starter($this->orders)->start(
            $orderId,
            $this->url->getUrl('caravelpay/checkout/back', $back),
            $this->url->getUrl('caravelpay/checkout/back', $cancel),
            $this->service->callbackUrl()
        );
        if ($result->ok()) {
            return $redirect->setUrl($result->redirect);
        }
        $this->messages->addErrorMessage($this->service->messages()->render($result->error));
        $this->session->restoreQuote();
        return $redirect->setPath('checkout/cart');
    }
}
