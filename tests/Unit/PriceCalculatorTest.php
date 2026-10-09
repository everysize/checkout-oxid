<?php

declare(strict_types=1);

namespace Everysize\Checkout\Tests\Unit;

use Everysize\Checkout\Import\PriceCalculator;
use PHPUnit\Framework\TestCase;

final class PriceCalculatorTest extends TestCase
{
    public function testTypicalEverysizeOrder(): void
    {
        $result = PriceCalculator::calculate([['gross' => 119.99, 'quantity' => 1, 'vat' => 19.0]], 4.90);

        self::assertSame(119.99, $result['items'][0]['totalGross']);
        self::assertSame(100.83, $result['items'][0]['totalNet']);
        self::assertSame(19.16, $result['items'][0]['totalVat']);
        self::assertSame(100.83, $result['items'][0]['unitNet']);
        self::assertSame(119.99, $result['totalGross']);
        self::assertSame(100.83, $result['totalNet']);
        self::assertSame(['19' => 19.16], $result['vatGroups']);
        self::assertSame(4.9, $result['deliveryCost']);
        self::assertSame(19.0, $result['deliveryVat']);
        self::assertSame(124.89, $result['totalOrderSum']);
    }

    public function testAboveThousand(): void
    {
        $result = PriceCalculator::calculate([['gross' => 1234.56, 'quantity' => 1, 'vat' => 19.0]], 4.90);

        self::assertSame(1037.45, $result['totalNet']);
        self::assertSame(1239.46, $result['totalOrderSum']);
    }

    public function testQuantityAndTwoRates(): void
    {
        $result = PriceCalculator::calculate([
            ['gross' => 10.00, 'quantity' => 3, 'vat' => 19.0],
            ['gross' => 5.35, 'quantity' => 1, 'vat' => 7.0],
        ], 0.0);

        self::assertSame(30.0, $result['items'][0]['totalGross']);
        self::assertSame(25.21, $result['items'][0]['totalNet']);
        self::assertSame(5.0, $result['items'][1]['totalNet']);
        self::assertSame(['19' => 4.79, '7' => 0.35], $result['vatGroups']);
        self::assertSame(19.0, $result['deliveryVat']);
        self::assertSame(35.35, $result['totalOrderSum']);
    }
}
