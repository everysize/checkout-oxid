<?php

declare(strict_types=1);

namespace Everysize\Checkout\Import;

use Everysize\Checkout\Api\Client;

/**
 * The import report: what the shop had for an ordered position at import
 * time. Only informs — a failed read or send never touches the order.
 */
final class ImportReport
{
    /** OXSTOCKFLAG 2 = if out of stock, offline; 3 = if out of stock, not orderable. */
    private const LIMITED_STOCK_FLAGS = [2, 3];

    /**
     * OXSTOCKFLAG 1 = standard and 4 = external storehouse stay orderable
     * (Article::checkForStock), except with basket reservations on and
     * negative stock off: then OXID limits them by stock as well.
     */
    private const OVERSELL_STOCK_FLAGS = [1, 4];

    /** The currency the checkout compares prices in. */
    private const CURRENCY = 'EUR';

    /**
     * Cancelled orders are not reported.
     */
    public static function isReportable(string $status): bool
    {
        return \in_array(strtoupper($status), [Client::STATUS_PROCESSING, Client::STATUS_COMPLETED], true);
    }

    /**
     * Stock before the import, null if the shop does not limit sales by it.
     *
     * @param bool $basketReservation blPsBasketReservationEnabled
     * @param bool $allowNegativeStock blAllowNegativeStock
     */
    public static function stock(int $stockFlag, float $stock, bool $basketReservation = false, bool $allowNegativeStock = false): ?int
    {
        $limited = \in_array($stockFlag, self::LIMITED_STOCK_FLAGS, true)
            || (\in_array($stockFlag, self::OVERSELL_STOCK_FLAGS, true) && $basketReservation && !$allowNegativeStock);

        return $limited ? (int) floor($stock) : null;
    }

    /**
     * Whether Article::getPrice()->getBruttoPrice() is the gross price in
     * euro. It is not if the shop shows net prices and calculates VAT only in
     * the basket (bl_perfCalcVatOnlyForBasketOrder: the "gross" price is then
     * the net price), nor if the shop's default currency is not euro. Then
     * nothing is reported rather than a wrong price.
     *
     * @param string|null $defaultCurrency name of the shop's default currency, null = unknown
     */
    public static function priceIsGrossEuro(bool $showNetPrice, bool $calcVatOnlyForBasket, ?string $defaultCurrency): bool
    {
        if ($showNetPrice && $calcVatOnlyForBasket) {
            return false;
        }

        return $defaultCurrency !== null && strtoupper(trim($defaultCurrency)) === self::CURRENCY;
    }

    /**
     * @return array{sku: string, shopPrice: ?string, stock: ?int}
     */
    public static function entry(string $sku, ?float $shopPrice, ?int $stock): array
    {
        return [
            'sku' => $sku,
            'shopPrice' => $shopPrice !== null && is_finite($shopPrice) ? number_format($shopPrice, 2, '.', '') : null,
            'stock' => $stock,
        ];
    }

    /**
     * @param array{sku: string, shopPrice: ?string, stock: ?int} $entry
     *
     * @return string[]
     */
    public static function warnings(string $everysizeOrderId, array $entry, float $paidGross, int $quantity): array
    {
        $warnings = [];
        if ($entry['shopPrice'] !== null && abs((float) $entry['shopPrice'] - $paidGross) > 0.009) {
            $warnings[] = sprintf(
                '%s: %s: shop price %s differs from the price paid at everysize %.2f',
                $everysizeOrderId,
                $entry['sku'],
                $entry['shopPrice'],
                $paidGross
            );
        }
        if ($entry['stock'] !== null && $entry['stock'] < $quantity) {
            $warnings[] = sprintf(
                '%s: %s: stock %d is below the ordered quantity %d (oversold)',
                $everysizeOrderId,
                $entry['sku'],
                $entry['stock'],
                $quantity
            );
        }

        return $warnings;
    }

    /**
     * Sends one report per position, once. Any failure (also a 404 while the
     * checkout does not know the route yet) becomes a warning text.
     *
     * @param array<int, array{sku: string, shopPrice: ?string, stock: ?int}> $entries
     *
     * @return string[] warnings
     */
    public static function send(Client $client, string $everysizeOrderId, array $entries): array
    {
        $warnings = [];
        foreach ($entries as $entry) {
            try {
                $client->sendImportReport($everysizeOrderId, $entry['sku'], $entry['shopPrice'], $entry['stock']);
            } catch (\Throwable $e) {
                $warnings[] = sprintf('%s: %s: import report not sent: %s', $everysizeOrderId, $entry['sku'], $e->getMessage());
            }
        }

        return $warnings;
    }
}
