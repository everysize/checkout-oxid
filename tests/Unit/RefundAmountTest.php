<?php

declare(strict_types=1);

namespace Everysize\Checkout\Tests\Unit;

use Everysize\Checkout\RefundAmount;
use PHPUnit\Framework\TestCase;

final class RefundAmountTest extends TestCase
{
    public function testAcceptedInput(): void
    {
        foreach ([
            '10' => '10.00',
            '10,5' => '10.50',
            '10.50' => '10.50',
            '124,89' => '124.89',
            '1.234,56' => '1234.56',
            '1,234.56' => '1234.56',
            '1234,56' => '1234.56',
            ' 9,99 € ' => '9.99',
            '0,01' => '0.01',
        ] as $input => $expected) {
            self::assertSame($expected, RefundAmount::normalize((string) $input), (string) $input);
        }
    }

    public function testRejectedInput(): void
    {
        foreach (['', '0', '0,00', '-5', 'abc', '10,555', '1.234', '1.2.3', '10,-'] as $input) {
            self::assertNull(RefundAmount::normalize($input), $input);
        }
    }
}
