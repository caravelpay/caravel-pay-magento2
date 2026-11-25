<?php
declare(strict_types=1);

namespace Caravel\Pay\Model;

use CaravelPay\Connector\Checkout;
use CaravelPay\Connector\CheckoutOrders;
use CaravelPay\Connector\Decision;
use CaravelPay\Connector\Money;
use CaravelPay\Connector\OrderAdapter;
use CaravelPay\Connector\OrderSnapshot;
use CaravelPay\Connector\StartSnapshot;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;

/**
 * Magento orders for the connector.
 *
 * | Checkout status              | Magento order                                                       |
 * |------------------------------|---------------------------------------------------------------------|
 * | awaiting_payment, confirming | Pending Payment / "Awaiting Caravel Pay payment" (comment on confirming) |
 * | paid                         | invoiced (registerCaptureNotification, tx hash) → Processing, order email |
 * | underpaid, review            | On Hold, comment                                                    |
 * | expired                      | comment; cancelled by cron after "Cancel unpaid orders after" hours  |
 *
 * Expiry doesn't cancel at once because Magento can't invoice a cancelled order, and a payment sent
 * just before expiry can still confirm (expired → paid). The cron re-checks the checkout first.
 */
class Orders implements OrderAdapter, CheckoutOrders
{
    /** @var OrderRepositoryInterface */
    private $orders;
    /** @var Service */
    private $service;
    /** @var LockManagerInterface */
    private $locks;
    /** @var OrderSender */
    private $orderSender;

    public function __construct(OrderRepositoryInterface $orders, Service $service, LockManagerInterface $locks, OrderSender $orderSender)
    {
        $this->orders = $orders;
        $this->service = $service;
        $this->locks = $locks;
        $this->orderSender = $orderSender;
    }

    /** @return Order|null */
    private function load(int $orderId)
    {
        try {
            $order = $this->orders->get($orderId);
        } catch (\Exception $e) {
            return null;
        }
        return $order instanceof Order && $order->getId() ? $order : null;
    }

    private function store(): Store
    {
        return $this->service->store();
    }

    /** OrderSnapshot state of a Magento order. */
    public function state(Order $order): string
    {
        if ($order->hasInvoices() || (float) $order->getTotalPaid() > 0) {
            return OrderSnapshot::PAID;
        }
        switch ((string) $order->getState()) {
            case Order::STATE_PENDING_PAYMENT:
            case Order::STATE_NEW:
                return OrderSnapshot::OPEN;
            case Order::STATE_HOLDED:
                return OrderSnapshot::HELD;
            case Order::STATE_CANCELED:
                return OrderSnapshot::CANCELLED;
            default:
                return OrderSnapshot::OTHER;
        }
    }

    private function ours(Order $order): bool
    {
        $payment = $order->getPayment();
        return $payment && Service::CODE === $payment->getMethod();
    }

    // ---------------------------------------------------------------- webhooks

    public function locate($checkoutId, $fetch)
    {
        $found = $this->store()->findCheckout((string) $checkoutId);
        return $found ? $found['order_id'] : null;
    }

    public function checkoutMerchant($ref, $checkoutId, $signer)
    {
        $found = $this->store()->findCheckout((string) $checkoutId);
        return $found && $found['order_id'] === (int) $ref ? $found['merchant_id'] : '';
    }

    public function lock($ref)
    {
        $name = 'caravel_pay_order_' . (int) $ref;
        return $this->locks->lock($name, 0) ? $name : null;
    }

    public function unlock($lease)
    {
        if (is_string($lease)) {
            $this->locks->unlock($lease);
        }
    }

    public function owns($lease)
    {
        return is_string($lease) && $this->locks->isLocked($lease);
    }

