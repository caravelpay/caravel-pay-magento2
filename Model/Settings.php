<?php
declare(strict_types=1);

namespace Caravel\Pay\Model;

use CaravelPay\Connector\KeyValue;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Connector storage in caravel_pay_setting; secrets are encrypted with Magento's key.
 */
class Settings implements KeyValue
{
    private const ENCRYPTED = 'enc:';

    /** @var Store */
    private $store;
    /** @var EncryptorInterface */
    private $encryptor;

    public function __construct(Store $store, EncryptorInterface $encryptor)
    {
        $this->store = $store;
        $this->encryptor = $encryptor;
    }

    public function get($key, $default = '')
    {
        $value = $this->store->getSetting((string) $key);
        if (null === $value) {
            return $default;
        }
        if (0 === strpos($value, self::ENCRYPTED)) {
            $decrypted = $this->encryptor->decrypt(substr($value, strlen(self::ENCRYPTED)));
            return is_string($decrypted) && '' !== $decrypted ? $decrypted : $default;
        }
        return $value;
    }

    public function set($key, $value, $secret = false)
    {
        $this->store->setSetting((string) $key, $secret ? self::ENCRYPTED . $this->encryptor->encrypt((string) $value) : (string) $value);
    }

    public function delete($key)
    {
        $this->store->deleteSetting((string) $key);
    }
}
