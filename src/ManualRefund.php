<?php

declare(strict_types=1);

namespace Everysize\Checkout;

use Everysize\Checkout\Api\Amount;
use Everysize\Checkout\Api\ApiException;
use Everysize\Checkout\Api\Client;

/**
 * Every refund the module sends (typed into the order tab, repeated, or the automatic one after a
 * cancellation), under the rules for money that cannot be taken back. Only the answer to the refund POST
 * itself counts:
 *
 * - 2xx with a refund ID (or the status ALREADY_REFUNDED): success. Without both it is unclear.
 * - 422 PAYPAL_REFUND_FAILED / REFUND_AMOUNT_INVALID or 400 ORDER_NOT_PAID_USING_EVERYSIZE_CHECKOUT_PAYMENTS:
 *   safely refused, no money moved. Only the refusal of the FIRST attempt removes the entry; a refused repeat
 *   leaves it open (the first attempt may have gone through): check PayPal, mark as settled.
 * - The token call failed, the POST never went out: nothing happened. An entry created by this very request
 *   is removed, an older one stays.
 * - Everything else (no answer, timeout, 3xx, 401, 403, 404, 408, 409, 425, 429, 5xx, IDEMPOTENCY_KEY_REUSED,
 *   unknown): unclear. The entry stays open.
 *
 * The open entry is one row per order, reserved BEFORE the PayPal list is read and anything is sent, and it
 * keeps the refund IDs PayPal listed at that moment. The right to send is taken over atomically (claimSend) and
 * counted from then on: within 150 s nobody repeats. A repeat reads the list first; a refund that is new since
 * the entry was made, completed or pending, of the same amount (any for "rest") and unknown to the shop closes
 * the entry as success without sending. The automatic run sends at most once and afterwards only reads the list.
 * Success: the report first, then the entry goes; once the refund ID is known the entry is never sent again.
 */
final class ManualRefund
{
    /** Seconds since the last hand-over after which an open entry may be repeated or settled by hand. */
    public const MIN_AGE = 150;

    public const DONE = 'done';
    public const ALREADY_REFUNDED = 'already-refunded';
    /** A new matching refund in the PayPal list: booked as success, nothing sent. */
    public const FOUND = 'found';
    public const INVALID_AMOUNT = 'invalid-amount';
    /** An unclear refund is open: no other refund, only repeating or settling it. */
    public const OTHER_AMOUNT = 'other-amount';
    /** Repeat or settle without an open entry. */
    public const NOTHING_OPEN = 'nothing-open';
    /** Handed over less than MIN_AGE ago, or somebody else got the right to send. */
    public const RUNNING = 'running';
    /** The refunds seen at page load differ from the PayPal list now: reload. */
    public const STALE = 'stale';
    /** The PayPal list could not be read (or is no list): nothing is sent. */
    public const LIST_UNREADABLE = 'list-unreadable';
    /** Nothing was sent (no token): no refund. */
    public const NOT_SENT = 'not-sent';
    /** The first attempt was safely refused: no money moved, entry removed. */
    public const REJECTED = 'rejected';
    /** A repeat was refused: the first attempt may have gone through, the entry stays open. */
    public const REJECTED_REPEAT = 'rejected-repeat';
    /** Money may have moved. */
    public const UNCLEAR = 'unclear';
    /** The merchant marked the open refund as settled. */
    public const SETTLED = 'settled';
    /** Automatic refund only: the PayPal list already shows a refund. */
    public const EXISTS = 'exists';
    /** Automatic refund only: an open entry, nothing new in the list, nothing is sent. */
    public const WAITING = 'waiting';

    private Client $client;
    private OrderLinks $links;
    /** @var callable */
    private $clock;

    public function __construct(Client $client, OrderLinks $links, ?callable $clock = null)
    {
        $this->client = $client;
        $this->links = $links;
        $this->clock = $clock ?? static function (): int {
            return time();
        };
    }

