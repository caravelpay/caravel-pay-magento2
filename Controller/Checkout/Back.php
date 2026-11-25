<?php
declare(strict_types=1);

namespace Caravel\Pay\Controller\Checkout;

use Caravel\Pay\Model\Orders;
use Caravel\Pay\Model\Service;
use CaravelPay\Connector\OrderSnapshot;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * Where the hosted checkout sends the buyer back. Arriving here is not proof of payment: only a
 * verified webhook invoices the order. Paid or still confirming → success page; cancelled → the
 * cart is restored (the unpaid order is cancelled later, when its checkout has expired).
 */
class Back implements HttpGetActionInterface
{
    /** @var RequestInterface */
    private $request;
    /** @var CheckoutSession */
    private $session;
    /** @var ResultFactory */
    private $resultFactory;
    /** @var Service */
    private $service;
    /** @var Orders */
    private $orders;
    /** @var OrderRepositoryInterface */
    private $repository;
    /** @var ManagerInterface */
    private $messages;

    public function __construct(RequestInterface $request, CheckoutSession $session, ResultFactory $resultFactory, Service $service, Orders $orders, OrderRepositoryInterface $repository, ManagerInterface $messages)
    {
        $this->request = $request;
        $this->session = $session;
        $this->resultFactory = $resultFactory;
        $this->service = $service;
        $this->orders = $orders;
        $this->repository = $repository;
        $this->messages = $messages;
    }

    public function execute()
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $orderId = (int) $this->request->getParam('order');
        if (!$this->service->validOrderToken($orderId, (string) $this->request->getParam('t'))) {
            return $redirect->setPath('/');
        }
        try {
            $order = $this->repository->get($orderId);
        } catch (\Exception $e) {
            return $redirect->setPath('/');
        }
        $ownSession = $order instanceof Order && (int) $this->session->getLastRealOrder()->getId() === $orderId;
        if ($this->request->getParam('cancel') && $order instanceof Order && OrderSnapshot::OPEN === $this->orders->state($order)) {
            if ($ownSession) {
                $this->session->restoreQuote();
            }
            $this->messages->addNoticeMessage(__('The payment was not completed. Your cart was restored so you can try again.'));
            return $redirect->setPath('checkout/cart');
        }
        if ($ownSession) {
            return $redirect->setPath('checkout/onepage/success');
        }
        $this->messages->addSuccessMessage(__('Thank you. Your payment is being confirmed; you will get an email when it is done.'));
        return $redirect->setPath('/');
    }
}
