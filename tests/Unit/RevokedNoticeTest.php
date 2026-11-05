<?php

declare(strict_types=1);

namespace Everysize\Checkout\Tests\Unit;

use Everysize\Checkout\Api\Client;
use Everysize\Checkout\Export\RevokedNotice;
use Everysize\Checkout\OrderLinks;
use Everysize\Checkout\Protocol;
use Everysize\Checkout\Settings;
use PHPUnit\Framework\TestCase;

/** OrderLinks in memory: the link to the everysize order, the shop state and the report table (unique per order, kind and SKU). */
final class RevokedLinks extends OrderLinks
{
    /** @var array<string, string> everysize order id => shop order id */
    public array $linked = ['ES-1' => 'o1', 'ES-2' => 'o2', 'ES-3' => 'o3', 'ES-4' => 'o4', 'ES-5' => 'o5'];

    /** @var array<string, array{number: string, lang: int, closed: bool, shipped: bool}> */
    public array $orders = [];

    /** @var array<string, array<string, array{kind: string, sku: string, reason: string, result: string}>> */
    public array $rows = [];

    public bool $addFails = false;

    public function findOrderId(string $everysizeOrderId): ?string
    {
        return $this->linked[$everysizeOrderId] ?? null;
    }

    /** @var array<string, ?int> order id => the paid amount in cents the check passed */
    public array $paidAsked = [];

    public function getRevokedCheckInfo(string $orderId, ?int $paidCents = null): ?array
    {
        $this->paidAsked[$orderId] = $paidCents;

        return $this->orders[$orderId] ?? null;
    }

    /** @var array<int, array{ORDERID: string, REASON: string}> */
    public array $markers = [];

    protected function getRevokedMarkers(array $orderIds): array
    {
        return array_values(array_filter($this->markers, static fn (array $m): bool => \in_array($m['ORDERID'], $orderIds, true)));
    }

    public function hasReport(string $orderId, string $kind, string $sku): bool
    {
        return isset($this->rows[$orderId][$kind.'|'.$sku]);
    }

    public function addReport(string $orderId, string $kind, string $sku, string $reason, string $result): void
    {
        if ($this->addFails) {
            throw new \RuntimeException('database gone');
        }
        if (isset($this->rows[$orderId][$kind.'|'.$sku])) {
            throw new \RuntimeException('duplicate entry');
        }
        $this->rows[$orderId][$kind.'|'.$sku] = ['kind' => $kind, 'sku' => $sku, 'reason' => $reason, 'result' => $result];
    }
}

/** The settings table in memory; can be made to fail. */
final class MemorySettings extends Settings
{
    /** @var array<string, string> */
    public array $values = [];

    public bool $fails = false;

    public function get(string $name, string $default = ''): string
    {
        if ($this->fails) {
            throw new \RuntimeException('database gone');
        }

        return $this->values[$name] ?? $default;
    }

    public function set(string $name, string $value): void
    {
        if ($this->fails) {
            throw new \RuntimeException('database gone');
        }
        $this->values[$name] = $value;
    }
}

final class RecordingProtocol extends Protocol
{
    /** @var array<int, array{string, string}> */
    public array $warnings = [];

    public function add(string $title, string $message, bool $success): void
    {
        throw new \LogicException('the check never writes an error entry');
    }

    public function warn(string $title, string $message = ''): void
    {
        $this->warnings[] = [$title, $message];
    }
}

final class RevokedNoticeTest extends TestCase
{
    private FakeTransport $http;
    private Client $client;
    private RevokedLinks $links;
    private RecordingProtocol $protocol;
    private MemorySettings $settings;

    /** @var array<int, string> what went to the technical log */
    private array $log = [];

