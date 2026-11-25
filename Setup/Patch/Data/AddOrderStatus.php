<?php
declare(strict_types=1);

namespace Caravel\Pay\Setup\Patch\Data;

use Caravel\Pay\Gateway\Command\InitializeCommand;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order;

/** Adds the "Awaiting Caravel Pay payment" status to the Pending Payment state. */
class AddOrderStatus implements DataPatchInterface
{
    /** @var ModuleDataSetupInterface */
    private $setup;

    public function __construct(ModuleDataSetupInterface $setup)
    {
        $this->setup = $setup;
    }

    public function apply()
    {
        $db = $this->setup->getConnection();
        $db->insertOnDuplicate($this->setup->getTable('sales_order_status'), ['status' => InitializeCommand::STATUS, 'label' => 'Awaiting Caravel Pay payment'], ['label']);
        $db->insertOnDuplicate($this->setup->getTable('sales_order_status_state'), [
            'status' => InitializeCommand::STATUS,
            'state' => Order::STATE_PENDING_PAYMENT,
            'is_default' => 0,
            'visible_on_front' => 1,
        ], ['visible_on_front']);
        return $this;
    }

    public static function getDependencies()
    {
        return [];
    }

    public function getAliases()
    {
        return [];
    }
}
