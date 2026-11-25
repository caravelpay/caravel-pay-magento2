<?php
declare(strict_types=1);

namespace Caravel\Pay\Model\Config\Backend;

use Caravel\Pay\Model\Service;
use CaravelPay\Connector\Urls;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Payout wallet field: validated before save; after save the store connects (POST /v1/connect)
 * on the first save, when the wallet changed, or when "Reconnect on save" is set. A failed
 * connect keeps the settings and shows why.
 */
class Wallet extends Value
{
    /** @var Service */
    private $service;
    /** @var ManagerInterface */
    private $messageManager;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        Service $service,
        ManagerInterface $messageManager,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
        $this->service = $service;
        $this->messageManager = $messageManager;
    }

    public function beforeSave()
    {
        $value = trim((string) $this->getValue());
        if ('' !== $value && null === Urls::address($value)) {
            throw new LocalizedException(__('Enter a Polygon address that starts with 0x.'));
        }
        $this->setValue($value);
        return parent::beforeSave();
    }

    public function afterSave()
    {
        $wallet = (string) $this->getValue();
        if ('' !== $wallet) {
            $force = (bool) $this->getFieldsetDataValue('reconnect');
            $result = $this->service->connection()->afterSave($wallet, $force, $this->service->connectInput());
            if ('failed' === $result['result']) {
                $error = $result['error'];
                $this->messageManager->addErrorMessage(__('Connecting to Caravel Pay failed: %1 (%2). See the Connection line.', $error->getMessage(), $error->errorCode()));
            } elseif ('connected' === $result['result']) {
                $this->messageManager->addSuccessMessage(!empty($result['changed'])
                    ? __('The new payout wallet is connected. New orders pay out to it; orders already started finish on the old one.')
                    : __('Connected to Caravel Pay.'));
            }
        }
        return parent::afterSave();
    }
}
