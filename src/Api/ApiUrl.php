<?php

declare(strict_types=1);

namespace Everysize\Checkout\Api;

/**
 * Base URL of the everysize checkout API.
 *
 * Without EVERYSIZE_CHECKOUT_API_URL this is always production, so a merchant
 * installation talks to the live checkout without any setup. A test or
 * development shop sets the address of an everysize test environment there;
 * everysize hands it out together with the test credentials.
 *
 * A value that is set must be https on a host under everysize.com, without
 * port or path. Anything else is an error and does NOT fall back to
 * production: a test shop with a typo must never send its orders to the live
 * checkout.
 */
final class ApiUrl
{
    public const PRODUCTION = 'https://ext-checkout.everysize.com';
    public const ENV = 'EVERYSIZE_CHECKOUT_API_URL';

    private const PATTERN = '~^https://([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+everysize\.com$~';

    /**
     * @param string|null $configured value to check; null reads EVERYSIZE_CHECKOUT_API_URL
     *                                from the environment (OXID loads .env/.env.local with putenv)
     */
    public static function resolve(?string $configured = null): string
    {
        $value = strtolower(rtrim(trim($configured ?? self::fromEnvironment()), '/'));
        if ($value === '') {
            return self::PRODUCTION;
        }

        if (!preg_match(self::PATTERN, $value)) {
            throw new ApiException(sprintf('%s=%s is not allowed: expected https://<host>.everysize.com without port or path', self::ENV, $value));
        }

        return $value;
    }

    public static function isProduction(string $url): bool
    {
        return $url === self::PRODUCTION;
    }

    private static function fromEnvironment(): string
    {
        foreach ([getenv(self::ENV), $_ENV[self::ENV] ?? null, $_SERVER[self::ENV] ?? null] as $value) {
            if (\is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return '';
    }
}
