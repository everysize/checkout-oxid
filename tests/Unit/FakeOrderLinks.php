<?php

declare(strict_types=1);

namespace Everysize\Checkout\Tests\Unit;

use Everysize\Checkout\OrderLinks;

/**
 * The pending-* queries, the reports and the open-refund entry are overridden;
 * nothing touches a database.
 */
class FakeOrderLinks extends OrderLinks
{
    /** @var array<int, array<string, string>> */
    public array $fulfillments = [];

    /** @var array<int, array<string, string>> */
    public array $trackingCodes = [];

    public ?string $trackingSince = null;

    /** @var array<int, array<string, string>> */
    public array $revocations = [];

    /** @var array<int, array<string, string>> */
    public array $autoRefunds = [];

    public ?string $autoRefundSince = null;

    /** Created time of an open entry the tests put in: long ago. */
    public const OLD = '2026-11-04 11:00:00';

    /** "Now" of the tests (the clock of ManualRefund is set to the same moment). */
    public const NOW = '2026-11-04 12:00:00';

    /** @var array<string, array{key: string, amount: string, created: string, sent: ?string, knownIds: ?array<int, string>, resultId: string}> order => open refund */
    public array $openRefunds = [];

    /** @var array<int, string> every call that changed something, in order: "report:<kind>:<sku>", "close:<key>", "open:<key>" ... */
    public array $calls = [];

    /** @var array<int, string> refund IDs the shop booked itself */
    public array $knownRefundIds = [];

    /** @var array<int, array<string, string>> */
    public array $staleOpenRefunds = [];

    /** @var array<int, array<string, string>> */
    public array $openAutoRefunds = [];

    /** @var array<int, array<string, string>> */
    public array $unhandledDeclines = [];

    /** If set, the next claimSend() loses (somebody else took over). */
    public bool $claimLost = false;

    /** If set, openRefund() fails as if a parallel request had won; the entry is then this one. */
    public ?array $parallelEntry = null;

    /** Reasons passed to addReport, parallel to $reports. */
    public array $reasons = [];

    /** @var array<int, string> order ids whose order shows signs of shipping */
    public array $shippedOrders = [];

    /** @var array<int, array{string, string, string, string}> order, kind, SKU, result */
    public array $reports = [];

    public function getPendingFulfillments(): array
    {
        return $this->fulfillments;
    }

    public function getPendingTrackingCodes(string $since): array
    {
        $this->trackingSince = $since;

        return $this->trackingCodes;
    }

    public function getPendingRevocations(): array
    {
        return $this->revocations;
    }

    public function hasSentFulfillment(string $orderId): bool
    {
        return \in_array([$orderId, self::KIND_FULFILLMENT, '', self::RESULT_SENT], $this->reports, true);
    }

    public function getPendingAutoRefunds(string $since, ?string $onlyOrderId = null): array
    {
        $this->autoRefundSince = $since;
        if ($onlyOrderId !== null) {
            return array_values(array_filter($this->autoRefunds, static fn (array $row): bool => $row['OXID'] === $onlyOrderId));
        }

        return $this->autoRefunds;
    }

    public function addReport(string $orderId, string $kind, string $sku, string $reason, string $result): void
    {
        $this->calls[] = 'report:'.$kind.':'.$sku;
        $this->reports[] = [$orderId, $kind, $sku, $result];
        $this->reasons[] = $reason;
    }

    public function isShipped(string $orderId): bool
    {
        return \in_array($orderId, $this->shippedOrders, true);
    }

    public function getOpenRefund(string $orderId): ?array
    {
        return $this->openRefunds[$orderId] ?? null;
    }

    public function openRefund(string $orderId, string $key, string $amount): void
    {
        if ($this->parallelEntry !== null) {
            $this->openRefunds[$orderId] = $this->parallelEntry;
            throw new \RuntimeException('duplicate entry');
        }
        if (isset($this->openRefunds[$orderId])) {
            throw new \RuntimeException('duplicate entry');
        }
        $this->calls[] = 'open:'.$key;
        $this->openRefunds[$orderId] = ['key' => $key, 'amount' => $amount, 'created' => self::NOW, 'sent' => null, 'knownIds' => null, 'resultId' => ''];
    }

    public function setKnownIds(string $orderId, string $key, array $refundIds): void
    {
        $this->calls[] = 'known:'.implode(',', $refundIds);
        if (($this->openRefunds[$orderId]['key'] ?? null) === $key) {
            $this->openRefunds[$orderId]['knownIds'] = array_values($refundIds);
        }
    }

    public function setResultId(string $orderId, string $key, string $resultId): void
    {
        $this->calls[] = 'result:'.$resultId;
        if (($this->openRefunds[$orderId]['key'] ?? null) === $key) {
            $this->openRefunds[$orderId]['resultId'] = $resultId;
        }
    }

    public function claimSend(string $orderId, string $key, ?string $previousSent): bool
    {
        $open = $this->openRefunds[$orderId] ?? null;
        if ($this->claimLost || $open === null || $open['key'] !== $key || $open['sent'] !== $previousSent) {
            return false;
        }
        $this->calls[] = 'claim:'.$key;
        $this->openRefunds[$orderId]['sent'] = self::NOW;

        return true;
    }

    public function closeRefund(string $orderId, string $key, string $amount): void
    {
        $this->calls[] = 'close:'.$key;
        $open = $this->openRefunds[$orderId] ?? null;
        if ($open !== null && $open['key'] === $key && $open['amount'] === $amount) {
            unset($this->openRefunds[$orderId]);
        }
    }

    public function removeOpenRefundIfOld(string $orderId, string $key, int $seconds): bool
    {
        $open = $this->openRefunds[$orderId] ?? null;
        if ($open === null) {
            return true;
        }
        if ($open['key'] === $key && strtotime(self::NOW) - strtotime($open['sent'] ?? $open['created']) >= $seconds) {
            $this->calls[] = 'remove:'.$key;
            unset($this->openRefunds[$orderId]);

            return true;
        }

        return false;
    }

    public function removeReport(string $orderId, string $kind, string $sku): void
    {
        $this->calls[] = 'unreport:'.$kind.':'.$sku;
        $this->reports = array_values(array_filter($this->reports, static function (array $r) use ($orderId, $kind, $sku): bool {
            return !($r[0] === $orderId && $r[1] === $kind && $r[2] === $sku);
        }));
    }

    public function isKnownRefund(string $orderId, string $refundId): bool
    {
        return \in_array($refundId, $this->knownRefundIds, true);
    }

    public function getStaleOpenRefunds(int $seconds): array
    {
        return $this->staleOpenRefunds;
    }

    public function getOpenAutoRefunds(): array
    {
        return $this->openAutoRefunds;
    }

    public function getUnhandledDeclines(string $since, int $days = 60): array
    {
        return $this->unhandledDeclines;
    }

    /** @var list<string> */
    public array $updateProblems = [];

    public function getUpdateProblems(): array
    {
        return $this->updateProblems;
    }

    public function hasReport(string $orderId, string $kind, string $sku): bool
    {
        foreach ($this->reports as $report) {
            if ($report[0] === $orderId && $report[1] === $kind && $report[2] === $sku) {
                return true;
            }
        }

        return false;
    }
}
