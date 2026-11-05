<?php

declare(strict_types=1);

namespace Everysize\Checkout\Controller\Admin;

use Everysize\Checkout\OrderLinks;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;

/**
 * Order list in the admin: a first column "everysize" with the everysize order
 * ID, a filter "only everysize / without everysize" and a search by everysize
 * order ID. The template part is
 * views/twig/extensions/themes/admin_twig/order_list.html.twig.
 *
 * Orders are recognised by their row in everysize_checkout_order, not by the
 * payment method, so a merchant who changes the payment of an imported order
 * still finds it. The table is joined, not queried in a subselect: OXID turns
 * the list query into the count query with a greedy "select .* from" replace,
 * which a second FROM breaks.
 */
class OrderList extends OrderList_parent
{
    public const FILTER_PARAM = 'everysize_filter';
    public const SEARCH_PARAM = 'everysize_search';

    public const FILTER_ONLY = '1';
    public const FILTER_WITHOUT = '0';

    public function render()
    {
        $template = parent::render();

        $orderIds = [];
        foreach ($this->getItemList() ?? [] as $order) {
            $orderIds[] = (string) $order->getId();
        }
        $links = ContainerFactory::getInstance()->getContainer()->get(OrderLinks::class);

        $this->_aViewData['everysizeOrderIds'] = $links->getEverysizeOrderIds($orderIds);
        $this->_aViewData['everysizeRevokedNotes'] = $links->getRevokedNotes($orderIds);
        $this->_aViewData['everysizeFilter'] = self::filter();
        $this->_aViewData['everysizeSearch'] = self::search();

        return $template;
    }

    protected function buildSelectString($listObject = null)
    {
        $query = parent::buildSelectString($listObject);
        if (self::filter() === '' && self::search() === '') {
            return $query;
        }

        // The shop's own search may already have joined a table to "oxorder" (OrderList::buildSelectString).
        $position = stripos($query, ' from oxorder ');
        if ($position === false) {
            return $query;
        }
        $from = ' from oxorder ';

        return substr_replace($query, $from.'left join everysize_checkout_order esco on esco.OXID = oxorder.oxid ', $position, \strlen($from));
    }

    protected function prepareWhereQuery($whereQuery, $fullQuery)
    {
        $query = parent::prepareWhereQuery($whereQuery, $fullQuery);
        if (stripos($fullQuery, 'everysize_checkout_order esco') === false) {
            return $query;
        }

        if (self::filter() === self::FILTER_ONLY) {
            $query .= ' and esco.OXID is not null';
        } elseif (self::filter() === self::FILTER_WITHOUT) {
            $query .= ' and esco.OXID is null';
        }

        $search = self::search();
        if ($search !== '') {
            $query .= ' and esco.EVERYSIZE_ORDER_ID like '.DatabaseProvider::getDb()->quote('%'.$search.'%');
        }

        return $query;
    }

    private static function filter(): string
    {
        $filter = (string) Registry::getRequest()->getRequestEscapedParameter(self::FILTER_PARAM);

        return \in_array($filter, [self::FILTER_ONLY, self::FILTER_WITHOUT], true) ? $filter : '';
    }

    private static function search(): string
    {
        return trim((string) Registry::getRequest()->getRequestEscapedParameter(self::SEARCH_PARAM));
    }
}
