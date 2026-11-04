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

    public function testAutoRefundLooksBackAtMostFourteenDays(): void
    {
        $this->settings = new FakeSettings('DHL', true, '2020-01-01 00:00:00');

        $this->export();

        self::assertEqualsWithDelta(time() - 14 * 86400, strtotime((string) $this->links->autoRefundSince), 5);
    }

    private function autoRow(array $extra = []): array
    {
        return array_merge(['OXID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37', 'SHIPPED' => '0', 'DISCOUNTED' => '0'], $extra);
    }

    /** Queues what a clean automatic refund reads: the order (for the total), the refund list. */
    private function queueAutoReads(string $list = '[]', string $grossPrice = '124.89'): void
    {
        $this->http->queue(200, '{"everysizeOrderId":"ABC123","status":"PROCESSING","grossPrice":"'.$grossPrice.'"}');
        $this->http->queue(200, $list);
    }

    public function testCancellationBeforeShippingIsRefundedInFullOnce(): void
    {
        $this->links->autoRefunds = [$this->autoRow()];
        $this->queueAutoReads();
        $this->http->queue(201, '{"refundId":"9XD8","status":"COMPLETED","amount":"124.89","currency":"EUR"}');

        $result = $this->export();

        self::assertSame(1, $result['autoRefunds']);
        self::assertSame('GET', $this->http->requests[2]['method']);
        self::assertStringEndsWith('/orders/ABC123/refunds', $this->http->requests[2]['url']);
        $request = $this->http->requests[3];
        self::assertGreaterThanOrEqual(90, $request['timeout'], 'the checkout waits up to 60 s for PayPal');
        self::assertSame('POST', $request['method']);
        self::assertSame('{}', $request['body'], 'no amount: everything not refunded yet');
        self::assertContains('Idempotency-Key: auto-decline-ABC123', $request['headers'], 'fixed per order');
        self::assertSame([], $this->links->openRefunds, 'answered: nothing unclear is left');
        self::assertSame([['o1', OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, OrderLinks::RESULT_SENT]], $this->links->reports);
    }

    public function testAutoRefundOfAnAlreadyRefundedPaymentIsOnlyRemembered(): void
    {
        $this->links->autoRefunds = [$this->autoRow()];
        $this->queueAutoReads();
        $this->http->queue(201, '{"status":"ALREADY_REFUNDED"}');

        self::assertSame(0, $this->export()['autoRefunds']);
        self::assertSame([['o1', OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, OrderLinks::RESULT_KNOWN]], $this->links->reports);
    }

    public function testSafelyRefusedAutoRefundIsRecordedOnceAndNotRetried(): void
    {
        $this->links->autoRefunds = [$this->autoRow()];
        $this->queueAutoReads();
        $this->http->queue(422, '{"title":"PayPal refused","reason":"PAYPAL_REFUND_FAILED","detail":"REFUND_AMOUNT_EXCEEDED"}');

        $result = $this->export();

        self::assertSame([['o1', OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, OrderLinks::RESULT_SKIPPED]], $this->links->reports);
        self::assertSame('PAYPAL_REFUND_FAILED', $this->links->reasons[0]);
        self::assertStringContainsString('please refund in the order', $result['errors'][0]);
        self::assertSame([], $this->links->openRefunds, 'a safe refusal: no money moved, nothing unclear');
    }

    /** @return array<string, array{int, string}> */
    public static function unclearAnswers(): array
    {
        return [
            '503' => [503, 'Service Unavailable'], '502 with a refusal-like reason' => [502, '{"reason":"PAYPAL_REFUND_FAILED"}'],
            '404' => [404, '{"title":"not found"}'], '429' => [429, ''], '401' => [401, ''], '403' => [403, ''],
            '422 key reused' => [422, '{"reason":"IDEMPOTENCY_KEY_REUSED"}'],
        ];
    }

    /**
     * @dataProvider unclearAnswers
     */
    public function testAutoRefundWithoutAProofOfRefusalStaysOpenAndIsNeverCalledFailed(int $status, string $body): void
    {
        $this->links->autoRefunds = [$this->autoRow()];
        $this->queueAutoReads();
        $this->http->queue($status, $body);

        $result = $this->export();

        self::assertSame([], $this->links->reports, 'neither refunded nor refused');
        self::assertArrayHasKey('o1', $this->links->openRefunds);
        self::assertSame('auto-decline-ABC123', $this->links->openRefunds['o1']['key']);
        self::assertStringContainsString('unclear', $result['errors'][0]);
        self::assertStringNotContainsString('refused', $result['errors'][0]);
    }

    public function testAnOpenAutoRefundIsSettledInTheNextRunAfterReadingTheList(): void
    {
        $this->links->openRefunds['o1'] = ['key' => 'auto-decline-ABC123', 'amount' => '', 'created' => FakeOrderLinks::OLD, 'sent' => FakeOrderLinks::OLD, 'knownIds' => [], 'resultId' => ''];
        $this->links->openAutoRefunds = [['ORDER_ID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXID' => 'o1', 'OXORDERNR' => '37']];
        $this->http->queue(200, '[{"refundId":"R-9","status":"COMPLETED","amount":"124.89","currency":"EUR"}]');

        $result = $this->export();

        self::assertCount(2, $this->http->requests, 'token and list, no POST');
        self::assertSame([['o1', OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, OrderLinks::RESULT_SENT]], $this->links->reports);
        self::assertSame([], $this->links->openRefunds);
        self::assertSame(0, $result['autoRefunds'], 'not sent by this run');
    }

    public function testAnOpenAutoRefundWithAnUnreadableListSendsNothing(): void
    {
        $this->links->openRefunds['o1'] = ['key' => 'auto-decline-ABC123', 'amount' => '', 'created' => FakeOrderLinks::OLD, 'sent' => FakeOrderLinks::OLD, 'knownIds' => [], 'resultId' => ''];
        $this->links->openAutoRefunds = [['ORDER_ID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXID' => 'o1', 'OXORDERNR' => '37']];
        $this->http->queue(503, '');

        $result = $this->export();

        self::assertCount(2, $this->http->requests);
        self::assertArrayHasKey('o1', $this->links->openRefunds);
        self::assertSame([], $result['errors'], 'silent: the entry is reported by the 15-minute alarm, not by every read');
    }

    public function testAnOpenRefundOlderThanFifteenMinutesIsReportedInEveryRun(): void
    {
        $this->links->staleOpenRefunds = [['ORDER_ID' => 'o1', 'CREATED' => '2026-11-04 10:00:00']];

        $result = $this->export();

        self::assertStringContainsString('unclear result has been open since 2026-11-04 10:00:00', $result['errors'][0]);
    }

    public function testAutoRefundIsNotSentWhileARefundOfAPersonIsOpen(): void
    {
        $this->links->autoRefunds = [$this->autoRow()];
        $this->links->openRefunds['o1'] = ['key' => 'manual-key', 'amount' => '10.00', 'created' => FakeOrderLinks::OLD, 'sent' => FakeOrderLinks::OLD, 'knownIds' => [], 'resultId' => ''];
        $this->http->queue(200, '{"everysizeOrderId":"ABC123","status":"PROCESSING"}');

        $result = $this->export();

        self::assertSame(0, $result['autoRefunds']);
        foreach ($this->http->requests as $request) {
            self::assertStringNotContainsString('/refunds', $request['url']);
        }
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function blockedRows(): array
    {
        return [
            'shipping signs' => [['SHIPPED' => '1'], 'shipping'],
            'discount' => [['DISCOUNTED' => '1'], 'discount'],
        ];
    }

    /**
     * @param array<string, string> $extra
     */
    /**
     * @dataProvider blockedRows
     */
    public function testAutoRefundIsBlockedWithANote(array $extra, string $word): void
    {
        $this->links->autoRefunds = [$this->autoRow($extra)];

        $result = $this->export();

        self::assertSame(0, $result['autoRefunds']);
        self::assertSame([], $this->http->requests, 'no call at all');
        self::assertSame([['o1', OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, OrderLinks::RESULT_SKIPPED]], $this->links->reports);
        self::assertStringContainsString($word, $result['errors'][0]);
    }

    /** The PayPal list: a completed or pending refund (also one made in PayPal) blocks, failed ones do not. */
    public function testAutoRefundIsBlockedByACompletedOrPendingRefundAtEverysize(): void
    {
        foreach (['COMPLETED', 'PENDING'] as $status) {
            $this->setUp();
            $this->links->autoRefunds = [$this->autoRow()];
            $this->queueAutoReads('[{"refundId":"R1","status":"'.$status.'","amount":"5.00","currency":"EUR"}]');

            $result = $this->export();

            self::assertSame(0, $result['autoRefunds'], $status);
            self::assertCount(3, $this->http->requests, 'token, order, list: no refund call');
            self::assertSame([['o1', OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, OrderLinks::RESULT_SKIPPED]], $this->links->reports, $status);
            self::assertSame([], $this->links->openRefunds);
        }
    }

    public function testAutoRefundIgnoresFailedAndCancelledRefundsInTheList(): void
    {
        $this->links->autoRefunds = [$this->autoRow()];
        $this->queueAutoReads('[{"refundId":"R1","status":"FAILED"},{"refundId":"R2","status":"CANCELLED"}]');
        $this->http->queue(201, '{"refundId":"9XD8","status":"COMPLETED","amount":"124.89","currency":"EUR"}');

        self::assertSame(1, $this->export()['autoRefunds']);
    }

    public function testAutoRefundIsSkippedWhenTheRefundListCannotBeRead(): void
    {
        $this->links->autoRefunds = [$this->autoRow()];
        $this->http->queue(200, '{"everysizeOrderId":"ABC123","status":"PROCESSING","grossPrice":"124.89"}');
        $this->http->queue(503, 'Service Unavailable');

        $result = $this->export();

        self::assertSame(0, $result['autoRefunds']);
        self::assertCount(3, $this->http->requests, 'token, order, list only');
        self::assertSame([], $this->links->reports);
        self::assertSame([], $this->links->openRefunds);
        self::assertStringContainsString('nothing was sent', $result['errors'][0]);
    }

    public function testAnErrorInOneOrderDoesNotStopTheOthers(): void
    {
        $links = new class extends FakeOrderLinks {
            public function addReport(string $orderId, string $kind, string $sku, string $reason, string $result): void
            {
                if ($orderId === 'o1') {
                    throw new \RuntimeException('database gone');
                }
                parent::addReport($orderId, $kind, $sku, $reason, $result);
            }
        };
        $links->autoRefunds = [$this->autoRow(['SHIPPED' => '1']), $this->autoRow(['OXID' => 'o2', 'SHIPPED' => '1'])];
        $this->links = $links;

        $result = $this->export();

        self::assertSame([['o2', OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, OrderLinks::RESULT_SKIPPED]], $links->reports);
        self::assertStringContainsString('database gone', implode("\n", $result['errors']));
    }

    /** A failed shipping report must not be followed by a return in the same run: the shipping comes first. */
    public function testAReturnIsNotReportedWhileTheShippingReportFails(): void
    {
        $this->links->fulfillments = [['OXID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37', 'OXTRACKCODE' => '']];
        $this->links->revocations = [['ORDERID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37', 'SKU' => 'S1']];
        $this->links->shippedOrders = ['o1'];
        $this->http->queue(200, '{"everysizeOrderId":"ABC123","status":"PROCESSING"}');
        $this->http->queue(503, '');

        $result = $this->export();

        self::assertSame(0, $result['returns']);
        self::assertSame([], $this->links->reports);
        self::assertCount(3, $this->http->requests, 'token, order, failed fulfillment: no revocation');
    }

    /** A2: an open automatic entry is only checked against the list: no match, no POST, whatever its age. */
    public function testAnOpenAutoRefundWithoutAMatchIsNeverSentAgain(): void
    {
        $this->links->openRefunds['o1'] = ['key' => 'auto-decline-ABC123', 'amount' => '', 'created' => FakeOrderLinks::OLD, 'sent' => FakeOrderLinks::OLD, 'knownIds' => [], 'resultId' => ''];
        $this->links->openAutoRefunds = [['ORDER_ID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXID' => 'o1', 'OXORDERNR' => '37']];
        $this->http->queue(200, '[]');

        $result = $this->export();

        self::assertCount(2, $this->http->requests, 'token and list: no POST');
        self::assertArrayHasKey('o1', $this->links->openRefunds);
        self::assertSame(0, $result['autoRefunds']);
    }

    /** A2: the setting and the conditions are asked again right before the send, for exactly this order. */
    public function testAnOrderThatIsNoLongerDueIsNotRefunded(): void
    {
        $links = new class extends FakeOrderLinks {
            public function getPendingAutoRefunds(string $since, ?string $onlyOrderId = null): array
            {
                return $onlyOrderId === null ? $this->autoRefunds : []; // gone / refunded / cancelled back in between
            }
        };
        $links->autoRefunds = [$this->autoRow()];
        $this->links = $links;

        $result = $this->export();

        self::assertSame(0, $result['autoRefunds']);
        self::assertSame([], $this->http->requests);
    }

    /** A11: "1,234.56" is read as a thousand euros. */
    public function testAGrossPriceOverAThousandIsReadRight(): void
    {
        $this->links->autoRefunds = [$this->autoRow()];
        $this->queueAutoReads('[]', '1,234.56');
        $this->http->queue(201, '{"refundId":"9XD8","status":"COMPLETED","amount":"1234.56","currency":"EUR"}');

        self::assertSame(1, $this->export()['autoRefunds']);
    }

    /** The lowered total after a position was cancelled is no reason to block: the amount paid is the grossPrice. */
    public function testALoweredShopTotalAfterAPositionCancellationDoesNotBlock(): void
    {
        $this->links->autoRefunds = [$this->autoRow(['TOTAL' => '0.00'])];
        $this->queueAutoReads('[]', '124.89');
        $this->http->queue(201, '{"refundId":"9XD8","status":"COMPLETED","amount":"124.89","currency":"EUR"}');

        self::assertSame(1, $this->export()['autoRefunds']);
    }

    /** @return array<string, array{string}> */
    public static function unusableGrossPrices(): array
    {
        return ['missing' => [''], 'text' => ['"abc"'], 'null' => ['null']];
    }

    /**
     * @dataProvider unusableGrossPrices
     */
    public function testWithoutAReadableAmountPaidTheAutomaticRefundDoesNotSend(string $json): void
    {
        $this->links->autoRefunds = [$this->autoRow()];
        $this->http->queue(200, '{"everysizeOrderId":"ABC123","status":"PROCESSING"'.($json === '' ? '' : ',"grossPrice":'.$json).'}');

        $result = $this->export();

        self::assertSame(0, $result['autoRefunds']);
        self::assertCount(2, $this->http->requests, 'token and order only');
        self::assertSame('TOTAL_UNKNOWN', $this->links->reasons[0]);
    }

    /** A13: a cancellation the automatic refund leaves out gets a note once. */
    public function testSilentlySkippedCancellationsGetOneNote(): void
    {
        $this->links->unhandledDeclines = [['OXID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37', 'CODE' => 'BEFORE_CUTOFF']];

        $result = $this->export();

        self::assertSame([['o1', OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, OrderLinks::RESULT_SKIPPED]], $this->links->reports, 'the report keeps the query from finding it again');
        self::assertSame('BEFORE_CUTOFF', $this->links->reasons[0]);
        self::assertStringContainsString('switch-on date', $result['errors'][0]);
    }

    public function testNoNotesWhenTheAutomaticRefundIsSwitchedOff(): void
    {
        $this->settings = new FakeSettings('DHL', false);
        $this->links->unhandledDeclines = [['OXID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37', 'CODE' => 'BEFORE_CUTOFF']];

        $result = $this->export();

        self::assertSame([], $this->links->reports);
        self::assertSame([], $result['errors']);
    }

    /** An unfinished module update (no table, extension not active) switches the refund part off. */
    public function testAnUnfinishedUpdateSwitchesTheRefundPartOffLoudly(): void
    {
        $this->links->updateProblems = ['the order model extension is not active (deactivate and activate the module)'];
        $this->links->autoRefunds = [$this->autoRow()];
        $this->links->openAutoRefunds = [['OXID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37']];

        $result = $this->export();

        self::assertSame(0, $result['autoRefunds']);
        self::assertSame([], $this->http->requests);
        self::assertStringContainsString('update is not finished', $result['errors'][0]);
    }

    public function testNoAutoRefundWhenSwitchedOff(): void
    {
        $this->settings = new FakeSettings('DHL', false);
        $this->links->autoRefunds = [['OXID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37']];

        $this->export();

        self::assertNull($this->links->autoRefundSince);
        self::assertSame([], $this->http->requests);
    }

    /** R4: a shipped order is cancelled as a return, also when its shipping report has not gone out (yet). */
    public function testCancellationOfAnOrderThatShowsSignsOfShippingIsReportedAsReturn(): void
    {
        $this->links->revocations = [['ORDERID' => 'o1', 'EVERYSIZE_ORDER_ID' => 'ABC123', 'OXORDERNR' => '37', 'SKU' => 'SKU-42']];
        $this->links->shippedOrders = ['o1'];
        $this->http->queue(200, '{"everysizeOrderId":"ABC123","status":"COMPLETED"}');
        $this->http->queue(204, '');

        $result = $this->export();

        self::assertSame(1, $result['returns']);
        self::assertSame(0, $result['revocations']);
        self::assertStringContainsString('"reason":"RETOUR"', $this->http->requests[2]['body']);
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
