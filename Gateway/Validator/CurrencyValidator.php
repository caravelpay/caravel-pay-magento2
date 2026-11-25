<?php
declare(strict_types=1);

namespace Caravel\Pay\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;

/** Caravel Pay takes US dollars and the currencies it converts to US dollars. */
class CurrencyValidator extends AbstractValidator
{
    public function validate(array $validationSubject)
    {
        $currency = strtoupper((string) ($validationSubject['currency'] ?? ''));
        // US dollars, or a currency Caravel Pay converts to US dollars when the buyer pays.
        $supported = \CaravelPay\Currency::isSupported($currency);
        return $this->createResult($supported, $supported ? [] : [__('Caravel Pay can\'t take orders in this currency.')]);
    }
}
