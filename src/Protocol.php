<?php

declare(strict_types=1);

namespace Everysize\Checkout;

use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;

/**
 * Protocol of sync runs, shown in the admin. Only runs that moved something
 * or failed are recorded — with a run every 5 minutes, "nothing to do" entries
 * would bury the relevant ones.
 */
class Protocol
{
    public const RETENTION_DAYS = 31;

    /** STATE of an entry: a hint or a warning, not an error (TINYINT(1) takes it without a migration). */
    public const STATE_WARNING = 2;

    public function add(string $title, string $message, bool $success): void
    {
        $this->write($title, $message, $success ? 1 : 0);
    }

    /**
     * A warning: shown yellow, never counted as an error of the run.
     */
    public function warn(string $title, string $message = ''): void
    {
        $this->write($title, $message, self::STATE_WARNING);
    }

    private function write(string $title, string $message, int $state): void
    {
        DatabaseProvider::getDb()->execute(
            'INSERT INTO everysize_checkout_protocol (OXSHOPID, CREATED, TITLE, MESSAGE, STATE)
             VALUES (:shopId, :created, :title, :message, :state)',
            [
                ':shopId' => $this->shopId(),
                ':created' => date('Y-m-d H:i:s'),
                ':title' => mb_substr($title, 0, 255),
                ':message' => $message,
                ':state' => $state,
            ]
        );
    }

    /**
     * @param bool|null $success null = all entries, true = only successful, false = only errors
     *
     * @return array<int, array{CREATED: string, TITLE: string, MESSAGE: string, STATE: string}>
     */
    public function getEntries(int $offset = 0, int $limit = 50, ?bool $success = null): array
    {
        [$where, $params] = $this->filter($success);

        return DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getAll(
            sprintf(
                'SELECT CREATED, TITLE, MESSAGE, STATE FROM everysize_checkout_protocol WHERE %s ORDER BY ID DESC LIMIT %d, %d',
                $where,
                max(0, $offset),
                max(1, $limit)
            ),
            $params
        );
    }

    public function count(?bool $success = null): int
    {
        [$where, $params] = $this->filter($success);

        return (int) DatabaseProvider::getDb()->getOne(
            'SELECT COUNT(*) FROM everysize_checkout_protocol WHERE '.$where,
            $params
        );
    }

    public function cleanup(int $days = self::RETENTION_DAYS): void
    {
        DatabaseProvider::getDb()->execute(
            'DELETE FROM everysize_checkout_protocol WHERE OXSHOPID = :shopId AND CREATED < :before LIMIT 1000',
            [':shopId' => $this->shopId(), ':before' => date('Y-m-d H:i:s', strtotime(sprintf('-%d days', $days)))]
        );
    }

    /**
     * @return array{0: string, 1: array<string, int>}
     */
    private function filter(?bool $success): array
    {
        $where = 'OXSHOPID = :shopId';
        $params = [':shopId' => $this->shopId()];
        if ($success !== null) {
            $where .= ' AND STATE = :state';
            $params[':state'] = $success ? 1 : 0;
        }

        return [$where, $params];
    }

    private function shopId(): int
    {
        return (int) Registry::getConfig()->getShopId();
    }
}
