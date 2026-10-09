<?php

declare(strict_types=1);

namespace Everysize\Checkout\Tests\Unit;

use Everysize\Checkout\Api\ApiException;
use Everysize\Checkout\Api\ApiUrl;
use PHPUnit\Framework\TestCase;

final class ApiUrlTest extends TestCase
{
    public const TEST_URL = 'https://example.everysize.com';

    public function testDefaultsToProduction(): void
    {
        self::assertSame(ApiUrl::PRODUCTION, ApiUrl::resolve(''));
        self::assertSame(ApiUrl::PRODUCTION, ApiUrl::resolve('   '));
        self::assertTrue(ApiUrl::isProduction(ApiUrl::resolve('')));
    }

    public function testAcceptsAnEverysizeHostWithTrailingSlash(): void
    {
        self::assertSame(self::TEST_URL, ApiUrl::resolve(self::TEST_URL.'/'));
        self::assertSame('https://api.test.example.everysize.com', ApiUrl::resolve('https://API.test.Example.everysize.com'));
        self::assertFalse(ApiUrl::isProduction(ApiUrl::resolve(self::TEST_URL)));
    }

    /**
     * A typo must not silently fall back to production.
     *
     * @dataProvider notAllowed
     */
    public function testRejectsEverythingElse(string $value): void
    {
        $this->expectException(ApiException::class);
        ApiUrl::resolve($value);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function notAllowed(): array
    {
        return [
            'http' => ['http://example.everysize.com'],
            'other host' => ['https://example.com'],
            'lookalike host' => ['https://example-everysize.com'],
            'suffix trick' => ['https://example.everysize.com.example.com'],
            'bare domain' => ['https://everysize.com'],
            'port' => ['https://example.everysize.com:8443'],
            'path' => ['https://ext-checkout.everysize.com/api'],
            'credentials' => ['https://user:pass@example.everysize.com'],
        ];
    }

    public function testReadsTheEnvironment(): void
    {
        putenv(ApiUrl::ENV.'='.self::TEST_URL);
        try {
            self::assertSame(self::TEST_URL, ApiUrl::resolve());
        } finally {
            putenv(ApiUrl::ENV);
        }
        self::assertSame(ApiUrl::PRODUCTION, ApiUrl::resolve());
    }
}