    /**
     * "10", "10,5", "10.50", "1.234,56" → "1234.56"-style API form; null for everything else
     * (also empty, 0, negative: see RefundAmount).
     */
    public static function parseAmount(string $input): ?string
    {
        return RefundAmount::normalize($input);
    }

    /** Amount in cents of the API form "10.00" or "1,234.56". */
    public static function cents(string $amount): int
    {
        return (int) round(Amount::parse($amount) * 100);
    }

    /**
     * What the form may send, checked before anything else happens: null = go on, otherwise INVALID_AMOUNT,
     * OTHER_AMOUNT or NOTHING_OPEN.
     */
    public function precheck(string $orderId, string $postedAmount, bool $repeat): ?string
    {
        $open = $this->links->getOpenRefund($orderId);
        if ($open !== null) {
            return $repeat ? null : self::OTHER_AMOUNT;
        }
        if ($repeat) {
            return self::NOTHING_OPEN;
        }

        return self::parseAmount($postedAmount) === null ? self::INVALID_AMOUNT : null;
    }

    /**
     * A refund from the order tab.
     *
     * @param bool        $repeat        "repeat the open refund"
     * @param string      $postedKey     key of the form; replaced by a fresh one if it does not match the checkout's pattern
     * @param string|null $knownRefunded sum of the COMPLETED/PENDING refunds ("10.00") the page showed; null or a different sum now means STALE
     *
     * @return array{outcome: string, message: string, reason: string, refund: array<string, mixed>, amount: string, refundId: string, key: string, newKey: string}
     */
    public function refund(string $orderId, string $everysizeOrderId, string $postedAmount, bool $repeat, string $postedKey, ?string $knownRefunded = null): array
    {
        $blocked = $this->precheck($orderId, $postedAmount, $repeat);
        $open = $this->links->getOpenRefund($orderId);
        if ($blocked !== null) {
            return $this->result($blocked, '', $open !== null ? $open['amount'] : '', $open !== null ? $open['key'] : '');
        }
        if ($open !== null) {
            return $this->repeat($orderId, $everysizeOrderId, $open);
        }

        $amount = (string) self::parseAmount($postedAmount);
        $key = Client::isValidIdempotencyKey($postedKey) ? $postedKey : 'manual-'.bin2hex(random_bytes(8));

        $notConnected = $this->connect($amount, $key);
        if ($notConnected !== null) {
            return $notConnected;
        }
        // Reserve first, then read: whoever reads the list is the one who holds the entry.
        $reserved = $this->reserve($orderId, $key, $amount);
        if ($reserved !== null) {
            return $reserved;
        }
        $list = $this->readList($everysizeOrderId);
        if ($list === null) {
            $this->links->closeRefund($orderId, $key, $amount);

            return $this->result(self::LIST_UNREADABLE, '', $amount, $key);
        }
        try {
            $stale = $knownRefunded === null || self::sumCents($list) !== self::cents($knownRefunded);
        } catch (ApiException $e) {
            // An amount in the list that cannot be read: the list is not usable.
            $this->links->closeRefund($orderId, $key, $amount);

            return $this->result(self::LIST_UNREADABLE, $e->getMessage(), $amount, $key);
        }
        if ($stale) {
            $this->links->closeRefund($orderId, $key, $amount);

            return $this->result(self::STALE, '', $amount, $key);
        }
        $this->links->setKnownIds($orderId, $key, self::ids($list));

        return $this->send($orderId, $everysizeOrderId, ['key' => $key, 'amount' => $amount, 'sent' => null], true, true);
    }

