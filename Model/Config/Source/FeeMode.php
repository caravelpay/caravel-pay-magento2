<?php
declare(strict_types=1);

namespace Caravel\Pay\Model\Config\Source;

use CaravelPay\FeeMode as SdkFeeMode;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Options of the "Who pays the fees" setting: seller (the default), split or buyer.
 */
class FeeMode implements OptionSourceInterface
{
    public function toOptionArray()
    {
        // Phrases spelled out so Magento's translation collector finds them (i18n/en_US.csv).
        $labels = [
            SdkFeeMode::SELLER => __('Seller pays everything (default)'),
            SdkFeeMode::SPLIT => __('Split: buyer pays the card fee'),
            SdkFeeMode::BUYER => __('Buyer pays everything'),
        ];
        $options = [];
        foreach ($labels as $value => $label) {
            $options[] = ['value' => $value, 'label' => (string) $label];
        }
        return $options;
    }
}
