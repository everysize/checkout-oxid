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

    /**
     * Marker of the cancellation check: the order is cancelled at everysize and the merchant was told (or needed no
     * hint). Unique per order (empty SKU). REASON is the kind of hint (OPEN or SHIPPED) or empty. At most 16
     * characters: the column KIND.
     */
    public const KIND_REVOKED_AT_EVERYSIZE = 'es-revoked';
    /** A hint for the merchant was written for the order (KIND_REVOKED_AT_EVERYSIZE). */
    public const RESULT_NOTED = 'noted';

    /** Sent to everysize. */
    public const RESULT_SENT = 'sent';
    /** Not sent: the order was already revoked at everysize. */
    public const RESULT_SKIPPED = 'skipped';
    /** Not sent: everysize already had it (a tracking code reported before the module remembered codes). */
    public const RESULT_KNOWN = 'known';
    /** The merchant marked an unclear refund as settled (KIND_REFUND, SKU "settled-<key>"). */
    public const RESULT_SETTLED = 'settled';

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

    /**
     * What is missing after a module update that was not finished (new files copied, migrations not run, module
     * not activated again): the table of the open refund, the extension of the order model that records a
     * discount. The automatic refund does not run while anything is missing.
     *
     * @return list<string>
     */
    public function getUpdateProblems(): array
    {
        $problems = [];
        if (!DatabaseProvider::getDb()->getOne("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'everysize_checkout_open_refund'")) {
            $problems[] = 'table everysize_checkout_open_refund is missing (run the migrations)';
        }
        if (!oxNew(\OxidEsales\Eshop\Application\Model\Order::class) instanceof Model\Order) {
            $problems[] = 'the order model extension is not active (deactivate and activate the module)';
        }

        return $problems;
    }

    public function hasReport(string $orderId, string $kind, string $sku): bool
    {
        return (bool) DatabaseProvider::getDb()->getOne(
            'SELECT 1 FROM everysize_checkout_report WHERE ORDERID = :orderId AND KIND = :kind AND SKU = :sku',
            [':orderId' => $orderId, ':kind' => $kind, ':sku' => $sku]
        );
    }

    public function removeReport(string $orderId, string $kind, string $sku): void
    {
        DatabaseProvider::getDb()->execute(
            'DELETE FROM everysize_checkout_report WHERE ORDERID = :orderId AND KIND = :kind AND SKU = :sku',
            [':orderId' => $orderId, ':kind' => $kind, ':sku' => $sku]
        );
    }

    /** A PayPal refund the shop booked itself (tab or automatic): by refund ID in SKU or, for the automatic one, in REASON. Only reports of the kind refund. */
    public function isKnownRefund(string $orderId, string $refundId): bool
    {
        return (bool) DatabaseProvider::getDb()->getOne(
            'SELECT 1 FROM everysize_checkout_report WHERE ORDERID = :orderId AND KIND = :kind AND (SKU = :id OR REASON = :id)',
            [':orderId' => $orderId, ':kind' => self::KIND_REFUND, ':id' => $refundId]
        );
    }

    /**
     * The refund of this order whose outcome is not known (table everysize_checkout_open_refund, one row per
     * order). Until it is settled no other refund goes out. sent = when the POST was last handed over (null:
     * never), knownIds = the PayPal refund IDs at the time the entry was made (null: not read yet), resultId =
     * the refund ID once the answer is known but not yet booked everywhere.
     *
     * @return array{key: string, amount: string, created: string, sent: ?string, knownIds: ?list<string>, resultId: string}|null
     */
    public function getOpenRefund(string $orderId): ?array
    {
        // getAll, not getRow: getRow swallows an SQL error (a missing table) and answers "nothing open".
        $rows = DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getAll(
            'SELECT IDEM_KEY, AMOUNT, CREATED, SENT_AT, KNOWN_IDS, RESULT_ID FROM everysize_checkout_open_refund WHERE ORDERID = :orderId',
            [':orderId' => $orderId]
        );
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];

        return [
            'key' => (string) $row['IDEM_KEY'],
            'amount' => (string) $row['AMOUNT'],
            'created' => (string) $row['CREATED'],
            'sent' => $row['SENT_AT'] !== null && $row['SENT_AT'] !== '' ? (string) $row['SENT_AT'] : null,
            'knownIds' => $row['KNOWN_IDS'] === null ? null : array_values(array_filter(explode(',', (string) $row['KNOWN_IDS']), static fn (string $id): bool => $id !== '')),
            'resultId' => (string) $row['RESULT_ID'],
        ];
    }

    /**
     * Reserves the refund before anything is read or sent. The primary key makes this atomic per order: a
     * second sender gets an exception and does not send.
     */
    public function openRefund(string $orderId, string $key, string $amount): void
    {
        DatabaseProvider::getDb()->execute(
            'INSERT INTO everysize_checkout_open_refund (ORDERID, IDEM_KEY, AMOUNT, CREATED) VALUES (:orderId, :key, :amount, :created)',
            [':orderId' => $orderId, ':key' => $key, ':amount' => $amount, ':created' => date('Y-m-d H:i:s')]
        );
    }

    /** The refund IDs PayPal lists at this moment, stored with the entry. */
    public function setKnownIds(string $orderId, string $key, array $refundIds): void
    {
        DatabaseProvider::getDb()->execute(
            'UPDATE everysize_checkout_open_refund SET KNOWN_IDS = :ids WHERE ORDERID = :orderId AND IDEM_KEY = :key',
            [':ids' => implode(',', $refundIds), ':orderId' => $orderId, ':key' => $key]
        );
    }

    /** The refund ID of the answer, noted before it is booked: from now on the entry is never sent again. */
    public function setResultId(string $orderId, string $key, string $resultId): void
    {
        DatabaseProvider::getDb()->execute(
            'UPDATE everysize_checkout_open_refund SET RESULT_ID = :id WHERE ORDERID = :orderId AND IDEM_KEY = :key',
            [':id' => $resultId, ':orderId' => $orderId, ':key' => $key]
        );
    }

    /**
     * Takes over the right to send, atomically: only the caller whose UPDATE still found the sent time he read
     * (null = never sent) gets it. Everybody else sends nothing.
     */
    public function claimSend(string $orderId, string $key, ?string $previousSent): bool
    {
        $db = DatabaseProvider::getDb();
        $token = bin2hex(random_bytes(8));
        $params = [':now' => date('Y-m-d H:i:s'), ':token' => $token, ':orderId' => $orderId, ':key' => $key];
        if ($previousSent !== null) {
            $params[':previous'] = $previousSent;
        }
        $db->execute(
            'UPDATE everysize_checkout_open_refund SET SENT_AT = :now, SEND_TOKEN = :token
             WHERE ORDERID = :orderId AND IDEM_KEY = :key AND '.($previousSent === null ? 'SENT_AT IS NULL' : 'SENT_AT = :previous'),
            $params
        );

        return $db->getOne(
            'SELECT SEND_TOKEN FROM everysize_checkout_open_refund WHERE ORDERID = :orderId AND IDEM_KEY = :key',
            [':orderId' => $orderId, ':key' => $key]
        ) === $token;
    }

    /** The entry is settled. Only the very entry the caller knows is removed (key and amount): a late answer must not delete a newer one. */
    public function closeRefund(string $orderId, string $key, string $amount): void
    {
        DatabaseProvider::getDb()->execute(
            'DELETE FROM everysize_checkout_open_refund WHERE ORDERID = :orderId AND IDEM_KEY = :key AND AMOUNT = :amount',
            [':orderId' => $orderId, ':key' => $key, ':amount' => $amount]
        );
    }

    /**
     * "Mark as settled": removes the entry only if it is the given one and the last hand-over (or, if never
     * sent, its creation) is at least $seconds ago. True if it is gone afterwards.
     */
    public function removeOpenRefundIfOld(string $orderId, string $key, int $seconds): bool
    {
        $db = DatabaseProvider::getDb();
        $db->execute(
            'DELETE FROM everysize_checkout_open_refund
             WHERE ORDERID = :orderId AND IDEM_KEY = :key AND COALESCE(SENT_AT, CREATED) <= :cutoff',
            [':orderId' => $orderId, ':key' => $key, ':cutoff' => date('Y-m-d H:i:s', time() - $seconds)]
        );

        return !$db->getOne(
            'SELECT 1 FROM everysize_checkout_open_refund WHERE ORDERID = :orderId AND IDEM_KEY = :key',
            [':orderId' => $orderId, ':key' => $key]
        );
    }

    /**
     * Open refunds created more than $seconds ago, for the protocol.
     *
     * @return array<int, array{ORDER_ID: string, CREATED: string}>
     */
    public function getStaleOpenRefunds(int $seconds): array
    {
        return DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getAll(
            'SELECT ORDERID AS ORDER_ID, CREATED FROM everysize_checkout_open_refund WHERE CREATED < :before ORDER BY CREATED',
            [':before' => date('Y-m-d H:i:s', time() - $seconds)]
        );
    }

    /**
     * Orders with an open refund of the automatic run (key "auto-decline-…"): each run only checks the PayPal
     * list for them, it never sends again.
     *
     * @return array<int, array{OXID: string, EVERYSIZE_ORDER_ID: string, OXORDERNR: string}>
     */
    public function getOpenAutoRefunds(): array
    {
        return DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getAll(
            'SELECT o.OXID, l.EVERYSIZE_ORDER_ID, o.OXORDERNR
             FROM everysize_checkout_open_refund r
             JOIN oxorder o ON o.OXID = r.ORDERID
             JOIN everysize_checkout_order l ON l.OXID = o.OXID
             WHERE l.OXSHOPID = :shopId AND r.IDEM_KEY LIKE :auto
             ORDER BY o.OXORDERNR',
            [':shopId' => $this->shopId(), ':auto' => self::AUTO_REFUND.'-%']
        );
    }

    /**
     * The order shows a sign of shipping: a shipping date, a tracking code (also
     * one entered by hand) or a shipping or tracking report of any result.
     */
    public function isShipped(string $orderId): bool
    {
        return (bool) DatabaseProvider::getDb()->getOne(
            "SELECT 1 FROM oxorder o
             WHERE o.OXID = :orderId
               AND (o.OXSENDDATE > '1000-01-01 00:00:00'
                    OR TRIM(o.OXTRACKCODE) <> ''
                    OR EXISTS (SELECT 1 FROM everysize_checkout_report sr WHERE sr.ORDERID = o.OXID AND sr.KIND IN (:fulfillment, :tracking)))",
            [':orderId' => $orderId, ':fulfillment' => self::KIND_FULFILLMENT, ':tracking' => self::KIND_TRACKING]
        );
    }

    /**
     * What the cancellation check needs to know about the shop order: its number and language, whether it is
     * closed (the whole order or every item cancelled in OXID, a cancellation this module reported itself, which
     * means it came from the shop, or the whole amount refunded: see isFullyRefunded) and whether shipping shows.
     * A partial refund (goodwill) does not close it. $paidCents is what the customer paid (everysize grossPrice);
     * without it the total of the shop order counts. null: there is no such order any more.
     *
     * @return array{number: string, lang: int, closed: bool, shipped: bool}|null
     */
    public function getRevokedCheckInfo(string $orderId, ?int $paidCents = null): ?array
    {
        $row = DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getRow(
            'SELECT o.OXORDERNR, o.OXLANG, o.OXSTORNO, o.OXTOTALORDERSUM,
                    (SELECT COUNT(*) FROM oxorderarticles a WHERE a.OXORDERID = o.OXID) AS ITEMS,
                    (SELECT COUNT(*) FROM oxorderarticles a WHERE a.OXORDERID = o.OXID AND a.OXSTORNO = 0) AS ACTIVE_ITEMS,
                    (SELECT COUNT(*) FROM everysize_checkout_report r WHERE r.ORDERID = o.OXID AND r.KIND = :revocation) AS REVOCATIONS
             FROM oxorder o
             WHERE o.OXID = :orderId',
            [':orderId' => $orderId, ':revocation' => self::KIND_REVOCATION]
        );
        if (!$row) {
            return null;
        }

        $closed = (int) $row['OXSTORNO'] === 1
            || ((int) $row['ITEMS'] > 0 && (int) $row['ACTIVE_ITEMS'] === 0)
            || (int) $row['REVOCATIONS'] > 0;
        if (!$closed) {
            $paid = $paidCents !== null && $paidCents > 0 ? $paidCents : (int) round((float) $row['OXTOTALORDERSUM'] * 100);
            $closed = $this->isFullyRefunded($orderId, $paid);
        }

        return [
            'number' => (string) $row['OXORDERNR'],
            'lang' => (int) $row['OXLANG'],
            'closed' => $closed,
            'shipped' => $this->isShipped($orderId),
        ];
    }

    /**
     * The refunds through everysize that were reported for the order (result sent) add up to the whole amount
     * paid. The automatic refund after a cancellation is always the whole amount. Open or unclear refunds
     * (open entry, "settled", "known") count for nothing. Compared in cents.
     */
    public function isFullyRefunded(string $orderId, int $paidCents): bool
    {
        $rows = DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getAll(
            'SELECT SKU, REASON FROM everysize_checkout_report WHERE ORDERID = :orderId AND KIND = :kind AND RESULT = :sent',
            [':orderId' => $orderId, ':kind' => self::KIND_REFUND, ':sent' => self::RESULT_SENT]
        );

        return self::coversPaid($rows, $paidCents);
    }

    /**
     * @param array<int, array{SKU: string, REASON: string}> $refundReports reports of kind refund with result sent
     */
    public static function coversPaid(array $refundReports, int $paidCents): bool
    {
        $sum = 0;
        foreach ($refundReports as $report) {
            if ((string) $report['SKU'] === self::AUTO_REFUND) {
                return true;
            }
            try {
                $sum += Api\Amount::cents((string) $report['REASON']);
            } catch (Api\ApiException $e) {
                // no readable amount: it counts for nothing
            }
        }

        return $paidCents > 0 && $sum >= $paidCents;
    }

    /**
     * Orders of the given list that carry the marker of the cancellation check with a hint (for the order list and
     * the tab). Orders that are closed in the shop by now (cancelled, refunded in full, every item cancelled) are
     * left out: there is nothing left to warn about.
     *
     * @param array<int, string> $orderIds
     *
     * @return array<string, string> order id => REASON (OPEN or SHIPPED)
     */
    public function getRevokedNotes(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $notes = [];
        foreach ($this->getRevokedMarkers($orderIds) as $row) {
            $info = $this->getRevokedCheckInfo((string) $row['ORDERID']);
            if ($info !== null && !$info['closed']) {
                $notes[(string) $row['ORDERID']] = (string) $row['REASON'];
            }
        }

        return $notes;
    }

    /**
     * @param array<int, string> $orderIds
     *
     * @return array<int, array{ORDERID: string, REASON: string}> markers of the cancellation check that carry a hint
     */
    protected function getRevokedMarkers(array $orderIds): array
    {
        $db = DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC);

        return $db->getAll(
            'SELECT ORDERID, REASON FROM everysize_checkout_report WHERE KIND = '.$db->quote(self::KIND_REVOKED_AT_EVERYSIZE)
            .' AND RESULT = '.$db->quote(self::RESULT_NOTED).' AND ORDERID IN ('.implode(',', array_map([$db, 'quote'], $orderIds)).')'
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
     * everysize at all — automatic (done or given up) or from the tab — and no
     * refund of unknown outcome. After a deliberate partial refund the rest is
     * not paid out on top. An everysize order has one product; an item the
     * merchant added in OXID keeps the order from being refunded in full.
     * SHIPPED = 1 marks orders that show a sign of shipping (shipping date,
     * tracking code, shipping or tracking report): the exporter does not refund them.
     *
     * DISCOUNTED = 1: the merchant gave a discount in OXID (OXDISCOUNT, OXVOUCHERDISCOUNT): a real intervention,
     * unlike the lowered total after a position was cancelled (recalculateOrder), which is no reason to block.
     * $onlyOrderId asks the same question for one order (the exporter asks again right before sending).
     *
     * @return array<int, array{OXID: string, EVERYSIZE_ORDER_ID: string, OXORDERNR: string, SHIPPED: string, DISCOUNTED: string}>
     */
    public function getPendingAutoRefunds(string $since, ?string $onlyOrderId = null): array
    {
        return DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getAll(
            "SELECT DISTINCT o.OXID, l.EVERYSIZE_ORDER_ID, o.OXORDERNR,
                    CASE WHEN o.OXSENDDATE > '1000-01-01 00:00:00'
                           OR TRIM(o.OXTRACKCODE) <> ''
                           OR EXISTS (SELECT 1 FROM everysize_checkout_report sr WHERE sr.ORDERID = o.OXID AND sr.KIND IN (:shipFulfillment, :shipTracking))
                         THEN 1 ELSE 0 END AS SHIPPED,
                    CASE WHEN o.OXDISCOUNT <> 0 OR o.OXVOUCHERDISCOUNT <> 0 THEN 1 ELSE 0 END AS DISCOUNTED
             FROM everysize_checkout_report r
             JOIN oxorder o ON o.OXID = r.ORDERID
             JOIN everysize_checkout_order l ON l.OXID = o.OXID
             WHERE l.OXSHOPID = :shopId
               AND l.PAYPAL_CAPTURE_ID <> ''
               AND r.KIND = :revocation AND r.REASON = :decline AND r.RESULT = :sent
               AND r.CREATED >= :since
               AND NOT EXISTS (SELECT 1 FROM oxorderarticles i WHERE i.OXORDERID = o.OXID AND i.OXSTORNO = 0)
               AND NOT EXISTS (SELECT 1 FROM everysize_checkout_report a WHERE a.ORDERID = o.OXID AND a.KIND = :refund)
               AND NOT EXISTS (SELECT 1 FROM everysize_checkout_open_refund u WHERE u.ORDERID = o.OXID)
               ".($onlyOrderId !== null ? 'AND o.OXID = :only' : '').'
             ORDER BY o.OXORDERNR',
            [
                ':shopId' => $this->shopId(),
                ':revocation' => self::KIND_REVOCATION,
                ':decline' => Api\Client::REASON_MERCHANT_DECLINE,
                ':sent' => self::RESULT_SENT,
                ':since' => $since,
                ':refund' => self::KIND_REFUND,
                ':shipFulfillment' => self::KIND_FULFILLMENT,
                ':shipTracking' => self::KIND_TRACKING,
            ] + ($onlyOrderId !== null ? [':only' => $onlyOrderId] : [])
        );
    }

    /**
     * Cancellations before shipping (MERCHANT_DECLINE reported within $days days) that the automatic refund
     * leaves out silently: reported before the cut-off date of the setting, or with an item still active (the
     * merchant added one: an everysize order has one product). The customer would wait for her money
     * unnoticed, so the exporter writes a note once.
     *
     * @return array<int, array{OXID: string, EVERYSIZE_ORDER_ID: string, OXORDERNR: string, CODE: string}>
     */
    public function getUnhandledDeclines(string $since, int $days = 60): array
    {
        return DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getAll(
            "SELECT DISTINCT o.OXID, l.EVERYSIZE_ORDER_ID, o.OXORDERNR,
                    CASE WHEN EXISTS (SELECT 1 FROM oxorderarticles i WHERE i.OXORDERID = o.OXID AND i.OXSTORNO = 0)
                         THEN 'ITEMS_ACTIVE' ELSE 'BEFORE_CUTOFF' END AS CODE
             FROM everysize_checkout_report r
             JOIN oxorder o ON o.OXID = r.ORDERID
             JOIN everysize_checkout_order l ON l.OXID = o.OXID
             WHERE l.OXSHOPID = :shopId
               AND l.PAYPAL_CAPTURE_ID <> ''
               AND r.KIND = :revocation AND r.REASON = :decline AND r.RESULT = :sent
               AND r.CREATED >= :recent
               AND (r.CREATED < :since OR EXISTS (SELECT 1 FROM oxorderarticles i2 WHERE i2.OXORDERID = o.OXID AND i2.OXSTORNO = 0))
               AND NOT EXISTS (SELECT 1 FROM everysize_checkout_report a WHERE a.ORDERID = o.OXID AND a.KIND = :refund)
               AND NOT EXISTS (SELECT 1 FROM everysize_checkout_open_refund u WHERE u.ORDERID = o.OXID)
             ORDER BY o.OXORDERNR",
            [
                ':shopId' => $this->shopId(),
                ':revocation' => self::KIND_REVOCATION,
                ':decline' => Api\Client::REASON_MERCHANT_DECLINE,
                ':sent' => self::RESULT_SENT,
                ':since' => $since,
                ':recent' => date('Y-m-d H:i:s', time() - $days * 86400),
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
