<?php
declare(strict_types=1);

namespace Caravel\Pay\Model\Config\Backend;

use Magento\Framework\App\Config\Value;

/** "Reconnect on save" acts once: it is never stored as Yes. */
class Reconnect extends Value
{
    public function beforeSave()
    {
        $this->setValue('0');
        return parent::beforeSave();
    }
}