    /**
     * The automatic full refund after a cancellation before shipping (key "auto-decline-<everysize ID>").
     * The caller has checked every condition for this very order just now. It sends at most once: a new entry
     * needs a readable PayPal list without a COMPLETED/PENDING refund (EXISTS otherwise). It never sends again
     * for an open entry (see checkOpenAutomatic).
     *
     * @return array{outcome: string, message: string, reason: string, refund: array<string, mixed>, amount: string, refundId: string, key: string, newKey: string}
     */
    public function autoRefund(string $orderId, string $everysizeOrderId): array
    {
        $key = OrderLinks::AUTO_REFUND.'-'.$everysizeOrderId;
        if (!Client::isValidIdempotencyKey($key)) {
            return $this->result(self::NOT_SENT, 'the idempotency key of this order is not valid', '', $key);
        }
        $open = $this->links->getOpenRefund($orderId);
        if ($open !== null) {
            return $this->result(self::OTHER_AMOUNT, '', $open['amount'], $open['key']);
        }

        $notConnected = $this->connect('', $key);
        if ($notConnected !== null) {
            return $notConnected;
        }
        $reserved = $this->reserve($orderId, $key, '');
        if ($reserved !== null) {
            return $reserved;
        }
        $list = $this->readList($everysizeOrderId);
        if ($list === null) {
            $this->links->closeRefund($orderId, $key, '');

            return $this->result(self::LIST_UNREADABLE, '', '', $key);
        }
        foreach ($list as $refund) {
            if (self::counts($refund)) {
                $this->links->closeRefund($orderId, $key, '');

                return $this->result(self::EXISTS, '', '', $key);
            }
        }
        $this->links->setKnownIds($orderId, $key, self::ids($list));

        return $this->send($orderId, $everysizeOrderId, ['key' => $key, 'amount' => '', 'sent' => null], true, true);
    }

    /**
     * What the automatic run does with an open entry of its own: it never sends. If the answer is already
     * known it books it; if the PayPal list shows a new matching refund it closes the entry as success;
     * otherwise it waits (the protocol reports entries older than 15 minutes).
     *
     * @return array{outcome: string, message: string, reason: string, refund: array<string, mixed>, amount: string, refundId: string, key: string, newKey: string}
     */
    public function checkOpenAutomatic(string $orderId, string $everysizeOrderId): array
    {
        $open = $this->links->getOpenRefund($orderId);
        if ($open === null) {
            return $this->result(self::NOTHING_OPEN);
        }
        if ($open['resultId'] !== '') {
            return $this->finish($orderId, $open);
        }
        $notConnected = $this->connect($open['amount'], $open['key']);
        if ($notConnected !== null) {
            return $notConnected;
        }
        $list = $this->readList($everysizeOrderId);
        if ($list === null) {
            return $this->result(self::LIST_UNREADABLE, '', $open['amount'], $open['key']);
        }
        $match = $this->findMatch($orderId, $list, $open);

        return $match !== null ? $this->book($orderId, $match, $open['amount'], $open['key'], self::FOUND) : $this->result(self::WAITING, '', $open['amount'], $open['key']);
    }

    /**
     * "Mark as settled": sends nothing. Removes the open entry, but only the one the caller saw and only if the
     * last hand-over is at least MIN_AGE ago (checked in the DELETE itself). The report that keeps the
     * automatic refund away is written first and taken back if the entry could not be removed.
     *
     * @return array{outcome: string, message: string, reason: string, refund: array<string, mixed>, amount: string, refundId: string, key: string, newKey: string}
     */
    public function markSettled(string $orderId, string $key = ''): array
    {
        $open = $this->links->getOpenRefund($orderId);
        if ($open === null || ($key !== '' && $open['key'] !== $key)) {
            return $this->result(self::NOTHING_OPEN);
        }
        if ($this->age($open) < self::MIN_AGE) {
            return $this->result(self::RUNNING, '', $open['amount'], $open['key']);
        }

        $sku = 'settled-'.$open['key'];
        try {
            $this->links->addReport($orderId, OrderLinks::KIND_REFUND, $sku, $open['amount'], OrderLinks::RESULT_SETTLED);
            if (!$this->links->removeOpenRefundIfOld($orderId, $open['key'], self::MIN_AGE)) {
                $this->links->removeReport($orderId, OrderLinks::KIND_REFUND, $sku);

                return $this->result(self::RUNNING, '', $open['amount'], $open['key']);
            }
        } catch (\Throwable $e) {
            return $this->result(self::NOT_SENT, $e->getMessage(), $open['amount'], $open['key']);
        }

        return $this->result(self::SETTLED, '', $open['amount'], $open['key']);
    }

