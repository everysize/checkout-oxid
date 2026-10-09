<?php

declare(strict_types=1);

namespace Everysize\Checkout\Tests\Unit;

use Everysize\Checkout\Import\AddressSplitter;
use PHPUnit\Framework\TestCase;

final class AddressSplitterTest extends TestCase
{
    /**
     * @dataProvider addresses
     */
    public function testSplitsStreetAndNumber(string $line, string $street, string $number): void
    {
        self::assertSame([$street, $number], AddressSplitter::split($line));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public function addresses(): array
    {
        return [
            'simple' => ['Musterstraße 12', 'Musterstraße', '12'],
            'letter' => ['Musterstraße 12a', 'Musterstraße', '12a'],
            'letter with space' => ['Am Markt 3 b', 'Am Markt', '3 b'],
            'range' => ['Hauptstr. 5-7', 'Hauptstr.', '5-7'],
            'slash' => ['Weg 12/1', 'Weg', '12/1'],
            'number in street name' => ['Straße des 17. Juni 112', 'Straße des 17. Juni', '112'],
            'extra spaces' => ['  Musterweg   4 ', 'Musterweg', '4'],
            'no number' => ['Packstation', 'Packstation', ''],
            'number first stays whole' => ['12 Rue de la Paix', '12 Rue de la Paix', ''],
            'only number stays whole' => ['12', '12', ''],
        ];
    }
}
