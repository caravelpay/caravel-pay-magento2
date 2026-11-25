<?php
declare(strict_types=1);

namespace Caravel\Pay\Model\Ui;

use Caravel\Pay\Model\Service;
use Magento\Checkout\Model\ConfigProviderInterface;

/** Checkout JS config (window.checkoutConfig.payment.caravelpay). */
class ConfigProvider implements ConfigProviderInterface
{
    public const CODE = 'caravelpay';

    /** @var Service */
    private $service;
    /** @var \Magento\Checkout\Model\Session|null */
    private $checkoutSession;

    public function __construct(Service $service, ?\Magento\Checkout\Model\Session $checkoutSession = null)
    {
        $this->service = $service;
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * For a cart that is not in US dollars: "You'll be charged in US dollars: about $48.60. …". '' for US dollars.
     */
    private function currencyNotice(): string
    {
        try {
            $quote = $this->checkoutSession ? $this->checkoutSession->getQuote() : null;
            return $quote ? $this->service->currencyNotice((string) $quote->getQuoteCurrencyCode(), (float) $quote->getGrandTotal()) : '';
        } catch (\Throwable $error) {
            return '';
        }
    }

    public function getConfig()
    {
        $instructions = trim($this->service->configValue('instructions'));
        return ['payment' => [self::CODE => [
            'instructions' => '' !== $instructions ? $instructions : (string) __('You will be sent to the secure Caravel Pay checkout to pay by card, Apple Pay or Google Pay.'),
            'feeNotice' => $this->service->feeNotice(),
            'currencyNotice' => $this->currencyNotice(),
            'startUrl' => 'caravelpay/checkout/start',
        ]]];
    }
}
