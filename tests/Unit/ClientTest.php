<?php

declare(strict_types=1);

namespace Everysize\Checkout\Tests\Unit;

use Everysize\Checkout\Api\ApiException;
use Everysize\Checkout\Api\Client;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private FakeTransport $http;
    private Client $client;

    protected function setUp(): void
    {
        $this->http = new FakeTransport();
        $this->http->queue(200, '{"access_token":"tok","token_type":"bearer","expires_in":3600,"shop_id":4711}');
        $this->client = new Client($this->http, new FixedCredentials('id', 'secret'), ApiUrlTest::TEST_URL, '7.5.1');
    }

    public function testTokenUsesBasicAuthAndIsReused(): void
    {
        $this->http->queue(200, '{"content":[{"everysizeOrderId":"ABC123"}],"totalElements":1,"totalPages":1}');
        $this->http->queue(200, '{"content":[],"totalElements":0,"totalPages":0}');

        self::assertSame([['everysizeOrderId' => 'ABC123']], $this->client->getOpenOrders());
        self::assertSame([], $this->client->getOpenOrders());

        self::assertCount(3, $this->http->requests, 'one token for both calls');
        $token = $this->http->requests[0];
        self::assertSame('POST', $token['method']);
        self::assertSame(ApiUrlTest::TEST_URL.'/api/v1/oauth/token', $token['url']);
        self::assertContains('Authorization: Basic '.base64_encode('id:secret'), $token['headers']);
        self::assertContains('ERP-Shop-System: OXID', $token['headers']);
        self::assertContains('ERP-Shop-System-Version: 7.5.1', $token['headers']);

        $orders = $this->http->requests[1];
        self::assertSame(
            ApiUrlTest::TEST_URL.'/api/v1/shops/4711/orders?status=PROCESSING,REVOKING,REVOKED&acknowledged=false&pageNumber=0&pageSize=100',
            $orders['url']
        );
        self::assertContains('Authorization: Bearer tok', $orders['headers']);
    }

    public function testMerchantOrderNumberAlreadySetCountsAsDone(): void
    {
        $this->http->queue(204, '');
        $this->http->queue(403, '{"type":"about:blank","title":"Forbidden","reason":"MERCHANT_ORDER_NUMBER_CANNOT_BE_MODIFIED","detail":"x"}');

        self::assertTrue($this->client->sendMerchantOrderNumber('ABC123', '10001'));
        self::assertFalse($this->client->sendMerchantOrderNumber('ABC123', '10001'));
        self::assertSame('{"merchantOrderNumber":"10001"}', $this->http->requests[1]['body']);
    }

    public function testOtherErrorsAreReported(): void
    {
        $this->http->queue(404, '{"type":"about:blank","title":"Not Found","reason":"RESOURCE_NOT_FOUND","detail":"Order ID ABC123 not found."}');

        try {
            $this->client->sendMerchantOrderNumber('ABC123', '10001');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(404, $e->getStatus());
            self::assertSame('RESOURCE_NOT_FOUND', $e->getReason());
            self::assertStringContainsString('Order ID ABC123 not found.', $e->getMessage());
        }
    }

    public function testFulfillmentWithAndWithoutTracking(): void
    {
        $this->http->queue(201, '{}');
        $this->http->queue(201, '{}');
        $this->http->queue(201, '{}');

        $this->client->sendFulfillment('ABC123', 'DHL', '00340434161234567890');
        $this->client->sendFulfillment('ABC123', 'DHL', '');
        $this->client->sendFulfillment('ABC123', '', '00340434161234567890');

        self::assertSame(ApiUrlTest::TEST_URL.'/api/v1/shops/4711/orders/ABC123/fulfillment', $this->http->requests[1]['url']);
        self::assertSame('{"carrier":"DHL","trackingCode":["00340434161234567890"]}', $this->http->requests[1]['body']);
        // An empty body is rejected by everysize (422), so "no tracking" is "{}".
        self::assertSame('{}', $this->http->requests[2]['body']);
        self::assertSame('{}', $this->http->requests[3]['body']);
    }

    public function testRevocation(): void
    {
        $this->http->queue(204, '');

        $this->client->sendRevocation('ABC123', 'CW2288-111-42.5', Client::REASON_RETOUR);

        self::assertSame(ApiUrlTest::TEST_URL.'/api/v1/shops/4711/orders/ABC123/revocations', $this->http->requests[1]['url']);
        self::assertSame('{"sku":"CW2288-111-42.5","reason":"RETOUR","remainingQuantity":0}', $this->http->requests[1]['body']);
    }

    public function testInvalidCredentials(): void
    {
        $http = new FakeTransport();
        $http->queue(403, '{"error":"unauthorized_client","error_description":"Invalid clientId/clientSecret."}');
        $client = new Client($http, new FixedCredentials('id', 'wrong'), ApiUrlTest::TEST_URL);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid clientId/clientSecret.');
        $client->getOpenOrders();
    }

    public function testMissingCredentialsMakeNoRequest(): void
    {
        $http = new FakeTransport();
        $client = new Client($http, new FixedCredentials('', ''), ApiUrlTest::TEST_URL);

        try {
            $client->getOpenOrders();
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame([], $http->requests);
        }
    }

    public function testRefundSendsTheKeyWithALongTimeout(): void
    {
        $this->http->queue(200, '{"refundId":"R1","status":"COMPLETED"}');

        self::assertSame('R1', $this->client->refund('ABC123', '5.00', 'key_1-A')['refundId']);

        $request = $this->http->requests[1];
        self::assertContains('Idempotency-Key: key_1-A', $request['headers']);
        self::assertSame(Client::REFUND_TIMEOUT, $request['timeout']);
        self::assertGreaterThanOrEqual(90, Client::REFUND_TIMEOUT, 'the checkout waits up to 60 s for PayPal');
        self::assertSame(0, $this->http->requests[0]['timeout'], 'the token call keeps the default');
    }

    public function testAnyTwoHundredIsARefund(): void
    {
        $this->http->queue(200, '{"refundId":"R1"}');
        self::assertSame('R1', $this->client->refund('ABC123', null, 'k')['refundId']);
    }

    public function testRefundRefusesAKeyTheCheckoutWouldNotTake(): void
    {
        foreach (['', str_repeat('a', 65), "a\r\nb", 'a b', 'ä'] as $key) {
            try {
                $this->client->refund('ABC123', '5.00', $key);
                self::fail('key accepted: '.json_encode($key));
            } catch (\InvalidArgumentException $e) {
                self::assertSame([], $this->http->requests, 'no request');
            }
        }
        self::assertTrue(Client::isValidIdempotencyKey(str_repeat('a', 64)));
    }

    public function testServerErrorsAndMissingAnswersAreUnclear(): void
    {
        $this->http->queue(502, '{"title":"PayPal did not answer"}');
        try {
            $this->client->refund('ABC123', '5.00', 'k');
            self::fail('no exception');
        } catch (ApiException $e) {
            self::assertTrue($e->isOutcomeUnclear());
        }

        self::assertTrue((new ApiException('timeout'))->isOutcomeUnclear(), 'status 0');
        foreach ([300, 401, 403, 404, 408, 409, 425, 429, 500] as $status) {
            self::assertTrue((new ApiException('x', $status))->isOutcomeUnclear(), 'HTTP '.$status.' proves nothing');
        }
        self::assertTrue((new ApiException('x', 422, 'IDEMPOTENCY_KEY_REUSED'))->isOutcomeUnclear());
        self::assertTrue((new ApiException('x', 404, 'PAYPAL_REFUND_FAILED'))->isOutcomeUnclear(), 'the reason counts only with its status');
        self::assertFalse((new ApiException('x', 422, 'PAYPAL_REFUND_FAILED'))->isOutcomeUnclear());
        self::assertFalse((new ApiException('x', 422, 'REFUND_AMOUNT_INVALID'))->isOutcomeUnclear());
        self::assertFalse((new ApiException('x', 400, 'ORDER_NOT_PAID_USING_EVERYSIZE_CHECKOUT_PAYMENTS'))->isOutcomeUnclear());
        self::assertTrue((new ApiException('x', 422, 'PAYPAL_REFUND_FAILED'))->isSafeRejection());
    }

    public function testATokenFailureBeforeTheRefundPostIsNotSent(): void
    {
        $http = new FakeTransport();
        $http->queue(503, '{"title":"down"}'); // the token call itself fails
        $client = new Client($http, new FixedCredentials('id', 'secret'), ApiUrlTest::TEST_URL, '7.5.1');

        try {
            $client->refund('ABC123', '5.00', 'k');
            self::fail('no exception');
        } catch (ApiException $e) {
            self::assertTrue($e->isNotSent());
            self::assertFalse($e->isOutcomeUnclear(), 'nothing was sent, nothing is unclear');
            self::assertFalse($e->isSafeRejection());
        }
        self::assertCount(1, $http->requests, 'the token request only');
    }

    /** A9: only a real list whose entries have a refund ID and a status is readable. */
    public function testOnlyARealRefundListIsReadable(): void
    {
        $this->http->queue(200, '[]');
        $this->http->queue(200, '[{"refundId":"R1","status":"COMPLETED","amount":"1.00"}]');
        self::assertSame([], $this->client->getRefunds('ABC123'));
        self::assertSame('R1', $this->client->getRefunds('ABC123')[0]['refundId']);

        foreach (['{}', '{"refundId":"R1","status":"COMPLETED"}', '[{"status":"COMPLETED"}]', '[{"refundId":"R1"}]', '[{"refundId":"","status":"COMPLETED"}]', '[1,2]', '"x"', 'not json', ''] as $body) {
            $this->http->queue(200, $body);
            try {
                $this->client->getRefunds('ABC123');
                self::fail('read as a list: '.$body);
            } catch (ApiException $e) {
                self::assertStringContainsString('Refunds of ABC123 failed', $e->getMessage());
            }
        }
        $this->http->queue(500, '[]');
        $this->expectException(ApiException::class);
        $this->client->getRefunds('ABC123');
    }

    /** A counting refund without a readable amount makes the whole list unusable (it would count as 0). */
    public function testACountingRefundWithoutAReadableAmountMakesTheListUnreadable(): void
    {
        foreach ([
            '[{"refundId":"R1","status":"COMPLETED"}]',
            '[{"refundId":"R1","status":"PENDING","amount":""}]',
            '[{"refundId":"R1","status":"COMPLETED","amount":"1.234,56"}]',
            '[{"refundId":"R1","status":"COMPLETED","amount":"1,234.56"}]',
            '[{"refundId":"R1","status":"completed","amount":null}]',
            '[{"refundId":"R1","status":"COMPLETED","amount":10}]',
            '[{"refundId":"R0","status":"COMPLETED","amount":"1.00"},{"refundId":"R1","status":"PENDING","amount":"abc"}]',
        ] as $body) {
            $this->http->queue(200, $body);
            try {
                $this->client->getRefunds('ABC123');
                self::fail('read as a list: '.$body);
            } catch (ApiException $e) {
                self::assertStringContainsString('Refunds of ABC123 failed', $e->getMessage());
            }
        }
        // A refund that does not count may lack its amount; counting ones read as PayPal writes them.
        $this->http->queue(200, '[{"refundId":"R1","status":"FAILED"},{"refundId":"R2","status":"CANCELLED","amount":"x"},{"refundId":"R3","status":"COMPLETED","amount":"1234.56"},{"refundId":"R4","status":"PENDING","amount":"10"}]');
        self::assertCount(4, $this->client->getRefunds('ABC123'));
    }
}
