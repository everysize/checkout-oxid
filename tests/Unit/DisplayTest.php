<?php

declare(strict_types=1);

namespace Everysize\Checkout\Tests\Unit;

use Everysize\Checkout\Display;
use PHPUnit\Framework\TestCase;

final class DisplayTest extends TestCase
{
    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    public function testStoredTimeIsShopTimeAlready(): void
    {
        self::assertSame('14.10.2026 15:05', Display::dateTime('2026-10-14 15:05:42'));
    }

    public function testPayPalTimeIsConvertedToShopTime(): void
    {
        self::assertSame('09.10.2026 15:27', Display::dateTime('2026-10-09T06:27:10-07:00'));
        self::assertSame('14.10.2026 17:33', Display::dateTime('2026-10-14T15:33:00Z'));
    }

    public function testFormatCanBeChosen(): void
    {
        self::assertSame('2026-10-14 15:05', Display::dateTime('2026-10-14 15:05:42', 'Y-m-d H:i'));
    }

    public function testEmptyAndZeroDatesAreEmpty(): void
    {
        self::assertSame('', Display::dateTime(''));
        self::assertSame('', Display::dateTime('0000-00-00 00:00:00'));
    }

    public function testUnreadableValueIsShownAsItIs(): void
    {
        self::assertSame('gestern', Display::dateTime('gestern'));
    }
}
