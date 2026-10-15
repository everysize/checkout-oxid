<?php

declare(strict_types=1);

namespace Everysize\Checkout\Tests\Unit;

use Everysize\Checkout\Api\Client;
use Everysize\Checkout\Export\StatusExporter;
use Everysize\Checkout\OrderLinks;
use Everysize\Checkout\Settings;
use PHPUnit\Framework\TestCase;

final class StatusExporterTest extends TestCase
{
    private FakeTransport $http;
    private FakeOrderLinks $links;
    private FakeSettings $settings;

    protected function setUp(): void
    {
        $this->http = new FakeTransport();
        $this->http->queue(200, '{"access_token":"tok","token_type":"bearer","expires_in":3600,"shop_id":4711}');
        $this->links = new FakeOrderLinks();
        $this->settings = new FakeSettings('DHL');
    }

    public function testShippingWithTrackingCodeRemembersTheCode(): void
    {
        $this->links->fulfillments = [['OXID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37', 'OXTRACKCODE' => ' 0034 ']];
        $this->http->queue(200, '{"everysizeOrderId":"ABC123","status":"PROCESSING"}');
        $this->http->queue(201, '');

        $result = $this->export();

        self::assertSame(1, $result['fulfillments']);
        self::assertSame('{"carrier":"DHL","trackingCode":["0034"]}', $this->http->requests[2]['body']);
        self::assertSame([
            ['o1', OrderLinks::KIND_FULFILLMENT, '', OrderLinks::RESULT_SENT],
            ['o1', OrderLinks::KIND_TRACKING, '0034', OrderLinks::RESULT_SENT],
        ], $this->links->reports);
    }

    public function testShippingWithoutTrackingCodeRemembersNoCode(): void
    {
        $this->links->fulfillments = [['OXID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37', 'OXTRACKCODE' => '']];
        $this->http->queue(200, '{"everysizeOrderId":"ABC123","status":"PROCESSING"}');
        $this->http->queue(201, '');

        $this->export();

        self::assertSame('{}', $this->http->requests[2]['body']);
        self::assertSame([['o1', OrderLinks::KIND_FULFILLMENT, '', OrderLinks::RESULT_SENT]], $this->links->reports);
    }

    public function testLateTrackingCodeIsReportedOnceWhileCompleted(): void
    {
        $this->links->trackingCodes = [['OXID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37', 'OXTRACKCODE' => '0034']];
        $this->http->queue(200, '{"everysizeOrderId":"ABC123","status":"COMPLETED"}');
        $this->http->queue(201, '');

        $result = $this->export();

        self::assertSame(1, $result['trackingCodes']);
        self::assertSame([], $result['errors']);
        self::assertSame('POST', $this->http->requests[2]['method']);
        self::assertStringEndsWith('/orders/ABC123/fulfillment', $this->http->requests[2]['url']);
        self::assertSame('{"carrier":"DHL","trackingCode":["0034"]}', $this->http->requests[2]['body']);
        self::assertSame([['o1', OrderLinks::KIND_TRACKING, '0034', OrderLinks::RESULT_SENT]], $this->links->reports);

        // Looks back TRACKING_DAYS from now.
        $since = strtotime((string) $this->links->trackingSince);
        self::assertEqualsWithDelta(time() - StatusExporter::TRACKING_DAYS * 86400, $since, 5);
    }

    public function testLateTrackingCodeThatEverysizeAlreadyHasIsOnlyRemembered(): void
    {
        $this->links->trackingCodes = [['OXID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37', 'OXTRACKCODE' => '0034']];
        $this->http->queue(200, '{"everysizeOrderId":"ABC123","status":"COMPLETED","fulfillment":{"method":"POSTAL","tracking":{"code":"0034","carrier":"DHL"}}}');

        $result = $this->export();

        self::assertSame(0, $result['trackingCodes']);
        self::assertCount(2, $this->http->requests, 'token and order only, no fulfillment');
        self::assertSame([['o1', OrderLinks::KIND_TRACKING, '0034', OrderLinks::RESULT_KNOWN]], $this->links->reports);
    }