    public function snapshot($ref, Checkout $checkout)
    {
        $order = $this->load((int) $ref);
        if (null === $order) {
            return null;
        }
        $record = $this->store()->record((int) $ref);
        $checkouts = $this->store()->checkouts((int) $ref);
        return OrderSnapshot::of([
            'ours' => $this->ours($order) && isset($checkouts[$checkout->id()]),
            'expectedAmount' => is_array($record['request']) && isset($record['request']['amount']) ? (string) $record['request']['amount'] : null,
            // The currency of that request (US dollars for requests saved before 1.2.0).
            'expectedCurrency' => is_array($record['request']) && isset($record['request']['currency']) ? (string) $record['request']['currency'] : 'USD',
            'currentCheckoutId' => $record['checkout_id'],
            'marker' => $record['marker'],
            'state' => $this->state($order),
            'stateLabel' => (string) $order->getStatusLabel(),
            'total' => Money::usd((float) $order->getGrandTotal()),
            'currency' => (string) $order->getOrderCurrencyCode(),
            'paidAmount' => $record['paid_amount'],
            'transactionId' => (string) ($order->getPayment() ? $order->getPayment()->getLastTransId() : ''),
        ]);
    }

    public function apply($ref, Decision $decision, Checkout $checkout)
    {
        $orderId = (int) $ref;
        $order = $this->load($orderId);
        if (null === $order) {
            return false;
        }
        $fields = [];
        if (null !== $decision->marker) {
            $fields['marker'] = $decision->marker;
        }
        if (null !== $decision->paidAmount) {
            $fields['paid_amount'] = $decision->paidAmount;
        }
        switch ($decision->action) {
            case Decision::COMPLETE:
                return $this->complete($order, $decision, $checkout, $fields);
            case Decision::HOLD:
                if ($order->canHold()) {
                    $order->hold();
                }
                $order->addCommentToStatusHistory($decision->note);
                $fields['cancel_after'] = 0;
                break;
            case Decision::CANCEL:
                $hours = $this->service->cancelAfterHours();
                $record = $this->store()->record($orderId);
                if (0 === $hours) {
                    if ($order->canCancel()) {
                        $order->cancel();
                    }
                    $order->addCommentToStatusHistory($decision->note);
                } elseif ($record['cancel_after'] <= 0) {
                    $fields['cancel_after'] = $this->service->now() + $hours * 3600;
                    $order->addCommentToStatusHistory($this->service->messages()->render('note.cancel_scheduled', [$checkout->id(), (string) $hours]));
                }
                break;
            case Decision::NOTE:
                $order->addCommentToStatusHistory($decision->note);
                break;
            default:
                break;
        }
        $this->orders->save($order);
        if ($fields) {
            $this->store()->save($orderId, $fields);
        }
        return true;
    }

    /** paid: invoice the order with the payout tx hash (from Pending Payment or On Hold). */
    private function complete(Order $order, Decision $decision, Checkout $checkout, array $fields): bool
    {
        $orderId = (int) $order->getId();
        if (Order::STATE_CANCELED === $order->getState()) {
            // Magento can't reopen a cancelled order: record the payment for the merchant instead.
            $order->addCommentToStatusHistory($this->service->messages()->render('note.paid_after_cancel', [$checkout->id(), $this->service->messages()->payoutNote($checkout)]));
            $this->orders->save($order);
            $this->store()->save($orderId, $fields + ['cancel_after' => 0]);
            return true;
        }
        if (Order::STATE_HOLDED === $order->getState() && $order->canUnhold()) {
            $order->unhold();
        }
        $payment = $order->getPayment();
        $payment->setTransactionId($decision->txHash);
        $payment->setLastTransId($decision->txHash);
        $payment->setIsTransactionClosed(true);
        $payment->registerCaptureNotification((float) $order->getBaseGrandTotal(), true);
        $order->addCommentToStatusHistory($decision->note);
        if ('' !== $decision->followUpNote) {
            $order->addCommentToStatusHistory($decision->followUpNote);
        }
        $this->orders->save($order);
        $this->store()->save($orderId, $fields + ['cancel_after' => 0]);
        if (!$order->hasInvoices()) {
            return false;
        }
        try {
            $this->orderSender->send($order);
        } catch (\Throwable $e) {
            // The order email is a courtesy; the payment is recorded either way.
        }
        return true;
    }

    public function recordEvent($ref, $eventId, $type)
    {
        $events = $this->store()->record((int) $ref)['events'];
        $events[$eventId] = ['type' => $type, 'at' => gmdate('Y-m-d\TH:i:s\Z')];
        $this->store()->save((int) $ref, ['events' => array_slice($events, -20, null, true)]);
    }

    // ---------------------------------------------------------------- starting a payment

