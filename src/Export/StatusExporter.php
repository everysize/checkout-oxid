<?php

declare(strict_types=1);

namespace Everysize\Checkout\Export;

use Everysize\Checkout\Api\Amount;
use Everysize\Checkout\Api\ApiException;
use Everysize\Checkout\Api\Client;
use Everysize\Checkout\ManualRefund;
use Everysize\Checkout\OrderLinks;
use Everysize\Checkout\Settings;

/**
 * Reports shipping, cancellations and returns from OXID to everysize.
 *
 * - Shipping date set in OXID ("Jetzt versenden") → fulfillment with the
 *   order's tracking code and the configured carrier, also if the order was
 *   cancelled since (shipped and cancelled within one interval: fulfillment
 *   first, then RETOUR in the same run).
 * - Tracking code entered or corrected after that, within TRACKING_DAYS →
 *   the fulfillment once more with the new code. everysize takes a repeated
 *   fulfillment as an update of carrier and code; the customer gets no mail.
 * - Item or order cancelled in OXID before the fulfillment was reported →
 *   revocation MERCHANT_DECLINE; cancelled after it → RETOUR (OXID has no
 *   return status; merchants book returns by cancelling the item, which also
 *   puts the stock back).
 *
 * Before reporting, the order is fetched from everysize: if it was revoked
 * there in the meantime (PayPal refund, partner backoffice), nothing is sent.
 * A fulfillment would set a revoked order back to COMPLETED, a revocation
 * would overwrite everysize's own reason. A late tracking code goes out only
 * while the order is COMPLETED at everysize, and only if everysize does not
 * have that code yet (orders shipped with 1.0.0 have no remembered code).
 *
 * A cancellation before shipping (MERCHANT_DECLINE) is refunded in full
 * through everysize afterwards, unless switched off or something was refunded
 * through everysize already: nothing was shipped, so the customer is owed
 * everything — but after a deliberate partial refund the rest is not paid
 * out on top. It is done only when it is sure: the order shows no sign of
 * shipping, everysize lists no completed or pending refund (the list must be
 * readable, otherwise the run skips the order) and no refund of unknown outcome
 * is open. Returns are left to the merchant (goods first). The idempotency key
 * is fixed per order. A refusal is recorded once; no answer or a server error
 * leaves the result unclear: the call is written to the database first and
 * blocks every further automatic refund until the merchant has checked PayPal
 * and repeated it in the order tab.
 */
class StatusExporter
{
    /** A tracking code entered later is reported up to this many days after shipping. */
    public const TRACKING_DAYS = 14;

    /** Open refunds older than this are reported in every run. */
    public const STALE_OPEN_SECONDS = 900;

    private Client $client;
    private Settings $settings;
    private OrderLinks $links;

    public function __construct(Client $client, Settings $settings, OrderLinks $links)
    {
        $this->client = $client;
        $this->settings = $settings;
        $this->links = $links;
    }