    public function testLateTrackingCodeReplacesAnotherCodeAtEverysize(): void
    {
        $this->links->trackingCodes = [['OXID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37', 'OXTRACKCODE' => '0035']];
        $this->http->queue(200, '{"everysizeOrderId":"ABC123","status":"COMPLETED","fulfillment":{"method":"POSTAL","tracking":{"code":"0034","carrier":"DHL"}}}');
        $this->http->queue(201, '');

        self::assertSame(1, $this->export()['trackingCodes']);
        self::assertSame('{"carrier":"DHL","trackingCode":["0035"]}', $this->http->requests[2]['body']);
    }

    public function testLateTrackingCodeIsNotReportedForARevokedOrder(): void
    {
        $this->links->trackingCodes = [['OXID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37', 'OXTRACKCODE' => '0034']];
        $this->http->queue(200, '{"everysizeOrderId":"ABC123","status":"REVOKED"}');

        $result = $this->export();

        self::assertSame(0, $result['trackingCodes']);
        self::assertSame(1, $result['skipped']);
        self::assertCount(2, $this->http->requests, 'token and status only, no fulfillment');
        self::assertSame([['o1', OrderLinks::KIND_TRACKING, '0034', OrderLinks::RESULT_SKIPPED]], $this->links->reports);
        self::assertStringContainsString('REVOKED at everysize, tracking code 0034 not reported', $result['errors'][0]);
    }

    public function testWithoutCarrierNoTrackingCodeIsAddedLater(): void
    {
        $this->settings = new FakeSettings('');
        $this->links->trackingCodes = [['OXID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37', 'OXTRACKCODE' => '0034']];

        $result = $this->export();

        self::assertSame(0, $result['pending']);
        self::assertNull($this->links->trackingSince);
        self::assertSame([], $this->http->requests);
    }

    public function testCancellationBeforeShippingIsRefundedInFullOnce(): void
    {
        $this->links->autoRefunds = [['OXID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37']];
        $this->http->queue(201, '{"refundId":"9XD8","status":"COMPLETED","amount":"124.89","currency":"EUR"}');

        $result = $this->export();

        self::assertSame(1, $result['autoRefunds']);
        $request = $this->http->requests[1];
        self::assertSame('POST', $request['method']);
        self::assertStringEndsWith('/orders/ABC123/refunds', $request['url']);
        self::assertSame('{}', $request['body'], 'no amount: everything not refunded yet');
        self::assertContains('Idempotency-Key: auto-decline-ABC123', $request['headers'], 'fixed per order, never twice');
        self::assertSame([['o1', OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, OrderLinks::RESULT_SENT]], $this->links->reports);
    }

    public function testAutoRefundLooksBackAtMostFourteenDays(): void
    {
        $this->settings = new FakeSettings('DHL', true, '2020-01-01 00:00:00');

        $this->export();

        self::assertEqualsWithDelta(time() - 14 * 86400, strtotime((string) $this->links->autoRefundSince), 5);
    }

    public function testAutoRefundOfAnAlreadyRefundedPaymentIsOnlyRemembered(): void
    {
        $this->links->autoRefunds = [['OXID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37']];
        $this->http->queue(201, '{"status":"ALREADY_REFUNDED"}');

        self::assertSame(0, $this->export()['autoRefunds']);
        self::assertSame([['o1', OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, OrderLinks::RESULT_KNOWN]], $this->links->reports);
    }

    public function testRefusedAutoRefundIsRecordedAndNotRetried(): void
    {
        $this->links->autoRefunds = [['OXID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37']];
        $this->http->queue(502, '{"title":"PayPal refused","reason":"PAYPAL_REFUND_FAILED","detail":"REFUND_AMOUNT_EXCEEDED"}');

        $result = $this->export();

        self::assertSame([['o1', OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, OrderLinks::RESULT_SKIPPED]], $this->links->reports);
        self::assertStringContainsString('please refund in the order tab', $result['errors'][0]);
    }

