<?php

declare(strict_types=1);

namespace Everysize\Checkout\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * 1.1.0: automatic full refund for a cancellation before shipping is on by
 * default. "autoRefundSince" marks from when: only cancellations reported
 * after it are refunded, never older ones on an update.
 */
final class Version20261009190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'everysize checkout: automatic refund of cancellations before shipping, from now on';
    }

    public function up(Schema $schema): void
    {
        // PHP time, like every CREATED the module writes (the database may run in another time zone).
        $this->addSql(
            "INSERT IGNORE INTO everysize_checkout_settings (OXSHOPID, NAME, VALUE)
             SELECT OXID, 'autoRefundSince', ? FROM oxshops",
            [date('Y-m-d H:i:s')]
        );
    }

    public function down(Schema $schema): void
    {
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