    protected function setUp(): void
    {
        $this->http = new FakeTransport();
        $this->http->queue(200, '{"access_token":"tok","token_type":"bearer","expires_in":3600,"shop_id":4711}');
        $this->client = new Client($this->http, new FixedCredentials('id', 'secret'), ApiUrlTest::TEST_URL, '7.5.1');
        $this->links = new RevokedLinks();
        $this->protocol = new RecordingProtocol();
        $this->settings = new MemorySettings();
        $this->links->orders = [
            'o1' => ['number' => '37', 'lang' => 0, 'closed' => false, 'shipped' => false],
            'o2' => ['number' => '38', 'lang' => 1, 'closed' => false, 'shipped' => false],
            'o3' => ['number' => '39', 'lang' => 0, 'closed' => true, 'shipped' => false],
            'o4' => ['number' => '40', 'lang' => 0, 'closed' => false, 'shipped' => true],
        ];
    }

    private function notice(): RevokedNotice
    {
        $log = &$this->log;

        return new class($this->client, $this->links, $this->protocol, $this->settings, $log) extends RevokedNotice {
            /** @var array<int, string> */
            private array $logged;

            /** @param array<int, string> $log */
            public function __construct(Client $client, OrderLinks $links, Protocol $protocol, Settings $settings, array &$log)
            {
                parent::__construct($client, $links, $protocol, $settings);
                $this->logged = &$log;
            }

            protected function isoOf(int $languageId): string
            {
                return $languageId === 0 ? 'de' : 'en';
            }

            protected function log(string $title, string $message): void
            {
                $this->logged[] = $title.'|'.$message;
            }
        };
    }

    /** @return array{revoked: int, noted: int, closed: int, skipped: int, warnings: int} */
    private function check(string $day = '2026-11-04'): array
    {
        return $this->notice()->run(new \DateTimeImmutable($day.' 12:00:00', new \DateTimeZone('UTC')));
    }

    /** @param string[] $ids */
    private function queueList(array $ids, ?int $total = null): void
    {
        $content = array_map(static fn (string $id): array => ['everysizeOrderId' => $id, 'status' => 'REVOKED'], $ids);
        $this->http->queue(200, (string) json_encode(['content' => $content, 'totalElements' => $total ?? \count($ids), 'totalPages' => 1]));
    }

    public function testOpenOrderGetsOneHintAndTheSecondRunDoesNothing(): void
    {
        $this->queueList(['ES-1']);
        $result = $this->check();

        self::assertSame(1, $result['noted']);
        self::assertSame(['kind' => 'es-revoked', 'sku' => '', 'reason' => 'OPEN', 'result' => 'noted'], $this->links->rows['o1']['es-revoked|']);
        self::assertSame([['Bestellung 37 wurde bei everysize storniert. Bitte nicht versenden und im Shop stornieren.', '']], $this->protocol->warnings);

        $this->queueList(['ES-1']);
        $second = $this->check();

        self::assertSame(0, $second['noted']);
        self::assertSame(1, $second['skipped']);
        self::assertCount(1, $this->protocol->warnings);
        self::assertCount(1, $this->links->rows['o1']);
    }

    public function testTheHintIsInTheLanguageOfTheOrder(): void
    {
        $this->queueList(['ES-2']);
        $this->check();

        self::assertSame('Order 38 was cancelled at everysize. Please do not ship it and cancel it in the shop.', $this->protocol->warnings[0][0]);
    }

    public function testTextsInBothLanguages(): void
    {
        self::assertSame('Bestellung 7 wurde bei everysize storniert, ist hier aber schon als versendet markiert. Bitte prüfen.', RevokedNotice::text(RevokedNotice::SHIPPED, 'de', '7'));
        self::assertSame('Order 7 was cancelled at everysize but is already marked as shipped here. Please check.', RevokedNotice::text(RevokedNotice::SHIPPED, 'en', '7'));
        self::assertSame('Order 7 was cancelled at everysize. Please do not ship it and cancel it in the shop.', RevokedNotice::text(RevokedNotice::OPEN, 'fr', '7'));
    }

