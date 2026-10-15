<?php

declare(strict_types=1);

namespace Everysize\Checkout;

/**
 * Formatting for the admin pages.
 *
 * The module stores times as "Y-m-d H:i:s" in PHP's default time zone, which
 * is the shop's time zone (OXID has no time zone setting of its own). PayPal
 * times come as ISO 8601 with an offset and are converted to it.
 */
final class Display
{
    /** Stored or ISO 8601 time in the shop's time zone; '' for an empty or zero date, the input itself if unreadable. */
    public static function dateTime(string $value, string $format = 'd.m.Y H:i'): string
    {
        $value = trim($value);
        if ($value === '' || strncmp($value, '0000-00-00', 10) === 0) {
            return '';
        }

        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->format($format);
        } catch (\Exception $e) {
            return $value;
        }
    }
}
