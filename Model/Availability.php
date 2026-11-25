<?php
declare(strict_types=1);

namespace Caravel\Pay\Model;

use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Api\Data\CartInterface;

/**
 * Plugin on the payment method's isAvailable(): Caravel Pay shows only while the store is
 * connected and its merchant is active (status cached for a few minutes).
 */
class Availability
{
    /** @var Service */
    private $service;

    public function __construct(Service $service)
    {
        $this->service = $service;
    }

    public function afterIsAvailable(MethodInterface $subject, $result, ?CartInterface $quote = null)
    {
        if (!$result || Service::CODE !== $subject->getCode()) {
            return $result;
        }
        try {
            return $this->service->connection()->checkoutReady();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
