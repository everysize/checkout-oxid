<?php

declare(strict_types=1);

namespace Everysize\Checkout\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * 1.3.0: the refund whose outcome is unknown gets a table of its own, one row per order (the primary key makes
 * reserving it atomic): idempotency key, amount ("" = the rest), creation time, time of the last hand-over, the
 * PayPal refund IDs known when it was made, and the refund ID of the answer. An earlier draft kept it in the
 * report table (kind refund-open with the key in SKU, plus a refund-lock row): those rows are moved here.
 */
final class Version20261104100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'everysize checkout: open refund (outcome unknown) in its own table, one row per order';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `everysize_checkout_open_refund` (
                `ORDERID` CHAR(32) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL COMMENT 'oxorder.OXID',
                `IDEM_KEY` VARCHAR(64) NOT NULL,
                `AMOUNT` VARCHAR(16) NOT NULL DEFAULT '',
                `CREATED` DATETIME NOT NULL,
                `SENT_AT` DATETIME NULL DEFAULT NULL,
                `SEND_TOKEN` VARCHAR(32) NOT NULL DEFAULT '',
                `KNOWN_IDS` TEXT NULL,
                `RESULT_ID` VARCHAR(64) NOT NULL DEFAULT '',
                PRIMARY KEY (`ORDERID`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='everysize checkout: refund with an unknown outcome'
            SQL);

        // Rows of the earlier draft (the oldest entry of an order wins, the lock rows go).
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO `everysize_checkout_open_refund` (ORDERID, IDEM_KEY, AMOUNT, CREATED, SENT_AT)
                SELECT ORDERID, SKU, REASON, CREATED, CREATED FROM `everysize_checkout_report`
                WHERE KIND = 'refund-open' ORDER BY ID
            SQL);
        $this->addSql("DELETE FROM `everysize_checkout_report` WHERE KIND IN ('refund-open', 'refund-lock')");
    }

    public function down(Schema $schema): void
    {
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
