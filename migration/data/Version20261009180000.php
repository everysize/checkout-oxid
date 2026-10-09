<?php

declare(strict_types=1);

namespace Everysize\Checkout\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * 1.1.0: the customer's original e-mail address (customer.originalEmail of
 * the everysize order), shown in the "everysize" tab for information only.
 * The OXID customer keeps the everysize forwarding address, so shop mails
 * still go through everysize.
 */
final class Version20261009180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'everysize checkout: original customer e-mail on imported orders';
    }

    public function up(Schema $schema): void
    {
        // Not through $schema: introspecting it reads every shop table, and Doctrine knows no ENUM
        // ("Unknown database type enum requested").
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'everysize_checkout_order' AND COLUMN_NAME = 'ORIGINAL_EMAIL'"
        );
        if ($exists > 0) {
            return;
        }

        $this->addSql(<<<'SQL'
            ALTER TABLE `everysize_checkout_order`
                ADD COLUMN `ORIGINAL_EMAIL` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'customer original e-mail, information only' AFTER `PAYPAL_CAPTURE_ID`
            SQL);
    }

    public function down(Schema $schema): void
    {
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
