<?php

declare(strict_types=1);

namespace Everysize\Checkout\Tests\Unit;

use Everysize\Checkout\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Settings needs the shop database, so only its public API is checked here: a
 * docblock without its end once commented out get()/set() — php -l was fine,
 * the admin page and the sync were not.
 */
final class SettingsTest extends TestCase
{
    public function testPublicApi(): void
    {
        foreach (['get', 'set', 'isConfigured', 'getClientId', 'getClientSecret', 'getDeliverySetId', 'getCarrier', 'isAutoRefundDecline', 'getAutoRefundSince'] as $method) {
            self::assertTrue(method_exists(Settings::class, $method), $method);
        }
    }
}
