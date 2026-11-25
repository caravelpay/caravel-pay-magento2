<?php
declare(strict_types=1);

namespace Caravel\Pay\Model;

use CaravelPay\Connector\Api;
use CaravelPay\Connector\CheckoutStarter;
use CaravelPay\Connector\ConnectInput;
use CaravelPay\Connector\Connection;
use CaravelPay\Connector\EventLog;
use CaravelPay\Connector\Messages;
use CaravelPay\Connector\SdkApi;
use CaravelPay\Connector\Urls;
use CaravelPay\Connector\WebhookProcessor;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Wires the Caravel Pay connector for Magento: storage, API (bundled SDK), texts and URLs.
 */
class Service implements EventLog
{
    public const VERSION = '1.2.1';
    public const CODE = 'caravelpay';

    /** @var Store */
    private $store;
    /** @var Settings */
    private $settings;
    /** @var PsrLogger */
    private $logger;
    /** @var ScopeConfigInterface */
    private $config;
    /** @var StoreManagerInterface */
    private $storeManager;
    /** @var UrlInterface */
    private $url;
    /** @var Texts */
    private $texts;
    /** @var Api|null */
    private $api;
    /** @var callable|null */
    private $clock;
    /** @var Connection|null */
    private $connection;
    /** @var Messages|null */
    private $messages;

    public function __construct(Store $store, Settings $settings, PsrLogger $logger, ScopeConfigInterface $config, StoreManagerInterface $storeManager, UrlInterface $url, Texts $texts)
    {
        $this->store = $store;
        $this->settings = $settings;
        $this->logger = $logger;
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->url = $url;
        $this->texts = $texts;
    }

    /** Tests (and integrators) can replace the API and the clock before first use. */
    public function useApi(Api $api, ?callable $clock = null): void
    {
        $this->api = $api;
        $this->clock = $clock;
        $this->connection = null;
    }

    public function connection(): Connection
    {
        if (null === $this->connection) {
            $api = $this->api ?: new SdkApi(['api_base' => Urls::configuredApiBase(), 'user_agent' => 'CaravelPay-Magento/' . self::VERSION]);
            $this->connection = new Connection($this->settings, $api, $this->logger, $this->clock);
        }
        return $this->connection;
    }

    public function messages(): Messages
    {
        if (null === $this->messages) {
            $this->messages = new Messages($this->texts->all());
        }
        return $this->messages;
    }

    public function store(): Store
    {
        return $this->store;
    }

    public function processor(Orders $orders): WebhookProcessor
    {
        return new WebhookProcessor($this->connection(), $orders, $this, $this->messages(), $this->callbackUrls());
    }

    public function starter(Orders $orders): CheckoutStarter
    {
        return (new CheckoutStarter($this->connection(), $orders, $this->messages()))->withFeeMode($this->feeMode())->withFxBuffer($this->configValue('fx_buffer'));
    }

    /** Who pays the fees for new checkouts: "seller" (the default), "split" or "buyer". */
    public function feeMode(): string
    {
        return \CaravelPay\FeeMode::normalize($this->configValue('fee_mode'));
    }

    /** The line buyers see with the payment method when a fee is added at payment; '' for the default. */
    public function feeNotice(): string
    {
        return $this->messages()->feeNotice($this->feeMode());
    }

    /**
     * The line buyers see when the order is not in US dollars: the charge is in US dollars, with an estimate when a
     * rate is at hand. '' for US dollars.
     *
     * @param string     $currency The quote or order currency.
     * @param float|null $total    Its total in that currency, when known.
     */
    public function currencyNotice(string $currency, ?float $total = null): string
    {
        try {
            return \CaravelPay\Connector\CurrencyNotice::text($this->connection(), $this->settings, $currency, $total, $this->configValue('fx_buffer'));
        } catch (\Throwable $error) {
            return '';
        }
    }

    /** The webhook URL sent with each checkout. */
    public function callbackUrl(): string
    {
        return $this->url->getUrl('caravelpay/webhook/index', ['_secure' => true, '_nosid' => true]);
    }

    /**
     * Every form the webhook URL may take (with and without the trailing slash or "index"),
     * from configuration, never from request headers.
     *
     * @return string[]
     */
    public function callbackUrls(): array
    {
        $url = rtrim($this->callbackUrl(), '/');
        $short = preg_replace('#/index$#', '', $url);
        return array_values(array_unique([$url . '/', $url, $short . '/', $short]));
    }

    public function connectInput(): ConnectInput
    {
        $base = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB, true);
        $email = (string) $this->config->getValue('trans_email/ident_general/email', ScopeInterface::SCOPE_STORE);
        return new ConnectInput((string) $base, $email, 'magento', self::VERSION);
    }

    public function configValue(string $field): string
    {
        return (string) $this->config->getValue('payment/' . self::CODE . '/' . $field, ScopeInterface::SCOPE_STORE);
    }

    /** Hours an expired, unpaid order waits before it is cancelled (late payments still complete it meanwhile). */
    public function cancelAfterHours(): int
    {
        $value = $this->configValue('cancel_after_hours');
        return '' === $value ? 24 : max(0, min(720, (int) $value));
    }

    /** A per-order token for the return links, with a random key kept in caravel_pay_setting. */
    public function orderToken(int $orderId): string
    {
        $key = $this->settings->get('link_key', '');
        if (!preg_match('/^[0-9a-f]{64}$/D', $key)) {
            $key = bin2hex(random_bytes(32));
            $this->settings->set('link_key', $key, true);
        }
        return substr(hash_hmac('sha256', 'order|' . $orderId, $key), 0, 32);
    }

    public function validOrderToken(int $orderId, string $token): bool
    {
        return $orderId > 0 && '' !== $token && hash_equals($this->orderToken($orderId), $token);
    }

    public function now(): int
    {
        return $this->connection()->now();
    }

    public function seen($eventId)
    {
        return $this->store->eventSeen((string) $eventId);
    }

    public function remember($eventId)
    {
        $this->store->rememberEvent((string) $eventId);
    }
}
