<?php
declare(strict_types=1);

namespace Caravel\Pay\Cron;

use Caravel\Pay\Model\Orders;
use Caravel\Pay\Model\Service;

/**
 * Every 15 minutes: cancel orders whose checkout expired more than "Cancel unpaid orders after"
 * hours ago, after re-checking each checkout with the signed API.
 */
class CancelExpired
{
    /** @var Service */
    private $service;
    /** @var Orders */
    private $orders;

    public function __construct(Service $service, Orders $orders)
    {
        $this->service = $service;
        $this->orders = $orders;
    }

    /** @return array<int,string> order id => outcome */
    public function execute(): array
    {
        $outcomes = [];
        foreach ($this->service->store()->dueForCancel($this->service->now()) as $orderId) {
            $outcomes[$orderId] = $this->orders->cancelIfStillExpired($orderId);
        }
        return $outcomes;
    }
}
