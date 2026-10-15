<?php

declare(strict_types=1);

namespace Everysize\Checkout;

use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;

/**
 * Links OXID orders to everysize orders and records what was reported back.
 *
 * Every report is stored with a unique key (order, kind, SKU), so fulfillment
 * and revocation go out exactly once per order item — editing or re-saving an
 * order in the admin does not touch these rows. A tracking code is stored as
 * kind "tracking" with the code in the SKU column: each code goes out once,
 * so a code entered or corrected after shipping is reported once more.
 */
class OrderLinks
{
    public const KIND_FULFILLMENT = 'fulfillment';
    public const KIND_REVOCATION = 'revocation';
    public const KIND_TRACKING = 'tracking';
    /** A refund through everysize from the "everysize" tab; SKU = PayPal refund ID, REASON = amount. */
    public const KIND_REFUND = 'refund';
    /** SKU of the automatic full refund after a cancellation before shipping (REASON = PayPal refund ID or why not). */
    public const AUTO_REFUND = 'auto-decline';

    /** Sent to everysize. */
    public const RESULT_SENT = 'sent';
    /** Not sent: the order was already revoked at everysize. */
    public const RESULT_SKIPPED = 'skipped';
    /** Not sent: everysize already had it (a tracking code reported before the module remembered codes). */
    public const RESULT_KNOWN = 'known';

    public function findOrderId(string $everysizeOrderId): ?string
    {
        $orderId = DatabaseProvider::getDb()->getOne(
            'SELECT OXID FROM everysize_checkout_order WHERE OXSHOPID = :shopId AND EVERYSIZE_ORDER_ID = :esId',
            [':shopId' => $this->shopId(), ':esId' => $everysizeOrderId]
        );

        return $orderId ? (string) $orderId : null;
    }

    /**
     * @return array{OXID: string, EVERYSIZE_ORDER_ID: string, PAYPAL_CAPTURE_ID: string, ORIGINAL_EMAIL: string, NUMBER_REPORTED: ?string, CREATED: string}|null
     */
    public function getLink(string $orderId): ?array
    {
        $row = DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getRow(
            'SELECT OXID, EVERYSIZE_ORDER_ID, PAYPAL_CAPTURE_ID, ORIGINAL_EMAIL, NUMBER_REPORTED, CREATED FROM everysize_checkout_order WHERE OXID = :id',
            [':id' => $orderId]
        );

        return $row ?: null;
    }

    /**
     * everysize order IDs of the given OXID orders, for the order list.
     *
     * @param string[] $orderIds
     *
     * @return array<string, string> OXID order ID => everysize order ID
     */
    public function getEverysizeOrderIds(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $db = DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC);
        $in = implode(',', array_map([$db, 'quote'], $orderIds));
        $ids = [];
        foreach ($db->getAll("SELECT OXID, EVERYSIZE_ORDER_ID FROM everysize_checkout_order WHERE OXID IN ($in)") as $row) {
            $ids[(string) $row['OXID']] = (string) $row['EVERYSIZE_ORDER_ID'];
        }

