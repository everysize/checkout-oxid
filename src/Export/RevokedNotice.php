<?php

declare(strict_types=1);

namespace Everysize\Checkout\Export;

use Everysize\Checkout\Api\Amount;
use Everysize\Checkout\Api\ApiException;
use Everysize\Checkout\Api\Client;
use Everysize\Checkout\OrderLinks;
use Everysize\Checkout\Protocol;
use Everysize\Checkout\Settings;
use OxidEsales\Eshop\Core\Registry;

/**
 * Cancellation check: an order that was imported and is cancelled at everysize afterwards gets a hint for the
 * merchant (tab "everysize" of the order, marked in the order list, entry in the protocol). The hint is for the
 * merchant only: it is no remark of the order (oxorder.OXREMARK is the customer's own text and shown to the
 * customer), and no e-mail is sent.
 *
 * Runs after import and export and must never disturb them: run() does not throw, it only counts. It changes
 * nothing about the order (no cancellation, no stock, no refund) and reports nothing to everysize. The marker (kind
 * es-revoked, unique per order) makes the hint appear exactly once. The hint stays only while there is something to
 * warn about: once the order is cancelled or refunded in full in the shop it is not shown any more (a partial
 * refund does not close the order).
 *
 * Errors of this step reach the protocol at most once per day and kind of error (the list cannot be read / an
 * order failed: all order errors are one kind); everything above goes to the technical log of OXID only. Without
 * that limit an outage of the API would fill the protocol every five minutes.
 */
class RevokedNotice
{
    /** Orders created within this many days are checked. */
    public const WINDOW_DAYS = 30;

    public const OPEN = 'OPEN';
    public const SHIPPED = 'SHIPPED';

    private const TEXTS = [
        self::OPEN => [
            'de' => 'Bestellung %s wurde bei everysize storniert. Bitte nicht versenden und im Shop stornieren.',
            'en' => 'Order %s was cancelled at everysize. Please do not ship it and cancel it in the shop.',
        ],
        self::SHIPPED => [
            'de' => 'Bestellung %s wurde bei everysize storniert, ist hier aber schon als versendet markiert. Bitte prüfen.',
            'en' => 'Order %s was cancelled at everysize but is already marked as shipped here. Please check.',
        ],
    ];

    private Client $client;
    private OrderLinks $links;
    private Protocol $protocol;
    private ?Settings $settings;
    private string $today = '';

    /** Setting names (table everysize_checkout_settings) that remember the day of the last warning per kind. */
    private const THROTTLE_LIST = 'revokedWarnList';
    private const THROTTLE_ORDER = 'revokedWarnOrder';

    public function __construct(Client $client, OrderLinks $links, Protocol $protocol, ?Settings $settings = null)
    {
        $this->client = $client;
        $this->links = $links;
        $this->protocol = $protocol;
        $this->settings = $settings;
    }

    /**
     * The hint in German or English (anything else: English). Only the order number, no customer data.
     */
    public static function text(string $kind, string $iso, string $orderNumber): string
    {
        $texts = self::TEXTS[$kind] ?? self::TEXTS[self::OPEN];

        return sprintf($texts[strtolower($iso) === 'de' ? 'de' : 'en'], $orderNumber);
    }

    /**
     * @return array{revoked: int, noted: int, closed: int, skipped: int, warnings: int}
     */
    public function run(?\DateTimeImmutable $now = null): array
    {
        $result = ['revoked' => 0, 'noted' => 0, 'closed' => 0, 'skipped' => 0, 'warnings' => 0];

        try {
            $now = $now ?? new \DateTimeImmutable('now');
            $this->today = $now->format('Y-m-d');
            $orders = $this->client->getRevokedOrders($now->modify('-'.self::WINDOW_DAYS.' days'), $now);
        } catch (\Throwable $e) {
            $this->warn($result, self::THROTTLE_LIST, 'everysize: cancellation check skipped', $e->getMessage());

            return $result;
        }

        $result['revoked'] = \count($orders);
        foreach ($orders as $order) {
            try {
                $this->handle($order, $result);
            } catch (\Throwable $e) {
                $this->warn($result, self::THROTTLE_ORDER, sprintf('everysize: cancellation check failed for order %s', (string) ($order['everysizeOrderId'] ?? '?')), $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, int>   $result
     */
    private function handle(array $order, array &$result): void
    {
        $everysizeOrderId = (string) $order['everysizeOrderId'];
        $orderId = $this->links->findOrderId($everysizeOrderId);
        if ($orderId === null) {
            ++$result['skipped']; // not linked to a shop order

            return;
        }
        if ($this->links->hasReport($orderId, OrderLinks::KIND_REVOKED_AT_EVERYSIZE, '')) {
            ++$result['skipped']; // done already

            return;
        }

        // What the customer paid, in cents (grossPrice of the everysize order); unreadable: the total of the shop order counts.
        try {
            $paidCents = Amount::cents($order['grossPrice'] ?? null, 'grossPrice');
        } catch (ApiException $e) {
            $paidCents = null;
        }
        $info = $this->links->getRevokedCheckInfo($orderId, $paidCents);
        if ($info === null) {
            ++$result['skipped']; // the order is deleted in the shop

            return;
        }

        // Cancelled or refunded in full in the shop already (also: this module reported the cancellation itself): marker only.
        if ($info['closed']) {
            $this->links->addReport($orderId, OrderLinks::KIND_REVOKED_AT_EVERYSIZE, '', '', OrderLinks::RESULT_SKIPPED);
            ++$result['closed'];

            return;
        }

        $kind = $info['shipped'] ? self::SHIPPED : self::OPEN;
        $text = self::text($kind, $this->isoOf($info['lang']), $info['number']);

        // Marker first: a second run never writes a second hint. If that fails, nothing is hinted and the next run tries again.
        $this->links->addReport($orderId, OrderLinks::KIND_REVOKED_AT_EVERYSIZE, '', $kind, OrderLinks::RESULT_NOTED);
        try {
            $this->protocol->warn($text);
        } catch (\Throwable $e) {
            // The hint itself is the marker row (tab, order list); the protocol line is an addition.
        }
        ++$result['noted'];
    }

    /** Language abbreviation of an OXID language id (de, en, ...). */
    protected function isoOf(int $languageId): string
    {
        return (string) Registry::getLang()->getLanguageAbbr($languageId);
    }

    /**
     * A warning in the protocol, never an error: the check does not count as a failure of the run. At most one
     * warning per day and kind reaches the protocol, the rest goes to the technical log. If the limit itself
     * fails, nothing is written to the protocol.
     *
     * @param array<string, int> $result
     */
    private function warn(array &$result, string $kind, string $title, string $message): void
    {
        ++$result['warnings'];
        try {
            if ($this->mayWriteToProtocol($kind)) {
                $this->protocol->warn($title, $message);

                return;
            }
        } catch (\Throwable $e) {
            // the protocol and the limit must never disturb
        }
        try {
            $this->log($title, $message);
        } catch (\Throwable $e) {
            // dropped
        }
    }

    /** true once per day and kind (the day is remembered in the settings table); false if the limit cannot be read or written. */
    private function mayWriteToProtocol(string $kind): bool
    {
        if ($this->settings === null || $this->today === '') {
            return false;
        }
        try {
            if ($this->settings->get($kind) === $this->today) {
                return false;
            }
            $this->settings->set($kind, $this->today);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    /** The technical log of OXID (var/log). */
    protected function log(string $title, string $message): void
    {
        Registry::getLogger()->warning($title.($message !== '' ? ': '.$message : ''));
    }
}
