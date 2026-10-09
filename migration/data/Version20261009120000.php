<?php

declare(strict_types=1);

namespace Everysize\Checkout\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tables of the everysize checkout module (1.0.0).
 *
 * No foreign keys to oxorder/oxorderarticles: OXID deletes orders with its own
 * code, and a cascade must never remove the "already reported" markers of an
 * order that still exists.
 */
final class Version20261009120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'everysize checkout: settings, order links, reports, protocol';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `everysize_checkout_settings` (
                `OXSHOPID` INT(11) NOT NULL,
                `NAME` VARCHAR(64) NOT NULL,
                `VALUE` TEXT NOT NULL,
                `OXTIMESTAMP` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`OXSHOPID`, `NAME`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='everysize checkout: module settings incl. API credentials'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `everysize_checkout_order` (
                `OXID` CHAR(32) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL COMMENT 'oxorder.OXID',
                `OXSHOPID` INT(11) NOT NULL,
                `EVERYSIZE_ORDER_ID` VARCHAR(32) NOT NULL,
                `PAYPAL_CAPTURE_ID` VARCHAR(64) NOT NULL DEFAULT '',
                `NUMBER_REPORTED` DATETIME NULL DEFAULT NULL,
                `CREATED` DATETIME NOT NULL,
                PRIMARY KEY (`OXID`),
                UNIQUE KEY `EVERYSIZE_ORDER` (`OXSHOPID`, `EVERYSIZE_ORDER_ID`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='everysize checkout: imported orders'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `everysize_checkout_order_item` (
                `OXID` CHAR(32) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL COMMENT 'oxorderarticles.OXID',
                `ORDERID` CHAR(32) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL COMMENT 'oxorder.OXID',
                `SKU` VARCHAR(255) NOT NULL COMMENT 'SKU exactly as sent by everysize',
                PRIMARY KEY (`OXID`),
                KEY `ORDERID` (`ORDERID`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='everysize checkout: imported order items'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `everysize_checkout_report` (
                `ID` INT(11) NOT NULL AUTO_INCREMENT,
                `ORDERID` CHAR(32) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL COMMENT 'oxorder.OXID',
                `KIND` VARCHAR(16) NOT NULL COMMENT 'fulfillment | revocation',
                `SKU` VARCHAR(255) NOT NULL DEFAULT '',
                `REASON` VARCHAR(32) NOT NULL DEFAULT '',
                `RESULT` VARCHAR(16) NOT NULL COMMENT 'sent | skipped',
                `CREATED` DATETIME NOT NULL,
                PRIMARY KEY (`ID`),
                UNIQUE KEY `ONCE` (`ORDERID`, `KIND`, `SKU`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='everysize checkout: status reported to everysize, exactly once'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `everysize_checkout_protocol` (
                `ID` INT(11) NOT NULL AUTO_INCREMENT,
                `OXSHOPID` INT(11) NOT NULL,
                `CREATED` DATETIME NOT NULL,
                `TITLE` VARCHAR(255) NOT NULL,
                `MESSAGE` TEXT NOT NULL,
                `STATE` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = success, 0 = error',
                PRIMARY KEY (`ID`),
                KEY `SHOP_CREATED` (`OXSHOPID`, `CREATED`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='everysize checkout: sync protocol'
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
