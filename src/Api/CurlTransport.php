<?php

declare(strict_types=1);

namespace Everysize\Checkout\Api;

final class CurlTransport implements HttpTransport
{
    private const CONNECT_TIMEOUT = 10;
    private const TIMEOUT = 30;

    public function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        $ch = curl_init($url);
        $options = [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            \CURLOPT_TIMEOUT => self::TIMEOUT,
            \CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($body !== null) {
            $options[\CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new ApiException(sprintf('everysize API not reachable (%s %s): %s', $method, $url, $error));
        }

        $status = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $response];
    }
}
