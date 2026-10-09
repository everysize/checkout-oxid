<?php

declare(strict_types=1);

namespace Everysize\Checkout\Tests\Unit;

use Everysize\Checkout\Api\ApiException;
use Everysize\Checkout\Api\Client;
use Everysize\Checkout\Api\Credentials;
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
}
