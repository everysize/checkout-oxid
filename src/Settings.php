<?php

declare(strict_types=1);

namespace Everysize\Checkout;

use Everysize\Checkout\Api\Credentials;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;

/**
 * Module configuration, stored in the shop database (table
 * everysize_checkout_settings) instead of OXID's module settings: those live
 * in var/configuration/*.yaml, a directory OXID recommends to keep in version
 * control — the client secret would end up in the merchant's repository.
 */
class Settings implements Credentials
{
    public const CLIENT_ID = 'clientId';
    public const CLIENT_SECRET = 'clientSecret';
    public const DELIVERY_SET = 'deliverySetId';
    public const CARRIER = 'carrier';
    /** '1' (default) or '0': refund the full amount when an order is cancelled before shipping. */
    public const AUTO_REFUND_DECLINE = 'autoRefundDecline';
    /** Cancellations reported from this time on are refunded automatically (set by migration / when switched on). */
    public const AUTO_REFUND_SINCE = 'autoRefundSince';

    public const DEFAULT_DELIVERY_SET = 'oxidstandard';
    public const DEFAULT_CARRIER = 'DHL';

    /** Carriers listed in the everysize API documentation; the API accepts any value. */
    public const CARRIERS = [
        'Cargo', 'Der Courier', 'Deutsche Post', 'DHL', 'DPD', 'eparcel', 'FedEx', 'GdSK', 'GLS', 'GO!',
        'Hermes', 'iloxx', 'Midway', 'Noxxs Logistic', 'paket.ag', 'primeMail', 'TOMBA', 'UPS',
    ];

    /** @var array<int, array<string, string>> */
    private array $cache = [];

    public function getClientId(): string
    {
        return $this->get(self::CLIENT_ID);
    }

    public function getClientSecret(): string
    {
        return $this->get(self::CLIENT_SECRET);
    }

    public function getDeliverySetId(): string
    {
        return $this->get(self::DELIVERY_SET, self::DEFAULT_DELIVERY_SET);
    }

    public function getCarrier(): string
    {
        return $this->get(self::CARRIER, self::DEFAULT_CARRIER);
    }

    public function isAutoRefundDecline(): bool
    {
        return $this->get(self::AUTO_REFUND_DECLINE, '1') === '1';
    }

    public function getAutoRefundSince(): string
    {
        return $this->get(self::AUTO_REFUND_SINCE);
    }

    /** Without credentials the module makes no API calls at all. */
    public function isConfigured(): bool
    {
        return $this->getClientId() !== '' && $this->getClientSecret() !== '';
    }

    public function get(string $name, string $default = ''): string
    {
        $values = $this->load();
        $value = $values[$name] ?? '';

        return $value !== '' ? $value : $default;
    }

    public function set(string $name, string $value): void
    {
        DatabaseProvider::getDb()->execute(
            'INSERT INTO everysize_checkout_settings (OXSHOPID, NAME, VALUE) VALUES (:shopId, :name, :value)
             ON DUPLICATE KEY UPDATE VALUE = VALUES(VALUE)',
            [':shopId' => $this->shopId(), ':name' => $name, ':value' => $value]
        );
        unset($this->cache[$this->shopId()]);
    }

    /**
     * @return array<string, string>
     */
    private function load(): array
    {
        $shopId = $this->shopId();
        if (!isset($this->cache[$shopId])) {
            $rows = DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getAll(
                'SELECT NAME, VALUE FROM everysize_checkout_settings WHERE OXSHOPID = :shopId',
                [':shopId' => $shopId]
            );
            $this->cache[$shopId] = [];
            foreach ($rows as $row) {
                $this->cache[$shopId][(string) $row['NAME']] = trim((string) $row['VALUE']);
            }
        }

        return $this->cache[$shopId];
    }

    private function shopId(): int
    {
        return (int) Registry::getConfig()->getShopId();
    }
}
