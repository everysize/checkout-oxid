<?php

declare(strict_types=1);

namespace Everysize\Checkout\Api;

interface HttpTransport
{
    /**
     * @param string[] $headers complete header lines ("Name: value")
     * @param int      $timeout total timeout in seconds, 0 = the transport's default
     *
     * @return array{status: int, body: string}
     *
     * @throws ApiException if the API is not reachable (network, DNS, TLS)
     */
    public function request(string $method, string $url, array $headers, ?string $body = null, int $timeout = 0): array;
}
