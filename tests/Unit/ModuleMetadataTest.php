<?php

declare(strict_types=1);

namespace Everysize\Checkout\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ModuleMetadataTest extends TestCase
{
    public function testTheOrderModelIsExtendedAndTheExtensionFileExists(): void
    {
        $sMetadataVersion = null;
        $aModule = [];
        require __DIR__.'/../../metadata.php';

        self::assertSame(
            'Everysize\\Checkout\\Model\\Order',
            $aModule['extend']['OxidEsales\\Eshop\\Application\\Model\\Order'] ?? null,
            'recalculateOrder() resets the discount fields: the extension records the discount first'
        );
        self::assertFileExists(__DIR__.'/../../src/Model/Order.php');
        $source = (string) file_get_contents(__DIR__.'/../../src/Model/Order.php');
        self::assertStringContainsString('class Order extends Order_parent', $source);
        self::assertStringContainsString('recordDiscount();', $source);
        self::assertLessThan(strpos($source, 'parent::recalculateOrder'), strpos($source, 'recordDiscount();'), 'recorded BEFORE the parent recalculates');
        self::assertStringContainsString('INSERT IGNORE INTO everysize_checkout_report', $source);
    }
}
