<?php

declare(strict_types=1);

namespace Everysize\Checkout\Model;

use Everysize\Checkout\OrderLinks;
use Everysize\Checkout\Protocol;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;

/**
 * Extension of the order model with one job: a discount or voucher the merchant gave an everysize order must
 * keep the automatic refund away for good.
 *
 * Cancelling a position in the admin (OrderArticle::storno) calls recalculateOrder(), and the recalculation
 * (Order::loadFromBasket) writes OXDISCOUNT and OXVOUCHERDISCOUNT from a basket that knows neither, so both
 * fields are 0 afterwards. The automatic refund looks at exactly these fields: it would pay out the whole
 * capture. So, before the recalculation destroys the evidence, a discount on a linked order is recorded as
 * a refund report DISCOUNT (skipped) - the query for the automatic refund excludes every order with a refund
 * report, permanently.
 */
class Order extends Order_parent
{
    public function recalculateOrder($aNewArticles = [])
    {
        $this->recordDiscount();

        return parent::recalculateOrder($aNewArticles);
    }

    /** Never throws: an error here must not break the recalculation. */
    private function recordDiscount(): void
    {
        try {
            $db = DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC);
            $orderId = (string) $this->getId();
            $row = $db->getRow(
                'SELECT o.OXDISCOUNT, o.OXVOUCHERDISCOUNT FROM oxorder o JOIN everysize_checkout_order l ON l.OXID = o.OXID WHERE o.OXID = :id',
                [':id' => $orderId]
            );
            if (!$row || ((float) $row['OXDISCOUNT'] === 0.0 && (float) $row['OXVOUCHERDISCOUNT'] === 0.0)) {
                return;
            }

            $created = $db->execute(
                'INSERT IGNORE INTO everysize_checkout_report (ORDERID, KIND, SKU, REASON, RESULT, CREATED)
                 VALUES (:id, :kind, :sku, :reason, :result, :now)',
                [
                    ':id' => $orderId,
                    ':kind' => OrderLinks::KIND_REFUND,
                    ':sku' => OrderLinks::AUTO_REFUND,
                    ':reason' => 'DISCOUNT',
                    ':result' => OrderLinks::RESULT_SKIPPED,
                    ':now' => date('Y-m-d H:i:s'),
                ]
            );
            if ($created) {
                ContainerFactory::getInstance()->getContainer()->get(Protocol::class)->add(
                    'No automatic refund: discount on the order',
                    sprintf(
                        'Order %s has a discount or voucher (OXDISCOUNT %s, OXVOUCHERDISCOUNT %s) and the order is being recalculated (a position was cancelled). The recalculation resets both fields, so the automatic refund is switched off for this order for good - please refund in the order tab "everysize" if needed.',
                        $orderId,
                        $row['OXDISCOUNT'],
                        $row['OXVOUCHERDISCOUNT']
                    ),
                    false
                );
            }
        } catch (\Throwable $e) {
            // Best effort: the recalculation itself goes on.
        }
    }
}