    public function startSnapshot($ref)
    {
        $order = $this->load((int) $ref);
        if (null === $order) {
            return null;
        }
        $record = $this->store()->record((int) $ref);
        $checkouts = $this->store()->checkouts((int) $ref);
        return StartSnapshot::of([
            'payable' => $this->ours($order) && OrderSnapshot::OPEN === $this->state($order) && $record['cancel_after'] <= 0,
            'amount' => Money::usd((float) $order->getGrandTotal()),
            'currency' => (string) $order->getOrderCurrencyCode(),
            'email' => (string) $order->getCustomerEmail(),
            'request' => $record['request'],
            'attempt' => $record['attempt'],
            'requestMerchant' => $record['request_merchant'],
            'currentCheckoutId' => $record['checkout_id'],
            'currentMerchant' => $checkouts[$record['checkout_id']] ?? '',
            'url' => $record['checkout_url'],
            'expiresAt' => $record['expires_at'],
        ]);
    }

    public function saveRequest($ref, array $body, $attempt, $merchantId)
    {
        $this->store()->save((int) $ref, ['request' => $body, 'attempt' => (int) $attempt, 'request_merchant' => (string) $merchantId]);
    }

    public function dropRequest($ref, array $body)
    {
        $record = $this->store()->record((int) $ref);
        if ('' === $record['checkout_id'] && $record['request'] === $body) {
            $this->store()->save((int) $ref, ['request' => null]);
        }
    }

    public function linkCheckout($ref, Checkout $checkout, $merchantId, $isNew, $note)
    {
        $this->store()->link($checkout->id(), (int) $ref, (string) $merchantId);
        $fields = ['checkout_id' => $checkout->id(), 'checkout_url' => $checkout->checkoutUrl(), 'expires_at' => $checkout->expiresAt()];
        if ($isNew) {
            $fields['marker'] = '';
        }
        $this->store()->save((int) $ref, $fields);
        if ('' !== $note) {
            $order = $this->load((int) $ref);
            if (null !== $order) {
                $order->addCommentToStatusHistory($note);
                $this->orders->save($order);
            }
        }
    }

    /** "<increment id>-<protect code>": the protect code is random per order. */
    public function reference($ref, $attempt)
    {
        $order = $this->load((int) $ref);
        $increment = null !== $order ? preg_replace('/[^A-Za-z0-9-]/', '', (string) $order->getIncrementId()) : (string) (int) $ref;
        $random = null !== $order ? substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $order->getProtectCode()), 0, 6) : '';
        $reference = $increment . ('' !== $random ? '-' . $random : '');
        return $attempt > 1 ? $reference . '-' . (int) $attempt : $reference;
    }

    // ---------------------------------------------------------------- expiry cron

    /**
     * Cancel an order whose checkout expired more than the configured hours ago, after confirming
     * with the signed API that the checkout is still expired.
     *
     * @return string cancelled | kept | retry | gone
     */
    public function cancelIfStillExpired(int $orderId): string
    {
        $lease = $this->lock($orderId);
        if (null === $lease) {
            return 'retry';
        }
        try {
            $order = $this->load($orderId);
            $record = $this->store()->record($orderId);
            if (null === $order || OrderSnapshot::OPEN !== $this->state($order) || '' === $record['checkout_id']) {
                $this->store()->save($orderId, ['cancel_after' => 0]);
                return 'gone';
            }
            $connection = $this->service->connection();
            $checkouts = $this->store()->checkouts($orderId);
            $credentials = $connection->credentialsFor($checkouts[$record['checkout_id']] ?? '');
            if (null === $credentials) {
                $status = 'expired'; // The checkout's credentials were retired (7 days): it can't change any more.
            } else {
                try {
                    $status = \CaravelPay\Connector\WebhookProcessor::fetchCheckout($connection, $credentials, $record['checkout_id'])->status();
                } catch (\CaravelPay\Connector\ApiException $e) {
                    return 'retry';
                }
            }
            if ('expired' !== $status) {
                $this->store()->save($orderId, ['cancel_after' => 0]);
                return 'kept';
            }
            if ($order->canCancel()) {
                $order->cancel();
            }
            $order->addCommentToStatusHistory($this->service->messages()->render('note.expired', [$record['checkout_id']]));
            $this->orders->save($order);
            $this->store()->save($orderId, ['cancel_after' => 0]);
            return 'cancelled';
        } finally {
            $this->unlock($lease);
        }
    }
}