    /**
     * Repeating an open entry by hand: old enough, token and list readable, no new matching refund in the list;
     * only then the same key and amount are sent again, and only by the one who takes over the right to send.
     *
     * @param array{key: string, amount: string, created: string, sent: ?string, knownIds: ?list<string>, resultId: string} $open
     *
     * @return array{outcome: string, message: string, reason: string, refund: array<string, mixed>, amount: string, refundId: string, key: string, newKey: string}
     */
    private function repeat(string $orderId, string $everysizeOrderId, array $open): array
    {
        if ($open['resultId'] !== '') {
            return $this->finish($orderId, $open);
        }
        if ($this->age($open) < self::MIN_AGE) {
            return $this->result(self::RUNNING, '', $open['amount'], $open['key']);
        }
        $notConnected = $this->connect($open['amount'], $open['key']);
        if ($notConnected !== null) {
            return $notConnected;
        }
        $list = $this->readList($everysizeOrderId);
        if ($list === null) {
            return $this->result(self::LIST_UNREADABLE, '', $open['amount'], $open['key']);
        }
        if ($open['knownIds'] === null) {
            // Never read (the request died between reserving and reading): nothing was sent, take the list as of now.
            $this->links->setKnownIds($orderId, $open['key'], self::ids($list));
            $open['knownIds'] = self::ids($list);
        }
        $match = $this->findMatch($orderId, $list, $open);
        if ($match !== null) {
            return $this->book($orderId, $match, $open['amount'], $open['key'], self::FOUND);
        }

        return $this->send($orderId, $everysizeOrderId, $open, $open['sent'] === null, false);
    }

    /**
     * Takes over the right to send and sends.
     *
     * @param array{key: string, amount: string, sent: ?string} $open
     * @param bool                                              $first   this is the first POST of the entry
     * @param bool                                              $created the entry was made by this very request
     *
     * @return array{outcome: string, message: string, reason: string, refund: array<string, mixed>, amount: string, refundId: string, key: string, newKey: string}
     */
    private function send(string $orderId, string $everysizeOrderId, array $open, bool $first, bool $created): array
    {
        $key = $open['key'];
        $amount = $open['amount'];
        if (!$this->links->claimSend($orderId, $key, $open['sent'])) {
            return $this->result(self::RUNNING, '', $amount, $key);
        }

        try {
            $refund = $this->client->refund($everysizeOrderId, $amount !== '' ? $amount : null, $key);
        } catch (ApiException $e) {
            if ($e->isNotSent()) {
                // The POST never went out. An entry of this request is withdrawn, an older one stays.
                if ($created) {
                    $this->links->closeRefund($orderId, $key, $amount);
                }

                return $this->result(self::NOT_SENT, $e->getMessage(), $amount, $key);
            }
            if ($e->isSafeRejection()) {
                if ($first) {
                    $this->links->closeRefund($orderId, $key, $amount);

                    return $this->result(self::REJECTED, $e->getMessage(), $amount, $key, [], '', $e->getReason());
                }

                // A refused repeat proves nothing about the first attempt: it stays open.
                return $this->result(self::REJECTED_REPEAT, $e->getMessage(), $amount, $key, [], '', $e->getReason());
            }

            return $this->result(self::UNCLEAR, $e->getMessage(), $amount, $key);
        } catch (\Throwable $e) {
            return $this->result(self::UNCLEAR, $e->getMessage(), $amount, $key);
        }

        // A 2xx is a success only with proof: a refund ID, or the answer that the payment was refunded already.
        $status = strtoupper((string) ($refund['status'] ?? ''));
        if ((string) ($refund['refundId'] ?? '') === '' && $status !== 'ALREADY_REFUNDED') {
            return $this->result(self::UNCLEAR, 'the answer has no refund ID', $amount, $key, $refund);
        }

        return $this->book($orderId, $refund, $amount, $key, self::DONE);
    }

