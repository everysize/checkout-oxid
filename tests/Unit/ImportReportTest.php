<?php

declare(strict_types=1);

namespace Everysize\Checkout\Tests\Unit;

use Everysize\Checkout\Api\Client;
use Everysize\Checkout\Import\ImportReport;
use PHPUnit\Framework\TestCase;

final class ImportReportTest extends TestCase
{
    private FakeTransport $http;
    private Client $client;

    protected function setUp(): void
    {
        $this->http = new FakeTransport();
        $this->http->queue(200, '{"access_token":"tok","token_type":"bearer","expires_in":3600,"shop_id":4711}');
        $this->client = new Client($this->http, new FixedCredentials('id', 'secret'), ApiUrlTest::TEST_URL, '7.5.1');
    }

    public function testOnlyRunningOrdersAreReportable(): void
    {
        self::assertTrue(ImportReport::isReportable('PROCESSING'));
        self::assertTrue(ImportReport::isReportable('completed'));
        self::assertFalse(ImportReport::isReportable('REVOKED'));
        self::assertFalse(ImportReport::isReportable('REVOKING'));
        self::assertFalse(ImportReport::isReportable(''));
    }

    public function testStockOnlyWhereTheShopLimitsSales(): void
    {
        self::assertNull(ImportReport::stock(1, 5.0), 'standard: oversell allowed');
        self::assertNull(ImportReport::stock(4, 5.0), 'external storehouse');
        self::assertSame(5, ImportReport::stock(2, 5.0));
        self::assertSame(0, ImportReport::stock(3, 0.0));
        self::assertSame(-2, ImportReport::stock(3, -2.0));
    }

    public function testStockFlagsOneAndFourAreLimitedWithReservationsAndNoNegativeStock(): void
    {
        self::assertSame(5, ImportReport::stock(1, 5.0, true, false));
        self::assertSame(0, ImportReport::stock(4, 0.0, true, false));
        self::assertNull(ImportReport::stock(1, 5.0, true, true), 'negative stock allowed: oversell possible');
        self::assertNull(ImportReport::stock(4, 5.0, false, false), 'no reservations: oversell possible');
        self::assertNull(ImportReport::stock(1, 5.0, false, true));
    }

    public function testStockFlagsTwoAndThreeStayLimitedWhateverTheReservationSettings(): void
    {
        foreach ([[false, false], [true, false], [true, true], [false, true]] as [$reservation, $negative]) {
            self::assertSame(3, ImportReport::stock(2, 3.0, $reservation, $negative));
            self::assertSame(3, ImportReport::stock(3, 3.0, $reservation, $negative));
        }
    }

    public function testNetPricesWithVatOnlyInTheBasketAreNotReported(): void
    {
        self::assertFalse(ImportReport::priceIsGrossEuro(true, true, 'EUR'), 'getBruttoPrice is net here');
        self::assertTrue(ImportReport::priceIsGrossEuro(true, false, 'EUR'), 'net mode with VAT calculation is converted');
        self::assertTrue(ImportReport::priceIsGrossEuro(false, true, 'EUR'), 'gross prices stay gross');
        self::assertTrue(ImportReport::priceIsGrossEuro(false, false, 'EUR'));
    }

    public function testPriceOnlyInEuro(): void
    {
        self::assertTrue(ImportReport::priceIsGrossEuro(false, false, 'eur'));
        self::assertTrue(ImportReport::priceIsGrossEuro(false, false, ' EUR '));
        self::assertFalse(ImportReport::priceIsGrossEuro(false, false, 'CHF'));
        self::assertFalse(ImportReport::priceIsGrossEuro(false, false, 'GBP'));
        self::assertFalse(ImportReport::priceIsGrossEuro(false, false, null), 'unknown currency');
        self::assertFalse(ImportReport::priceIsGrossEuro(false, false, ''));
    }

    public function testEntryFormatsPriceWithDotAndTwoDecimals(): void
    {
        self::assertSame(['sku' => 'A', 'shopPrice' => '109.95', 'stock' => 3], ImportReport::entry('A', 109.95, 3));
        self::assertSame('110.00', ImportReport::entry('A', 110.0, null)['shopPrice']);
        self::assertNull(ImportReport::entry('A', null, null)['shopPrice']);
        self::assertNull(ImportReport::entry('A', NAN, null)['shopPrice']);
    }

    public function testNoWarningsForSamePriceAndEnoughStock(): void
    {
        $entry = ImportReport::entry('A', 109.95, 3);

        self::assertSame([], ImportReport::warnings('ES1', $entry, 109.95, 2));
        self::assertSame([], ImportReport::warnings('ES1', $entry, 109.95, 3), 'stock equal to quantity is fine');
        self::assertSame([], ImportReport::warnings('ES1', $entry, 109.954, 1), 'within 0.009');
        self::assertSame([], ImportReport::warnings('ES1', ImportReport::entry('A', null, null), 99.0, 5), 'unknown is no warning');
    }

    public function testWarningsForPriceDifferenceAndOversell(): void
    {
        $entry = ImportReport::entry('A', 129.95, 0);

        $warnings = ImportReport::warnings('ES1', $entry, 109.95, 1);

        self::assertCount(2, $warnings);
        self::assertStringContainsString('129.95', $warnings[0]);
        self::assertStringContainsString('109.95', $warnings[0]);
        self::assertStringContainsString('stock 0', $warnings[1]);
        self::assertCount(1, ImportReport::warnings('ES1', ImportReport::entry('A', 109.97, 9), 109.95, 1), 'above 0.009');
    }

    public function testSendPostsOneReportPerPosition(): void
    {
        $this->http->queue(204, '');
        $this->http->queue(204, '');

        $warnings = ImportReport::send($this->client, 'ES 1', [
            ImportReport::entry('CW2288-111-42.5', 109.95, 3),
            ImportReport::entry('CW2288-111-43', null, null),
        ]);

        self::assertSame([], $warnings);
        self::assertCount(3, $this->http->requests, 'token plus two reports');
        self::assertSame('POST', $this->http->requests[1]['method']);
        self::assertSame(ApiUrlTest::TEST_URL.'/api/v1/shops/4711/orders/ES%201/import-report', $this->http->requests[1]['url']);
        self::assertSame('{"sku":"CW2288-111-42.5","shopPrice":"109.95","stock":3}', $this->http->requests[1]['body']);
        self::assertSame('{"sku":"CW2288-111-43","shopPrice":null,"stock":null}', $this->http->requests[2]['body']);
    }

    public function testFailedReportIsOnlyAWarningAndNotRetried(): void
    {
        $this->http->queue(404, '{"title":"Not Found","detail":"No route"}');
        $this->http->queue(500, 'boom');

        $warnings = ImportReport::send($this->client, 'ES1', [
            ImportReport::entry('A', 1.0, 1),
            ImportReport::entry('B', 1.0, 1),
        ]);

        self::assertCount(2, $warnings);
        self::assertStringContainsString('HTTP 404', $warnings[0]);
        self::assertCount(3, $this->http->requests, 'no retry');
    }

    public function testTransportErrorDoesNotEscape(): void
    {
        // nothing queued: the fake transport throws a LogicException on the report request
        $warnings = ImportReport::send($this->client, 'ES1', [ImportReport::entry('A', 1.0, 1)]);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('import report not sent', $warnings[0]);
    }
}
