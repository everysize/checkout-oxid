<?php

declare(strict_types=1);

namespace Everysize\Checkout\Tests\Unit;

use Everysize\Checkout\Api\Amount;
use Everysize\Checkout\Api\ApiException;
use PHPUnit\Framework\TestCase;

final class AmountTest extends TestCase
{
    /**
     * @dataProvider validAmounts
     */
    public function testParsesEverysizeAmounts($input, float $expected): void
    {
        self::assertSame($expected, Amount::parse($input));
    }

    /**
     * @return array<string, array{0: mixed, 1: float}>
     */
    public function validAmounts(): array
    {
        return [
            'plain' => ['119.99', 119.99],
            'thousands separator' => ['1,234.56', 1234.56],
            'million' => ['1,234,567.89', 1234567.89],
            'integer string' => ['5', 5.0],
            'zero' => ['0.00', 0.0],
            'surrounding spaces' => [' 4.90 ', 4.9],
            'int' => [490, 490.0],
            'float' => [4.9, 4.9],
        ];
    }

    /**
     * @dataProvider invalidAmounts
     */
    public function testRejectsAnythingElse($input): void
    {
        $this->expectException(ApiException::class);
        Amount::parse($input);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public function invalidAmounts(): array
    {
        return [
            'decimal comma' => ['119,99'],
            'german thousands' => ['1.234,56'],
            'broken group' => ['1,23.45'],
            'empty' => [''],
            'text' => ['abc'],
            'null' => [null],
            'array' => [[]],
        ];
    }
}
