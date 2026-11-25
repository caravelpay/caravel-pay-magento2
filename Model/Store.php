<?php
declare(strict_types=1);

namespace Caravel\Pay\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * The module's tables (declared in etc/db_schema.xml); all SQL of the module lives here.
 */
class Store
{
    /** @var ResourceConnection */
    private $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }

    /** @return array The order's checkout record (defaults when none). */
    public function record(int $orderId): array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->table('caravel_pay_order'))->where('order_id = ?', $orderId));
        $request = is_array($row) ? json_decode((string) $row['request'], true) : null;
        $events = is_array($row) ? json_decode((string) $row['events'], true) : null;
        return [
            'nonce' => is_array($row) ? (string) $row['nonce'] : '',
            'request' => is_array($request) ? $request : null,
            'attempt' => is_array($row) ? max(1, (int) $row['attempt']) : 1,
            'request_merchant' => is_array($row) ? (string) $row['request_merchant'] : '',
            'checkout_id' => is_array($row) ? (string) $row['checkout_id'] : '',
            'checkout_url' => is_array($row) ? (string) $row['checkout_url'] : '',
            'expires_at' => is_array($row) ? (string) $row['expires_at'] : '',
            'marker' => is_array($row) ? (string) $row['marker'] : '',
            'paid_amount' => is_array($row) ? (string) $row['paid_amount'] : '',
            'cancel_after' => is_array($row) ? (int) $row['cancel_after'] : 0,
            'events' => is_array($events) ? $events : [],
        ];
    }

    /** Insert or update fields of an order's record. */
    public function save(int $orderId, array $fields): void
    {
        $data = ['order_id' => $orderId];
        foreach ($fields as $name => $value) {
            $data[$name] = in_array($name, ['request', 'events'], true) && null !== $value ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $value;
        }
        $this->resource->getConnection()->insertOnDuplicate($this->table('caravel_pay_order'), $data, array_keys($fields));
    }

    public function link(string $checkoutId, int $orderId, string $merchantId): void
    {
        $this->resource->getConnection()->insertOnDuplicate($this->table('caravel_pay_checkout'), ['checkout_id' => $checkoutId, 'order_id' => $orderId, 'merchant_id' => $merchantId], ['checkout_id']);
    }

    /** @return array|null {order_id, merchant_id} */
    public function findCheckout(string $checkoutId): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->table('caravel_pay_checkout'), ['order_id', 'merchant_id'])->where('checkout_id = ?', $checkoutId));
        return is_array($row) ? ['order_id' => (int) $row['order_id'], 'merchant_id' => (string) $row['merchant_id']] : null;
    }

    /** @return array<string,string> checkout id => merchant id */
    public function checkouts(int $orderId): array
    {
        $db = $this->resource->getConnection();
        return array_map('strval', $db->fetchPairs($db->select()->from($this->table('caravel_pay_checkout'), ['checkout_id', 'merchant_id'])->where('order_id = ?', $orderId)));
    }

    /** @return int[] Orders whose expired checkout is due to be cancelled. */
    public function dueForCancel(int $now, int $limit = 50): array
    {
        $db = $this->resource->getConnection();
        return array_map('intval', $db->fetchCol($db->select()->from($this->table('caravel_pay_order'), ['order_id'])
            ->where('cancel_after > 0')->where('cancel_after <= ?', $now)->order('cancel_after ASC')->limit($limit)));
    }

    public function eventSeen(string $eventId): bool
    {
        $db = $this->resource->getConnection();
        return (bool) $db->fetchOne($db->select()->from($this->table('caravel_pay_event'), ['event_id'])->where('event_id = ?', $eventId));
    }

    public function rememberEvent(string $eventId): void
    {
        $this->resource->getConnection()->insertOnDuplicate($this->table('caravel_pay_event'), ['event_id' => $eventId, 'created_at' => gmdate('Y-m-d H:i:s')], ['event_id']);
    }

    public function getSetting(string $key): ?string
    {
        $db = $this->resource->getConnection();
        $value = $db->fetchOne($db->select()->from($this->table('caravel_pay_setting'), ['value'])->where('setting_key = ?', $key));
        return false === $value || null === $value ? null : (string) $value;
    }

    public function setSetting(string $key, string $value): void
    {
        $this->resource->getConnection()->insertOnDuplicate($this->table('caravel_pay_setting'), ['setting_key' => $key, 'value' => $value], ['value']);
    }

    public function deleteSetting(string $key): void
    {
        $this->resource->getConnection()->delete($this->table('caravel_pay_setting'), ['setting_key = ?' => $key]);
    }
}