    public function testAlreadyCancelledOrderGetsOnlyTheMarker(): void
    {
        $this->queueList(['ES-3']);
        $result = $this->check();

        self::assertSame(1, $result['closed']);
        self::assertSame([], $this->protocol->warnings);
        self::assertSame('skipped', $this->links->rows['o3']['es-revoked|']['result']);

        $this->queueList(['ES-3']);
        $this->check();
        self::assertSame([], $this->protocol->warnings);
    }

    public function testShippedOrderGetsTheOtherText(): void
    {
        $this->queueList(['ES-4']);
        $result = $this->check();

        self::assertSame(1, $result['noted']);
        self::assertSame('SHIPPED', $this->links->rows['o4']['es-revoked|']['reason']);
        self::assertSame('Bestellung 40 wurde bei everysize storniert, ist hier aber schon als versendet markiert. Bitte prüfen.', $this->protocol->warnings[0][0]);
    }

    public function testUnlinkedAndDeletedOrdersAreSkipped(): void
    {
        $this->queueList(['ES-X', 'ES-5']); // ES-X is not linked, the shop order of ES-5 is gone
        $result = $this->check();

        self::assertSame(2, $result['skipped']);
        self::assertSame([], $this->links->rows);
        self::assertSame([], $this->protocol->warnings);
    }

    public function testRequestAsksForRevokedAcknowledgedOrdersOfTheLast30Days(): void
    {
        $this->queueList([]);
        $this->check();

        self::assertSame('GET', $this->http->requests[1]['method']);
        self::assertSame(
            ApiUrlTest::TEST_URL.'/api/v1/shops/4711/orders?status=REVOKED&acknowledged=true&from=2026-10-05T14%3A00%3A00%2B02%3A00&to=2026-11-04T13%3A00%3A00%2B01%3A00&pageSize=100&pageNumber=0',
            $this->http->requests[1]['url']
        );
    }

    public function testPageNumberIsARowOffset(): void
    {
        $this->queueList(['ES-1'], 250);
        $this->queueList(['ES-2'], 250);
        $this->queueList(['ES-3'], 250);
        $this->check();

        $offsets = [];
        foreach (\array_slice($this->http->requests, 1) as $request) {
            preg_match('/pageNumber=(\d+)/', $request['url'], $m);
            $offsets[] = (int) $m[1];
        }
        self::assertSame([0, 100, 200], $offsets);
    }

    public function testPaidAmountInCentsComesFromGrossPrice(): void
    {
        $this->http->queue(200, (string) json_encode(['content' => [
            ['everysizeOrderId' => 'ES-1', 'grossPrice' => '1,234.56'],
            ['everysizeOrderId' => 'ES-2', 'grossPrice' => 'kaputt'],
            ['everysizeOrderId' => 'ES-3'],
        ], 'totalElements' => 3]));
        $this->check();

        self::assertSame(123456, $this->links->paidAsked['o1']);
        self::assertNull($this->links->paidAsked['o2']);
        self::assertNull($this->links->paidAsked['o3']);
    }

    public function testPartialRefundDoesNotCloseButFullRefundDoes(): void
    {
        $partial = [['SKU' => 're-1', 'REASON' => '5.00']];
        $parts = [['SKU' => 're-1', 'REASON' => '5.00'], ['SKU' => 're-2', 'REASON' => '114.99']];
        self::assertFalse(OrderLinks::coversPaid($partial, 11999));
        self::assertFalse(OrderLinks::coversPaid([['SKU' => 're-1', 'REASON' => '119.98']], 11999));
        self::assertTrue(OrderLinks::coversPaid([['SKU' => 're-1', 'REASON' => '119.99']], 11999));
        self::assertTrue(OrderLinks::coversPaid($parts, 11999)); // sum of several parts
        self::assertTrue(OrderLinks::coversPaid([['SKU' => 'auto-decline', 'REASON' => 'RF-9']], 11999));
        self::assertTrue(OrderLinks::coversPaid([['SKU' => 're-1', 'REASON' => '1,234.56']], 123456));
        self::assertFalse(OrderLinks::coversPaid([['SKU' => 're-1', 'REASON' => 'x']], 11999));
        self::assertFalse(OrderLinks::coversPaid([], 11999));
        self::assertFalse(OrderLinks::coversPaid([['SKU' => 're-1', 'REASON' => '119.99']], 0));
    }