    /**
     * @return array{fulfillments: int, trackingCodes: int, revocations: int, returns: int, autoRefunds: int, skipped: int, pending: int, errors: string[]}
     */
    public function export(): array
    {
        $result = ['fulfillments' => 0, 'trackingCodes' => 0, 'revocations' => 0, 'returns' => 0, 'autoRefunds' => 0, 'skipped' => 0, 'pending' => 0, 'errors' => []];

        // Orders whose shipping report failed in this run: their return is not reported before the shipping is.
        $failedShipping = [];

        foreach ($this->links->getPendingFulfillments() as $row) {
            ++$result['pending'];
            try {
                if ($this->isRevokedAtEverysize($row['EVERYSIZE_ORDER_ID'])) {
                    $this->links->addReport($row['OXID'], OrderLinks::KIND_FULFILLMENT, '', '', OrderLinks::RESULT_SKIPPED);
                    ++$result['skipped'];
                    $result['errors'][] = sprintf(
                        'Order %s (%s) is revoked at everysize, shipping not reported',
                        $row['OXORDERNR'],
                        $row['EVERYSIZE_ORDER_ID']
                    );
                    continue;
                }

                $trackingCode = trim((string) $row['OXTRACKCODE']);
                $this->client->sendFulfillment($row['EVERYSIZE_ORDER_ID'], $this->settings->getCarrier(), $trackingCode);
                $this->links->addReport($row['OXID'], OrderLinks::KIND_FULFILLMENT, '', '', OrderLinks::RESULT_SENT);
                if ($this->sendsTracking($trackingCode)) {
                    $this->links->addReport($row['OXID'], OrderLinks::KIND_TRACKING, $trackingCode, '', OrderLinks::RESULT_SENT);
                }
                ++$result['fulfillments'];
            } catch (ApiException $e) {
                $failedShipping[$row['OXID']] = true;
                $result['errors'][] = sprintf('Order %s (%s): %s', $row['OXORDERNR'], $row['EVERYSIZE_ORDER_ID'], $e->getMessage());
            }
        }

        // Without a carrier the API takes no tracking code (Client::sendFulfillment): nothing to add later.
        $since = date('Y-m-d H:i:s', time() - self::TRACKING_DAYS * 86400);
        $late = $this->settings->getCarrier() !== '' ? $this->links->getPendingTrackingCodes($since) : [];
        foreach ($late as $row) {
            ++$result['pending'];
            $trackingCode = $row['OXTRACKCODE'];
            try {
                $order = $this->client->getOrder($row['EVERYSIZE_ORDER_ID']);
                $status = strtoupper((string) ($order['status'] ?? ''));
                if ($status !== Client::STATUS_COMPLETED) {
                    $this->links->addReport($row['OXID'], OrderLinks::KIND_TRACKING, $trackingCode, '', OrderLinks::RESULT_SKIPPED);
                    ++$result['skipped'];
                    $result['errors'][] = sprintf(
                        'Order %s (%s) is %s at everysize, tracking code %s not reported',
                        $row['OXORDERNR'],
                        $row['EVERYSIZE_ORDER_ID'],
                        $status !== '' ? $status : 'unknown',
                        $trackingCode
                    );
                    continue;
                }

                if (self::trackingCodeOf($order) === $trackingCode) {
                    $this->links->addReport($row['OXID'], OrderLinks::KIND_TRACKING, $trackingCode, '', OrderLinks::RESULT_KNOWN);
                    continue;
                }

                $this->client->sendFulfillment($row['EVERYSIZE_ORDER_ID'], $this->settings->getCarrier(), $trackingCode);
                $this->links->addReport($row['OXID'], OrderLinks::KIND_TRACKING, $trackingCode, '', OrderLinks::RESULT_SENT);
                ++$result['trackingCodes'];
            } catch (ApiException $e) {
                $result['errors'][] = sprintf('Order %s (%s), tracking code %s: %s', $row['OXORDERNR'], $row['EVERYSIZE_ORDER_ID'], $trackingCode, $e->getMessage());
            }
        }

        foreach ($this->links->getPendingRevocations() as $row) {
            ++$result['pending'];
            // A shipped order is a return, not a decline - also when the shipping report has not gone out yet.
            $isReturn = $this->links->hasSentFulfillment($row['ORDERID']) || $this->links->isShipped($row['ORDERID']);
            if ($isReturn && isset($failedShipping[$row['ORDERID']])) {
                continue; // the shipping report comes first and is retried in the next run
            }
            $reason = $isReturn ? Client::REASON_RETOUR : Client::REASON_MERCHANT_DECLINE;
            try {
                if ($this->isRevokedAtEverysize($row['EVERYSIZE_ORDER_ID'])) {
                    $this->links->addReport($row['ORDERID'], OrderLinks::KIND_REVOCATION, $row['SKU'], $reason, OrderLinks::RESULT_SKIPPED);
                    ++$result['skipped'];
                    continue;
                }

                $this->client->sendRevocation($row['EVERYSIZE_ORDER_ID'], $row['SKU'], $reason);
                $this->links->addReport($row['ORDERID'], OrderLinks::KIND_REVOCATION, $row['SKU'], $reason, OrderLinks::RESULT_SENT);
                ++$result[$isReturn ? 'returns' : 'revocations'];
            } catch (ApiException $e) {
                $result['errors'][] = sprintf('Order %s (%s), %s: %s', $row['OXORDERNR'], $row['EVERYSIZE_ORDER_ID'], $row['SKU'], $e->getMessage());
            }
        }

        // A module update that was not finished (migrations, activation) switches the refund part off, loudly.
        $problems = $this->links->getUpdateProblems();
        if ($problems !== []) {
            $result['errors'][] = 'The module update is not finished, the automatic refund is off: '.implode('; ', $problems);

            return $result;
        }

        foreach ($this->links->getStaleOpenRefunds(self::STALE_OPEN_SECONDS) as $row) {
            $result['errors'][] = sprintf(
                'Order %s: a refund with an unclear result has been open since %s - check PayPal, then repeat it or mark it as settled in the order box "everysize"',
                $row['ORDER_ID'],
                $row['CREATED']
            );
        }

        $refunds = new ManualRefund($this->client, $this->links);

        // Open refunds of the automatic run: the run never sends them again, it only reads the PayPal list.
        foreach ($this->links->getOpenAutoRefunds() as $row) {
            ++$result['pending'];
            $label = sprintf('Order %s (%s)', $row['OXORDERNR'], $row['EVERYSIZE_ORDER_ID']);
            try {
                $checked = $refunds->checkOpenAutomatic($row['OXID'], $row['EVERYSIZE_ORDER_ID']);
                if ($checked['outcome'] === ManualRefund::FOUND || $checked['outcome'] === ManualRefund::DONE) {
                    $result['errors'][] = sprintf('%s: the automatic refund was found in the PayPal list and booked, nothing was sent again', $label);
                }
            } catch (\Throwable $e) {
                $result['errors'][] = sprintf('%s: checking the open automatic refund failed (checked again next run): %s', $label, $e->getMessage());
            }
        }

        // From when the setting was switched on, and no older than TRACKING_DAYS (a lasting outage is not retried forever).
        $since = $this->settings->getAutoRefundSince();
        $since = $since !== '' ? max($since, date('Y-m-d H:i:s', time() - self::TRACKING_DAYS * 86400)) : '';
        if (!$this->settings->isAutoRefundDecline() || $since === '') {
            return $result;
        }

        foreach ($this->links->getPendingAutoRefunds($since) as $candidate) {
            ++$result['pending'];
            try {
                // The setting and every condition are asked again right before the one send, for exactly this order.
                if (!$this->settings->isAutoRefundDecline()) {
                    break;
                }
                $fresh = $this->links->getPendingAutoRefunds($since, $candidate['OXID']);
                if ($fresh === []) {
                    continue;
                }
                $row = $fresh[0];
                if ((int) ($row['SHIPPED'] ?? 0) === 1) {
                    $this->blockAutoRefund($row, 'SHIPPED', 'it shows signs of shipping (shipping date, tracking code or shipping report)', $result);
                    continue;
                }
                if ((int) ($row['DISCOUNTED'] ?? 0) === 1) {
                    $this->blockAutoRefund($row, 'DISCOUNT', 'the merchant gave a discount in OXID (the amount owed is not clear)', $result);
                    continue;
                }
                $everysizeOrder = $this->client->getOrder($row['EVERYSIZE_ORDER_ID']);
                try {
                    Amount::parse($everysizeOrder['grossPrice'] ?? null, 'grossPrice');
                } catch (ApiException $e) {
                    $this->blockAutoRefund($row, 'TOTAL_UNKNOWN', 'the amount paid is missing or unreadable in the everysize order', $result);
                    continue;
                }
                $this->autoRefund($refunds, $row, $result);
            } catch (\Throwable $e) {
                $result['errors'][] = sprintf('Order %s (%s): automatic refund stopped by an error (checked again next run): %s', $candidate['OXORDERNR'], $candidate['EVERYSIZE_ORDER_ID'], $e->getMessage());
            }
        }

        // A cancellation the automatic refund leaves out silently is announced once: the customer waits for her money.
        foreach ($this->links->getUnhandledDeclines($since) as $row) {
            try {
                $text = $row['CODE'] === 'ITEMS_ACTIVE'
                    ? 'an item of the order is still active (the merchant added one: an everysize order has one product)'
                    : 'the cancellation was reported before the switch-on date of the automatic refund';
                $this->blockAutoRefund($row, $row['CODE'], $text, $result);
            } catch (\Throwable $e) {
                $result['errors'][] = sprintf('Order %s (%s): the note about a skipped automatic refund could not be written: %s', $row['OXORDERNR'], $row['EVERYSIZE_ORDER_ID'], $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * One automatic refund through ManualRefund (the only send of the automatic run); the outcome decides
     * what is recorded.
     *
     * @param array<string, string|null> $row
     * @param array<string, mixed>       $result
     */
    private function autoRefund(ManualRefund $refunds, array $row, array &$result): void
    {
        $label = sprintf('Order %s (%s)', $row['OXORDERNR'], $row['EVERYSIZE_ORDER_ID']);
        $outcome = $refunds->autoRefund($row['OXID'], $row['EVERYSIZE_ORDER_ID']);

        switch ($outcome['outcome']) {
            case ManualRefund::DONE:
                ++$result['autoRefunds'];
                break;
            case ManualRefund::EXISTS:
                $this->blockAutoRefund($row, 'REFUND_EXISTS', 'everysize already lists a refund of this payment', $result);
                break;
            case ManualRefund::REJECTED:
                // Safely refused (no money moved): recorded once, the merchant refunds in the order box if wanted.
                $this->links->addReport($row['OXID'], OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, mb_substr($outcome['reason'], 0, 255), OrderLinks::RESULT_SKIPPED);
                $result['errors'][] = sprintf('%s: automatic refund after cancellation was refused, please refund in the order tab "everysize": %s', $label, $outcome['message']);
                break;
            case ManualRefund::UNCLEAR:
                $result['errors'][] = sprintf(
                    '%s: automatic refund after cancellation has an unclear result, the money may have been refunded - the next runs only read the PayPal list; check PayPal, then repeat it or mark it as settled in the order tab "everysize": %s',
                    $label,
                    $outcome['message']
                );
                break;
            case ManualRefund::NOT_SENT:
            case ManualRefund::LIST_UNREADABLE:
                $result['errors'][] = sprintf('%s: automatic refund skipped, nothing was sent (checked again next run): %s', $label, $outcome['message'] !== '' ? $outcome['message'] : 'the PayPal refund list could not be read');
                break;
            default: // RUNNING, OTHER_AMOUNT (an open refund of a person), ALREADY_REFUNDED: nothing to do now
                break;
        }
    }

    /**
     * No automatic refund for this order, ever: recorded as a refund report (which
     * keeps the query from offering it again, and shows in the order tab as "not
     * refunded - please refund yourself") and in the protocol, so the merchant decides.
     *
     * @param array<string, string|null> $row
     * @param array{errors: string[]}    $result
     */
    private function blockAutoRefund(array $row, string $code, string $why, array &$result): void
    {
        $this->links->addReport($row['OXID'], OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, $code, OrderLinks::RESULT_SKIPPED);
        $result['errors'][] = sprintf('Order %s (%s): no automatic refund after cancellation, %s - please check and refund in the order tab "everysize" if needed', $row['OXORDERNR'], $row['EVERYSIZE_ORDER_ID'], $why);
    }

    private function isRevokedAtEverysize(string $everysizeOrderId): bool
    {
        $order = $this->client->getOrder($everysizeOrderId);

        return strtoupper((string) ($order['status'] ?? '')) === Client::STATUS_REVOKED;
    }

    /**
     * The tracking code everysize has for an order: fulfillment.tracking is
     * {code, carrier}, or an empty list without one.
     *
     * @param array<string, mixed> $order
     */
    private static function trackingCodeOf(array $order): string
    {
        $tracking = $order['fulfillment']['tracking'] ?? null;
        if (\is_array($tracking) && isset($tracking[0]) && \is_array($tracking[0])) {
            $tracking = $tracking[0];
        }

        return \is_array($tracking) && isset($tracking['code']) ? trim((string) $tracking['code']) : '';
    }

    /** The API takes a tracking code only together with a carrier (Client::sendFulfillment). */
    private function sendsTracking(string $trackingCode): bool
    {
        return $trackingCode !== '' && $this->settings->getCarrier() !== '';
    }
}