    public function testAutoRefundAfterAServerErrorIsRetried(): void
    {
        $this->links->autoRefunds = [['OXID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37']];
        $this->http->queue(503, 'Service Unavailable');

        $result = $this->export();

        self::assertSame([], $this->links->reports, 'nothing recorded: the next run tries again');
        self::assertStringContainsString('retried next run', $result['errors'][0]);
    }

    public function testNoAutoRefundWhenSwitchedOff(): void
    {
        $this->settings = new FakeSettings('DHL', false);
        $this->links->autoRefunds = [['OXID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37']];

        $this->export();

        self::assertNull($this->links->autoRefundSince);
        self::assertSame([], $this->http->requests);
    }

    public function testShippedAndCancelledInOneIntervalIsReportedAsShippingThenReturn(): void
    {
        $this->links->fulfillments = [['OXID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37', 'OXTRACKCODE' => '0034']];
        $this->links->revocations = [['ORDERID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37', 'SKU' => 'SKU-42']];
        $this->http->queue(200, '{"everysizeOrderId":"ABC123","status":"PROCESSING"}');
        $this->http->queue(201, '');
        $this->http->queue(200, '{"everysizeOrderId":"ABC123","status":"COMPLETED"}');
        $this->http->queue(204, '');

        $result = $this->export();

        self::assertSame([], $result['errors']);
        self::assertSame(1, $result['fulfillments']);
        self::assertSame(1, $result['returns']);
        self::assertSame(0, $result['revocations']);
        self::assertStringEndsWith('/orders/ABC123/fulfillment', $this->http->requests[2]['url']);
        self::assertSame('{"carrier":"DHL","trackingCode":["0034"]}', $this->http->requests[2]['body']);
        self::assertStringContainsString('RETOUR', $this->http->requests[4]['body']);
        self::assertSame([
            ['o1', OrderLinks::KIND_FULFILLMENT, '', OrderLinks::RESULT_SENT],
            ['o1', OrderLinks::KIND_TRACKING, '0034', OrderLinks::RESULT_SENT],
            ['o1', OrderLinks::KIND_REVOCATION, 'SKU-42', OrderLinks::RESULT_SENT],
        ], $this->links->reports);
    }

    /**
     * @return array{fulfillments: int, trackingCodes: int, revocations: int, returns: int, autoRefunds: int, skipped: int, pending: int, errors: string[]}
     */
    private function export(): array
    {
        $client = new Client($this->http, new FixedCredentials('id', 'secret'), ApiUrlTest::TEST_URL, '7.5.1');

        return (new StatusExporter($client, $this->settings, $this->links))->export();
    }
}

final class FakeSettings extends Settings
{
    private string $carrier;
    private bool $autoRefund;
    private string $autoRefundSince;

    public function __construct(string $carrier, bool $autoRefund = true, string $autoRefundSince = '2026-10-09 18:00:00')
    {
        $this->carrier = $carrier;
        $this->autoRefund = $autoRefund;
        $this->autoRefundSince = $autoRefundSince;
    }

    public function getCarrier(): string
    {
        return $this->carrier;
    }

    public function isAutoRefundDecline(): bool
    {
        return $this->autoRefund;
    }

    public function getAutoRefundSince(): string
    {
        return $this->autoRefundSince;
    }
}

final class FakeOrderLinks extends OrderLinks
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

    public function getPendingAutoRefunds(string $since): array
    {
        $this->autoRefundSince = $since;

        return $this->autoRefunds;
    }

    public function addReport(string $orderId, string $kind, string $sku, string $reason, string $result): void
    {
        $this->reports[] = [$orderId, $kind, $sku, $result];
    }
}
