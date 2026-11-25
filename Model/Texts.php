<?php
declare(strict_types=1);

namespace Caravel\Pay\Model;

use CaravelPay\Connector\Messages;

/**
 * The connector's texts through Magento's translation (i18n/<locale>.csv). The English source
 * strings are listed in i18n/en_US.csv; their %1$s placeholders stay as they are.
 */
class Texts
{
    /** @return array<string,string> */
    public function all(): array
    {
        $texts = [];
        foreach (Messages::english() as $key => $english) {
            $texts[$key] = (string) __($english);
        }
        return $texts;
    }
}
