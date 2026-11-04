<?php

declare(strict_types=1);

namespace Everysize\Checkout\Tests\Unit;

use Everysize\Checkout\Api\ApiException;
use Everysize\Checkout\Api\Client;

/**
 * A client whose three refund-related calls are scripted, for the decision logic of
 * ManualRefund. The real wire format is tested with ClientTest and the FakeTransport.
 */
final class StubClient extends Client
{
    /** @var array<int, array<string, mixed>> */
    public array $list = [];

    public ?ApiException $connectError = null;

    public ?ApiException $listError = null;

    /** @var array<string, mixed>|ApiException|\Throwable */
    public $refundAnswer = ['refundId' => 'R-1', 'status' => 'COMPLETED', 'amount' => '10.00', 'currency' => 'EUR'];

    /** @var array<int, string> */
    public array $calls = [];

    /** @var array<int, array{string, ?string, string}> order, amount, key of every refund POST */
    public array $posts = [];

    /** Called at POST time, e.g. to look at what is stored by then. */
    public ?\Closure $onPost = null;

    /** Called when the refund list is read. */
    public ?\Closure $onList = null;

    public function __construct()
    {
        parent::__construct(new FakeTransport(), new FixedCredentials('id', 'secret'), ApiUrlTest::TEST_URL, '7.5.1');
    }

    public function getShopId(): int
    {
        $this->calls[] = 'connect';
        if ($this->connectError !== null) {
            throw $this->connectError;
        }

        return 4711;
    }

    public function getRefunds(string $everysizeOrderId): array
    {
        $this->calls[] = 'list';
        if ($this->onList !== null) {
            ($this->onList)();
        }
        if ($this->listError !== null) {
            throw $this->listError;
        }

        return $this->list;
    }

    public function refund(string $everysizeOrderId, ?string $amount, string $idempotencyKey): array
    {
        $this->calls[] = 'post';
        $this->posts[] = [$everysizeOrderId, $amount, $idempotencyKey];
        if ($this->onPost !== null) {
            ($this->onPost)();
        }
        if ($this->refundAnswer instanceof \Throwable) {
            throw $this->refundAnswer;
        }

        return $this->refundAnswer;
    }
}