        return $ids;
    }

    public function addOrder(string $orderId, string $everysizeOrderId, string $payPalCaptureId, string $originalEmail = ''): void
    {
        DatabaseProvider::getDb()->execute(
            'INSERT INTO everysize_checkout_order (OXID, OXSHOPID, EVERYSIZE_ORDER_ID, PAYPAL_CAPTURE_ID, ORIGINAL_EMAIL, CREATED)
             VALUES (:id, :shopId, :esId, :captureId, :originalEmail, :created)',
            [
                ':id' => $orderId,
                ':shopId' => $this->shopId(),
                ':esId' => $everysizeOrderId,
                ':captureId' => $payPalCaptureId,
                ':originalEmail' => mb_substr($originalEmail, 0, 255),
                ':created' => date('Y-m-d H:i:s'),
            ]
        );
    }

    public function addOrderItem(string $orderArticleId, string $orderId, string $sku): void
    {
        DatabaseProvider::getDb()->execute(
            'INSERT INTO everysize_checkout_order_item (OXID, ORDERID, SKU) VALUES (:id, :orderId, :sku)',
            [':id' => $orderArticleId, ':orderId' => $orderId, ':sku' => $sku]
        );
    }

    public function markNumberReported(string $orderId): void
    {
        DatabaseProvider::getDb()->execute(
            'UPDATE everysize_checkout_order SET NUMBER_REPORTED = :now WHERE OXID = :id',
            [':now' => date('Y-m-d H:i:s'), ':id' => $orderId]
        );
    }

    public function isNumberReported(string $orderId): bool
    {
        return (bool) DatabaseProvider::getDb()->getOne(
            'SELECT 1 FROM everysize_checkout_order WHERE OXID = :id AND NUMBER_REPORTED IS NOT NULL',
            [':id' => $orderId]
        );
    }

    public function addReport(string $orderId, string $kind, string $sku, string $reason, string $result): void
    {
        DatabaseProvider::getDb()->execute(
            'INSERT INTO everysize_checkout_report (ORDERID, KIND, SKU, REASON, RESULT, CREATED)
             VALUES (:orderId, :kind, :sku, :reason, :result, :created)',
            [
                ':orderId' => $orderId,
                ':kind' => $kind,
                ':sku' => $sku,
                ':reason' => $reason,
                ':result' => $result,
                ':created' => date('Y-m-d H:i:s'),
            ]
        );
    }

    public function hasSentFulfillment(string $orderId): bool
    {
        return (bool) DatabaseProvider::getDb()->getOne(
            'SELECT 1 FROM everysize_checkout_report WHERE ORDERID = :orderId AND KIND = :kind AND RESULT = :result',
            [':orderId' => $orderId, ':kind' => self::KIND_FULFILLMENT, ':result' => self::RESULT_SENT]
        );
    }

    /**
     * @return array<int, array{KIND: string, SKU: string, REASON: string, RESULT: string, CREATED: string}>
     */
    public function getReports(string $orderId): array
    {
        return DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getAll(
            'SELECT KIND, SKU, REASON, RESULT, CREATED FROM everysize_checkout_report WHERE ORDERID = :orderId ORDER BY ID',
            [':orderId' => $orderId]
        );
    }

    /**
     * Shipped orders (shipping date set) whose fulfillment has not been
     * reported — also when the order or its item has been cancelled since:
     * shipped and cancelled within one sync interval is a return, and
     * everysize only takes RETOUR (with the tracking code) after the
     * fulfillment. The exporter sends fulfillments before revocations.
     * Orders whose revocation went out already are left out: everysize has
     * them revoked, a fulfillment would only be refused.
     *
     * @return array<int, array{OXID: string, EVERYSIZE_ORDER_ID: string, OXORDERNR: string, OXTRACKCODE: string}>
     */
    public function getPendingFulfillments(): array
    {
        return DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getAll(
            "SELECT o.OXID, l.EVERYSIZE_ORDER_ID, o.OXORDERNR, o.OXTRACKCODE
             FROM everysize_checkout_order l
             JOIN oxorder o ON o.OXID = l.OXID
             WHERE l.OXSHOPID = :shopId
               AND o.OXSENDDATE > '1000-01-01 00:00:00'
               AND NOT EXISTS (SELECT 1 FROM everysize_checkout_report r WHERE r.ORDERID = o.OXID AND r.KIND IN (:fulfillment, :revocation))
             ORDER BY o.OXORDERNR",
            [':shopId' => $this->shopId(), ':fulfillment' => self::KIND_FULFILLMENT, ':revocation' => self::KIND_REVOCATION]
        );
    }

    /**
     * Orders whose fulfillment was reported since $since and that now carry a
     * tracking code that was never reported: entered after "Jetzt versenden"
     * or corrected. Cancelled orders are left out.
     *
     * @return array<int, array{OXID: string, EVERYSIZE_ORDER_ID: string, OXORDERNR: string, OXTRACKCODE: string}>
     */
    public function getPendingTrackingCodes(string $since): array
    {
        return DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getAll(
            "SELECT o.OXID, l.EVERYSIZE_ORDER_ID, o.OXORDERNR, TRIM(o.OXTRACKCODE) AS OXTRACKCODE
             FROM everysize_checkout_order l
             JOIN oxorder o ON o.OXID = l.OXID
             JOIN everysize_checkout_report f ON f.ORDERID = o.OXID AND f.KIND = :fulfillment AND f.RESULT = :sent
             WHERE l.OXSHOPID = :shopId
               AND f.CREATED >= :since
               AND o.OXSTORNO = 0
               AND TRIM(o.OXTRACKCODE) <> ''
               AND EXISTS (SELECT 1 FROM oxorderarticles a WHERE a.OXORDERID = o.OXID AND a.OXSTORNO = 0)
               AND NOT EXISTS (
                   SELECT 1 FROM everysize_checkout_report t
                   WHERE t.ORDERID = o.OXID AND t.KIND = :tracking AND t.SKU = TRIM(o.OXTRACKCODE)
               )
             ORDER BY o.OXORDERNR",
            [
                ':shopId' => $this->shopId(),
                ':since' => $since,
                ':fulfillment' => self::KIND_FULFILLMENT,
                ':sent' => self::RESULT_SENT,
                ':tracking' => self::KIND_TRACKING,
            ]
        );
    }

    /**
     * Orders whose cancellation before shipping (MERCHANT_DECLINE) was reported
     * since $since, with no item left uncancelled, and with no refund through
     * everysize at all — automatic (done or given up) or from the tab. After
     * a deliberate partial refund the rest is not paid out on top. An
     * everysize order has one product; an item the merchant added in OXID
     * keeps the order from being refunded in full.
     *
     * @return array<int, array{OXID: string, EVERYSIZE_ORDER_ID: string, OXORDERNR: string}>
     */
    public function getPendingAutoRefunds(string $since): array
    {
        return DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getAll(
            "SELECT DISTINCT o.OXID, l.EVERYSIZE_ORDER_ID, o.OXORDERNR
             FROM everysize_checkout_report r
             JOIN oxorder o ON o.OXID = r.ORDERID
             JOIN everysize_checkout_order l ON l.OXID = o.OXID
             WHERE l.OXSHOPID = :shopId
               AND l.PAYPAL_CAPTURE_ID <> ''
               AND r.KIND = :revocation AND r.REASON = :decline AND r.RESULT = :sent
               AND r.CREATED >= :since
               AND NOT EXISTS (SELECT 1 FROM oxorderarticles i WHERE i.OXORDERID = o.OXID AND i.OXSTORNO = 0)
               AND NOT EXISTS (SELECT 1 FROM everysize_checkout_report a WHERE a.ORDERID = o.OXID AND a.KIND = :refund)
             ORDER BY o.OXORDERNR",
            [
                ':shopId' => $this->shopId(),
                ':revocation' => self::KIND_REVOCATION,
                ':decline' => Api\Client::REASON_MERCHANT_DECLINE,
                ':sent' => self::RESULT_SENT,
                ':since' => $since,
                ':refund' => self::KIND_REFUND,
            ]
        );
    }

    /**
     * Cancelled items (item or whole order cancelled in OXID) whose revocation
     * has not been reported.
     *
     * @return array<int, array{ORDERID: string, EVERYSIZE_ORDER_ID: string, OXORDERNR: string, SKU: string}>
     */
    public function getPendingRevocations(): array
    {
        return DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getAll(
            'SELECT o.OXID AS ORDERID, l.EVERYSIZE_ORDER_ID, o.OXORDERNR, i.SKU
             FROM everysize_checkout_order_item i
             JOIN oxorderarticles a ON a.OXID = i.OXID
             JOIN oxorder o ON o.OXID = a.OXORDERID
             JOIN everysize_checkout_order l ON l.OXID = o.OXID
             WHERE l.OXSHOPID = :shopId
               AND (a.OXSTORNO = 1 OR o.OXSTORNO = 1)
               AND NOT EXISTS (
                   SELECT 1 FROM everysize_checkout_report r
                   WHERE r.ORDERID = o.OXID AND r.KIND = :kind AND r.SKU = i.SKU
               )
             ORDER BY o.OXORDERNR',
            [':shopId' => $this->shopId(), ':kind' => self::KIND_REVOCATION]
        );
    }

    private function shopId(): int
    {
        return (int) Registry::getConfig()->getShopId();
    }
}
