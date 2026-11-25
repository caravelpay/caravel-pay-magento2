<?php
declare(strict_types=1);

namespace Caravel\Pay\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order;

/**
 * "Place Order": the order starts as Pending Payment with the "Awaiting Caravel Pay payment"
 * status (not Magento's pending_payment status, so the core "Pending Payment Order Lifetime" cron
 * doesn't cancel it while a late payment can still arrive). The order email waits for the payment.
 */
class InitializeCommand implements CommandInterface
{
    public const STATUS = 'caravel_pending';

    public function execute(array $commandSubject)
    {
        $stateObject = SubjectReader::readStateObject($commandSubject);
        $payment = SubjectReader::readPayment($commandSubject)->getPayment();
        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus(self::STATUS);
        $stateObject->setIsNotified(false);
        $order = $payment->getOrder();
        $order->setCanSendNewEmailFlag(false);
        return null;
    }
}
