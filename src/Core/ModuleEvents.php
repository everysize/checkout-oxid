<?php

declare(strict_types=1);

namespace Everysize\Checkout\Core;

use Everysize\Checkout\Module;
use OxidEsales\DoctrineMigrationWrapper\MigrationsBuilder;
use OxidEsales\Eshop\Core\DatabaseProvider;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Activation: create the module tables (Doctrine migrations of the module,
 * the same ones `vendor/bin/oe-eshop-db_migrate migrations:migrate` runs) and
 * the payment method of imported orders. Deactivation keeps all data.
 */
final class ModuleEvents
{
    public static function onActivate(): void
    {
        self::runMigrations();
        self::createPaymentMethod();
    }

    public static function onDeactivate(): void
    {
    }

    private static function runMigrations(): void
    {
        $migrations = (new MigrationsBuilder())->build();
        $migrations->setOutput(new BufferedOutput());
        $migrations->execute('migrations:migrate', Module::ID);
    }

    /**
     * Imported orders carry their own payment method "everysize (PayPal)" —
     * the customer paid at everysize. It is not assigned to any shipping
     * method, so the storefront never offers it.
     */
    private static function createPaymentMethod(): void
    {
        // Plain SQL with every column: the Payment model writes unset
        // multi-language fields as NULL (PHP deprecation on OXID 7.0).
        $title = 'everysize (PayPal)';
        DatabaseProvider::getDb()->execute(
            "INSERT IGNORE INTO oxpayments
                (OXID, OXACTIVE, OXDESC, OXDESC_1, OXDESC_2, OXDESC_3, OXADDSUM, OXADDSUMTYPE, OXADDSUMRULES,
                 OXFROMBONI, OXFROMAMOUNT, OXTOAMOUNT, OXVALDESC, OXVALDESC_1, OXVALDESC_2, OXVALDESC_3,
                 OXCHECKED, OXLONGDESC, OXLONGDESC_1, OXLONGDESC_2, OXLONGDESC_3, OXSORT)
             VALUES (:id, 1, :title, :title, :title, :title, 0, 'abs', 0,
                 0, 0, 1000000, '', '', '', '',
                 0, '', '', '', '', 9999)",
            [':id' => Module::PAYMENT_ID, ':title' => $title]
        );
    }
}