    public function testOrderListAndTabShowNoHintForClosedOrders(): void
    {
        $this->links->markers = [['ORDERID' => 'o1', 'REASON' => 'OPEN'], ['ORDERID' => 'o3', 'REASON' => 'OPEN'], ['ORDERID' => 'o4', 'REASON' => 'SHIPPED']];

        // o3 is closed since (status set on, cancelled, refunded in full): no hint, although the marker says OPEN
        self::assertSame(['o1' => 'OPEN', 'o4' => 'SHIPPED'], $this->links->getRevokedNotes(['o1', 'o3', 'o4']));
        self::assertSame([], $this->links->getRevokedNotes(['o3']));
        self::assertSame([], $this->links->getRevokedNotes([]));
    }

    public function testASecondErrorOnTheSameDayDoesNotReachTheProtocol(): void
    {
        $this->http->queue(500, '');
        $first = $this->check();
        self::assertSame(1, $first['warnings']);
        self::assertCount(1, $this->protocol->warnings);
        self::assertSame([], $this->log);

        // 5 minutes later the same outage: counted, but only in the technical log
        $this->http->queue(500, '');
        $second = $this->check();
        self::assertSame(1, $second['warnings']);
        self::assertCount(1, $this->protocol->warnings);
        self::assertCount(1, $this->log);
        self::assertStringContainsString('cancellation check skipped', $this->log[0]);

        // the next day it is written again
        $this->http->queue(500, '');
        $this->check('2026-11-05');
        self::assertCount(2, $this->protocol->warnings);
        self::assertCount(1, $this->log);
    }

    public function testOrderErrorsAreOneKindAndTheListErrorIsAnother(): void
    {
        $this->links->addFails = true;
        $this->queueList(['ES-1', 'ES-2', 'ES-4']);
        $result = $this->check();

        self::assertSame(3, $result['warnings']);
        self::assertCount(1, $this->protocol->warnings); // three order errors: one entry
        self::assertCount(2, $this->log);

        $this->http->queue(500, ''); // the list error is a kind of its own
        $this->check();
        self::assertCount(2, $this->protocol->warnings);
    }

    public function testAFailingLimitNeverDisturbsTheRun(): void
    {
        $this->settings->fails = true;
        $this->queueList(['ES-1']);
        $this->links->addFails = true;
        $result = $this->check();

        self::assertSame(1, $result['warnings']);
        self::assertSame([], $this->protocol->warnings);
        self::assertCount(1, $this->log);
    }

    public function testTheHintItselfIsNotLimited(): void
    {
        $this->queueList(['ES-1', 'ES-2']);
        $this->check();

        self::assertCount(2, $this->protocol->warnings);
    }

    public function testAtMost500RowsPerRun(): void
    {
        for ($i = 0; $i < 8; ++$i) {
            $this->queueList(['ES-1'], 900);
        }
        $this->check();

        // the first page (offset 0) only tells the total, then 5 pages of the youngest 500
        self::assertCount(1 + 1 + 5, $this->http->requests);
        self::assertStringContainsString('pageNumber=800', $this->http->requests[6]['url']);
    }