    /**
     * Report first, then the entry goes. The refund ID is noted in the entry before: from then on it is never
     * sent again, even if booking or deleting fails halfway.
     *
     * @param array<string, mixed> $refund
     *
     * @return array{outcome: string, message: string, reason: string, refund: array<string, mixed>, amount: string, refundId: string, key: string, newKey: string}
     */
    private function book(string $orderId, array $refund, string $amount, string $key, string $outcome): array
    {
        $status = strtoupper((string) ($refund['status'] ?? ''));
        $refundId = (string) ($refund['refundId'] ?? '');
        $already = $status === 'ALREADY_REFUNDED' && $refundId === '';
        $result = $this->result($already ? self::ALREADY_REFUNDED : $outcome, '', $amount, $key, $refund, $refundId);

        try {
            $this->links->setResultId($orderId, $key, $already ? 'ALREADY_REFUNDED' : $refundId);
        } catch (\Throwable $e) {
            // Best effort: the report below carries the same fact.
        }
        try {
            $this->bookReport($orderId, $refund, $amount, $key, $already);
        } catch (\Throwable $e) {
            // The money moved; a failing note must not turn this into an error message. The entry stays and is finished later.
            $result['message'] = 'refund booked at everysize, but not noted in the shop: '.$e->getMessage();

            return $result;
        }
        try {
            $this->links->closeRefund($orderId, $key, $amount);
        } catch (\Throwable $e) {
            $result['message'] = 'refund booked, the open entry could not be removed (the next try finishes it without sending): '.$e->getMessage();
        }

        return $result;
    }

    /** @param array<string, mixed> $refund */
    private function bookReport(string $orderId, array $refund, string $amount, string $key, bool $already): void
    {
        $status = strtoupper((string) ($refund['status'] ?? ''));
        $refundId = (string) ($refund['refundId'] ?? '');
        $automatic = strpos($key, OrderLinks::AUTO_REFUND.'-') === 0;
        $sku = $automatic ? OrderLinks::AUTO_REFUND : ($refundId !== '' ? $refundId : ($already ? 'ALREADY_REFUNDED' : $key));
        // The same refund booked twice (double submit, a retry after a failed delete): the second one adds nothing.
        if ($this->links->hasReport($orderId, OrderLinks::KIND_REFUND, $sku)) {
            return;
        }
        $this->links->addReport(
            $orderId,
            OrderLinks::KIND_REFUND,
            $sku,
            $automatic ? ($refundId !== '' ? $refundId : $status) : ($amount !== '' ? $amount : (string) ($refund['amount'] ?? '')),
            $already ? OrderLinks::RESULT_KNOWN : OrderLinks::RESULT_SENT
        );
    }

    /**
     * The entry knows its refund ID: a success that was booked halfway. Finish booking, never send.
     *
     * @param array{key: string, amount: string, resultId: string} $open
     *
     * @return array{outcome: string, message: string, reason: string, refund: array<string, mixed>, amount: string, refundId: string, key: string, newKey: string}
     */
    private function finish(string $orderId, array $open): array
    {
        $already = $open['resultId'] === 'ALREADY_REFUNDED';
        $refund = $already ? ['status' => 'ALREADY_REFUNDED'] : ['refundId' => $open['resultId'], 'status' => 'COMPLETED'];

        return $this->book($orderId, $refund, $open['amount'], $open['key'], self::DONE);
    }

