<?php

declare(strict_types=1);

namespace Everysize\Checkout\Tests\Unit;

use Everysize\Checkout\Api\HttpTransport;

final class FakeTransport implements HttpTransport
{
    /** @var array<int, array{method: string, url: string, headers: string[], body: ?string}> */
    public array $requests = [];

    /** @var array<int, array{status: int, body: string}> */
    private array $responses = [];

    public function queue(int $status, string $body): void
    {
        $this->responses[] = ['status' => $status, 'body' => $body];
    }

    public function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if ($this->responses === []) {
            throw new \LogicException('unexpected request '.$method.' '.$url);
        }

        return array_shift($this->responses);
    }
}