    public function testMoreThan500ReadTheYoungestRowsAndDropTheFirstPage(): void
    {
        $this->queueList(['ES-1'], 750); // first page: only the total, its rows are dropped
        $this->queueList(['ES-2'], 750);
        $this->queueList(['ES-3'], 750);
        $this->queueList(['ES-4'], 750);
        $this->queueList(['ES-X'], 750);
        $this->queueList(['ES-Y'], 750);
        $result = $this->check();

        $offsets = [];
        foreach (\array_slice($this->http->requests, 1) as $request) {
            preg_match('/pageNumber=(\d+)/', $request['url'], $m);
            $offsets[] = (int) $m[1];
        }
        self::assertSame([0, 250, 350, 450, 550, 650], $offsets);
        self::assertSame(5, $result['revoked']); // 5 pages of the youngest 500; ES-1 was the dropped first page
        self::assertArrayNotHasKey('o1', $this->links->rows);
        self::assertArrayHasKey('o2', $this->links->rows);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public function brokenResponses(): array
    {
        return [
            '401' => [401, '{"error":"invalid_token"}'],
            '404' => [404, ''],
            '500' => [500, '{"title":"boom"}'],
            'no json' => [200, '<html>nope</html>'],
            'content is no list' => [200, '{"content":{"a":1},"totalElements":1}'],
            'content missing' => [200, '{"totalElements":1}'],
            'totalElements missing' => [200, '{"content":[]}'],
            'json null' => [200, 'null'],
        ];
    }

    /**
     * @dataProvider brokenResponses
     */
    public function testBrokenAnswerDoesNotDisturbTheRun(int $status, string $body): void
    {
        $this->http->queue($status, $body);
        $result = $this->check();

        self::assertSame(1, $result['warnings']);
        self::assertSame(0, $result['noted']);
        self::assertSame([], $this->links->rows);
        self::assertCount(1, $this->protocol->warnings);
    }

    public function testSecondPageBrokenDiscardsEverything(): void
    {
        $this->queueList(['ES-1'], 150);
        $this->http->queue(500, '');
        $result = $this->check();

        self::assertSame(1, $result['warnings']);
        self::assertSame([], $this->links->rows);
    }

    public function testNoAnswerAtAllDoesNotDisturbTheRun(): void
    {
        // the transport throws on the second request (nothing queued)
        $result = $this->check();

        self::assertSame(1, $result['warnings']);
    }

    public function testFailureToWriteTheMarkerIsAWarningAndRetriedNextRun(): void
    {
        $this->links->addFails = true;
        $this->queueList(['ES-1']);
        $result = $this->check();

        self::assertSame(1, $result['warnings']);
        self::assertSame(0, $result['noted']);
        self::assertSame([], $this->links->rows);

        $this->links->addFails = false;
        $this->protocol->warnings = [];
        $this->queueList(['ES-1']);
        $result = $this->check();

        self::assertSame(1, $result['noted']);
    }

    public function testNothingIsChangedAndNothingIsSentToEverysize(): void
    {
        $this->queueList(['ES-1', 'ES-3', 'ES-4']);
        $this->check();

        $methods = array_map(static fn (array $r): string => $r['method'], \array_slice($this->http->requests, 1));
        self::assertSame(['GET'], $methods);
        foreach ($this->links->rows as $rows) {
            foreach ($rows as $row) {
                self::assertSame(OrderLinks::KIND_REVOKED_AT_EVERYSIZE, $row['kind']);
            }
        }
        self::assertCount(2, $this->protocol->warnings);
    }

    public function testTheHintNamesOnlyTheOrderNumber(): void
    {
        $this->http->queue(200, (string) json_encode(['content' => [[
            'everysizeOrderId' => 'ES-1',
            'customer' => ['email' => 'kundin@example.org', 'originalEmail' => 'kundin@example.org'],
            'shippingAddress' => ['lastName' => 'Musterfrau'],
        ]], 'totalElements' => 1]));
        $this->check();

        $text = $this->protocol->warnings[0][0].$this->protocol->warnings[0][1];
        self::assertStringNotContainsString('Musterfrau', $text);
        self::assertStringNotContainsString('example.org', $text);
    }

    public function testTheMarkerKindFitsTheColumn(): void
    {
        self::assertLessThanOrEqual(16, \strlen(OrderLinks::KIND_REVOKED_AT_EVERYSIZE));
        self::assertLessThanOrEqual(16, \strlen(OrderLinks::RESULT_NOTED));
    }
}