    /**
     * A new matching refund: COMPLETED or PENDING, its ID neither in the list stored with the entry nor in the
     * shop's reports, and the amount fits (every amount for "rest").
     *
     * @param array<int, array<string, mixed>>               $list
     * @param array{amount: string, knownIds: ?list<string>} $open
     *
     * @return array<string, mixed>|null
     */
    private function findMatch(string $orderId, array $list, array $open): ?array
    {
        foreach ($list as $refund) {
            $id = (string) ($refund['refundId'] ?? '');
            if (!self::counts($refund) || $id === '' || ($open['knownIds'] !== null && \in_array($id, $open['knownIds'], true)) || $this->links->isKnownRefund($orderId, $id)) {
                continue;
            }
            try {
                if ($open['amount'] === '' || self::cents((string) ($refund['amount'] ?? '0')) === self::cents($open['amount'])) {
                    return $refund;
                }
            } catch (ApiException $e) {
                // An amount that cannot be read is no match.
            }
        }

        return null;
    }

    /** Reserves the entry; null = reserved, otherwise the result to return (nothing is sent). */
    private function reserve(string $orderId, string $key, string $amount): ?array
    {
        try {
            $this->links->openRefund($orderId, $key, $amount);
        } catch (\Throwable $e) {
            // A parallel request reserved first (or the database is gone): nothing is sent.
            $other = $this->links->getOpenRefund($orderId);
            if ($other === null) {
                return $this->result(self::NOT_SENT, 'the refund could not be recorded before sending, nothing was sent: '.$e->getMessage(), $amount, $key);
            }

            return $this->result(self::OTHER_AMOUNT, '', $other['amount'], $other['key']);
        }

        return null;
    }

    /** The token must work before anything else: no token, no POST. Null = connected. */
    private function connect(string $amount, string $key): ?array
    {
        try {
            $this->client->getShopId();
        } catch (ApiException $e) {
            return $this->result(self::NOT_SENT, $e->getMessage(), $amount, $key);
        }

        return null;
    }

    /** @return array<int, array<string, mixed>>|null null = not readable */
    private function readList(string $everysizeOrderId): ?array
    {
        try {
            return $this->client->getRefunds($everysizeOrderId);
        } catch (ApiException $e) {
            return null;
        }
    }

    /** @param array<string, mixed> $refund */
    private static function counts(array $refund): bool
    {
        return \in_array(strtoupper((string) ($refund['status'] ?? '')), ['COMPLETED', 'PENDING'], true);
    }

    /** @param array<int, array<string, mixed>> $list */
    private static function sumCents(array $list): int
    {
        $sum = 0;
        foreach ($list as $refund) {
            if (self::counts($refund)) {
                $sum += self::cents((string) ($refund['amount'] ?? '0'));
            }
        }

        return $sum;
    }

    /**
     * @param array<int, array<string, mixed>> $list
     *
     * @return list<string>
     */
    private static function ids(array $list): array
    {
        return array_values(array_map(static function (array $refund): string {
            return (string) ($refund['refundId'] ?? '');
        }, $list));
    }

    /** Seconds since the last hand-over (or, if never sent, since the entry was made). @param array{created: string, sent: ?string} $open */
    private function age(array $open): int
    {
        $time = strtotime($open['sent'] ?? $open['created']);

        return $time === false ? PHP_INT_MAX : (int) (($this->clock)() - $time);
    }

    /**
     * @param array<string, mixed> $refund
     *
     * @return array{outcome: string, message: string, reason: string, refund: array<string, mixed>, amount: string, refundId: string, key: string, newKey: string}
     */
    private function result(string $outcome, string $message = '', string $amount = '', string $key = '', array $refund = [], string $refundId = '', string $reason = ''): array
    {
        return [
            'outcome' => $outcome, 'message' => $message, 'reason' => $reason, 'refund' => $refund,
            'amount' => $amount, 'refundId' => $refundId, 'key' => $key,
            // A fresh key for the next attempt after a safe refusal (the form must not reuse the refused one).
            'newKey' => bin2hex(random_bytes(16)),
        ];
    }
}
