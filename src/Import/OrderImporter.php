<?php

declare(strict_types=1);

namespace Everysize\Checkout\Import;

use Everysize\Checkout\Api\Amount;
use Everysize\Checkout\Api\Client;
use Everysize\Checkout\Module;
use Everysize\Checkout\OrderLinks;
use Everysize\Checkout\Settings;
use OxidEsales\Eshop\Application\Model\Article;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\OrderArticle;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Application\Model\VatSelector;
use OxidEsales\Eshop\Core\Counter;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;

/**
 * Creates an OXID order from an everysize order.
 *
 * The order is written directly with the prices the customer paid at
 * everysize — not through OXID's basket, which would recalculate with the
 * shop's own prices. Customer, order, items, stock and the everysize link are
 * written in one transaction: a failure leaves nothing behind, no orphaned
 * customer per failed attempt.
 */
class OrderImporter
{
    private const ORDER_FOLDER = 'ORDERFOLDER_NEW';
    private const CUSTOMER_GROUP = 'oxidcustomer';

    private Settings $settings;
    private OrderLinks $links;

    public function __construct(Settings $settings, OrderLinks $links)
    {
        $this->settings = $settings;
        $this->links = $links;
    }

    /**
     * @param array<string, mixed> $esOrder order as returned by the everysize API
     *
     * @return array{orderId: string, orderNumber: string, warnings: string[]}
     */
    public function import(array $esOrder): array
    {
        $everysizeOrderId = $this->requireString($esOrder, 'everysizeOrderId');
        $customer = \is_array($esOrder['customer'] ?? null) ? $esOrder['customer'] : [];
        $email = $this->requireString($customer, 'email', 'customer.email');
        // Information only: the OXID customer keeps the forwarding address above.
        $originalEmail = filter_var(trim((string) ($customer['originalEmail'] ?? '')), \FILTER_VALIDATE_EMAIL) ?: '';
        $billing = $this->address($esOrder['billingAddress'] ?? null, 'billingAddress');
        $shipping = \is_array($esOrder['shippingAddress'] ?? null) && $esOrder['shippingAddress'] !== []
            ? $this->address($esOrder['shippingAddress'], 'shippingAddress')
            : $billing;
        $revoked = strtoupper((string) ($esOrder['status'] ?? '')) === Client::STATUS_REVOKED;
        $warnings = [];

        $lines = $this->resolveLines($esOrder['lineItems'] ?? null);
        $prices = PriceCalculator::calculate(
            array_map(static function (array $line): array {
                return ['gross' => $line['gross'], 'quantity' => $line['quantity'], 'vat' => $line['vat']];
            }, $lines),
            Amount::parse($esOrder['shippingCosts'] ?? 0, 'shippingCosts')
        );
        if (isset($esOrder['grossPrice'])) {
            $everysizeTotal = Amount::parse($esOrder['grossPrice'], 'grossPrice');
            if (abs($everysizeTotal - $prices['totalOrderSum']) > 0.009) {
                $warnings[] = sprintf(
                    '%s: total %.2f differs from everysize grossPrice %.2f',
                    $everysizeOrderId,
                    $prices['totalOrderSum'],
                    $everysizeTotal
                );
            }
        }

        $payment = \is_array($esOrder['payment'] ?? null) ? $esOrder['payment'] : [];
        $payPalCaptureId = (string) ($payment['resourceId'] ?? $payment['transactionId'] ?? '');
        $orderDate = $this->localDate($esOrder['created'] ?? null);
        $paidDate = isset($esOrder['processed']) ? $this->localDate($esOrder['processed']) : $orderDate;

        $db = DatabaseProvider::getDb();
        $db->startTransaction();
        try {
            $user = $this->findOrCreateUser($email, $billing);

            $order = oxNew(Order::class);
            $orderId = Registry::getUtilsObject()->generateUId();
            $order->setId($orderId);
            $order->assign(array_merge(
                $this->billingFields($email, $billing),
                $this->deliveryFields($billing, $shipping),
                $this->priceFields($prices),
                [
                    'oxuserid' => $user->getId(),
                    'oxorderdate' => $orderDate,
                    'oxpaymenttype' => Module::PAYMENT_ID,
                    'oxpaymentid' => '',
                    'oxcurrency' => strtoupper((string) ($esOrder['currency'] ?? 'EUR')),
                    'oxcurrate' => 1,
                    'oxfolder' => self::ORDER_FOLDER,
                    'oxtransid' => $payPalCaptureId,
                    'oxpaid' => $paidDate,
                    'oxtransstatus' => 'OK',
                    'oxlang' => (int) Registry::getLang()->getBaseLanguage(),
                    'oxdeltype' => $this->settings->getDeliverySetId(),
                    'oxisnettomode' => 0,
                    'oxstorno' => $revoked ? 1 : 0,
                    'oxsenddate' => '0000-00-00 00:00:00',
                ]
            ));
            if (!$order->save()) {
                throw new ImportException(sprintf('%s: OXID order could not be saved', $everysizeOrderId));
            }

            $orderNumber = (string) oxNew(Counter::class)->getNext('oxOrder');
            $db->execute('UPDATE oxorder SET OXORDERNR = :nr WHERE OXID = :id', [':nr' => $orderNumber, ':id' => $orderId]);

            $allowNegativeStock = (bool) Registry::getConfig()->getConfigParam('blAllowNegativeStock');
            foreach ($lines as $key => $line) {
                $orderArticle = $this->createOrderArticle($orderId, $line, $prices['items'][$key], $revoked);
                if (!$revoked) {
                    $orderArticle->updateArticleStock(-$line['quantity'], $allowNegativeStock);
                }
                $this->links->addOrderItem($orderArticle->getId(), $orderId, $line['sku']);
            }

            $this->links->addOrder($orderId, $everysizeOrderId, $payPalCaptureId, $originalEmail);
            $db->commitTransaction();
        } catch (\Throwable $e) {
            $db->rollbackTransaction();
            throw $e;
        }

        return ['orderId' => $orderId, 'orderNumber' => $orderNumber, 'warnings' => $warnings];
    }

    /**
     * @return array<int, array{sku: string, title: string, quantity: int, gross: float, vat: float, article: Article}>
     */
    private function resolveLines($lineItems): array
    {
        if (!\is_array($lineItems) || $lineItems === []) {
            throw new ImportException('everysize order has no line items');
        }

        $lines = [];
        foreach (array_values($lineItems) as $item) {
            if (!\is_array($item)) {
                throw new ImportException('invalid line item in everysize order');
            }
            $sku = $this->requireString($item, 'sku', 'lineItems.sku');
            $article = $this->findArticle($sku);
            $lines[] = [
                'sku' => $sku,
                'title' => trim((string) ($item['title'] ?? '')),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'gross' => Amount::parse($item['price'] ?? null, 'lineItems.price'),
                'vat' => (float) Registry::get(VatSelector::class)->getArticleVat($article),
                'article' => $article,
            ];
        }

        return $lines;
    }

    /**
     * The SKU everysize sends is the article number (OXARTNUM) of the variant
     * the customer bought. OXARTNUM is not unique in OXID: an exact
     * (case-sensitive) match beats a case-insensitive one, an active article
     * an inactive one; two equally good candidates are an error.
     */
    private function findArticle(string $sku): Article
    {
        $ids = DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getAll(
            'SELECT OXID, BINARY OXARTNUM = BINARY :sku AS EXACT, OXACTIVE FROM oxarticles
             WHERE OXARTNUM = :sku AND OXSHOPID = :shopId ORDER BY EXACT DESC, OXACTIVE DESC',
            [':sku' => $sku, ':shopId' => Registry::getConfig()->getShopId()]
        );
        if ($ids === []) {
            throw new ImportException(sprintf('No article with article number %s', $sku));
        }
        if (\count($ids) > 1
            && (int) $ids[0]['EXACT'] === (int) $ids[1]['EXACT']
            && (int) $ids[0]['OXACTIVE'] === (int) $ids[1]['OXACTIVE']) {
            throw new ImportException(sprintf('Article number %s is not unique in the shop', $sku));
        }

        $article = oxNew(Article::class);
        if (!$article->load((string) $ids[0]['OXID'])) {
            throw new ImportException(sprintf('Article %s could not be loaded', $sku));
        }

        return $article;
    }

    /**
     * Customers are identified by the e-mail everysize sends — a forwarding
     * alias, stable per customer. OXID allows each username only once per
     * shop, so a returning customer gets the existing account (its stored
     * addresses stay untouched; the order carries its own addresses).
     *
     * @param array<string, string> $billing
     */
    private function findOrCreateUser(string $email, array $billing): User
    {
        $user = oxNew(User::class);
        $userId = $user->getIdByUserName($email);
        if ($userId && $user->load($userId)) {
            return $user;
        }

        $user->assign([
            'oxusername' => $email,
            'oxpassword' => '',
            'oxactive' => 1,
            'oxrights' => 'user',
            'oxshopid' => Registry::getConfig()->getShopId(),
            'oxfname' => $billing['firstName'],
            'oxlname' => $billing['lastName'],
            'oxstreet' => $billing['street'],
            'oxstreetnr' => $billing['streetNr'],
            'oxaddinfo' => $billing['addInfo'],
            'oxcity' => $billing['city'],
            'oxzip' => $billing['zip'],
            'oxcountryid' => $billing['countryId'],
        ]);
        if (!$user->save()) {
            throw new ImportException(sprintf('Customer %s could not be saved', $email));
        }
        $user->addToGroup(self::CUSTOMER_GROUP);

        return $user;
    }

    /**
     * @param array{sku: string, title: string, quantity: int, gross: float, vat: float, article: Article}             $line
     * @param array{unitGross: float, unitNet: float, totalGross: float, totalNet: float, totalVat: float, vat: float} $price
     */
    private function createOrderArticle(string $orderId, array $line, array $price, bool $revoked): OrderArticle
    {
        $article = $line['article'];
        $title = trim((string) $article->oxarticles__oxtitle->getRawValue());

        $orderArticle = oxNew(OrderArticle::class);
        $orderArticle->copyThis($article);
        $orderArticle->setId();
        $orderArticle->assign([
            'oxorderid' => $orderId,
            'oxartid' => $article->getId(),
            'oxartnum' => $article->oxarticles__oxartnum->getRawValue(),
            'oxtitle' => $title !== '' ? $title : $line['title'],
            'oxshortdesc' => $article->oxarticles__oxshortdesc->getRawValue(),
            'oxselvariant' => trim((string) $article->oxarticles__oxvarselect->getRawValue()),
            'oxamount' => $line['quantity'],
            'oxnetprice' => $price['totalNet'],
            'oxbrutprice' => $price['totalGross'],
            'oxvatprice' => $price['totalVat'],
            'oxvat' => $price['vat'],
            'oxprice' => $price['unitGross'],
            'oxbprice' => $price['unitGross'],
            'oxnprice' => $price['unitNet'],
            'oxordershopid' => Registry::getConfig()->getShopId(),
            'oxstorno' => $revoked ? 1 : 0,
            'oxisbundle' => 0,
        ]);
        if (!$orderArticle->save()) {
            throw new ImportException(sprintf('Order item %s could not be saved', $line['sku']));
        }

        return $orderArticle;
    }

    /**
     * @param array<string, string> $billing
     *
     * @return array<string, mixed>
     */
    private function billingFields(string $email, array $billing): array
    {
        return [
            'oxbillemail' => $email,
            'oxbillcompany' => '',
            'oxbillsal' => '',
            'oxbillfname' => $billing['firstName'],
            'oxbilllname' => $billing['lastName'],
            'oxbillstreet' => $billing['street'],
            'oxbillstreetnr' => $billing['streetNr'],
            'oxbilladdinfo' => $billing['addInfo'],
            'oxbillcity' => $billing['city'],
            'oxbillzip' => $billing['zip'],
            'oxbillcountryid' => $billing['countryId'],
        ];
    }

    /**
     * OXID shows "delivery address = billing address" when the delivery
     * fields are empty, so they are only filled for a different address.
     *
     * @param array<string, string> $billing
     * @param array<string, string> $shipping
     *
     * @return array<string, mixed>
     */
    private function deliveryFields(array $billing, array $shipping): array
    {
        if ($billing === $shipping) {
            return [];
        }

        return [
            'oxdelcompany' => '',
            'oxdelsal' => '',
            'oxdelfname' => $shipping['firstName'],
            'oxdellname' => $shipping['lastName'],
            'oxdelstreet' => $shipping['street'],
            'oxdelstreetnr' => $shipping['streetNr'],
            'oxdeladdinfo' => $shipping['addInfo'],
            'oxdelcity' => $shipping['city'],
            'oxdelzip' => $shipping['zip'],
            'oxdelcountryid' => $shipping['countryId'],
        ];
    }

    /**
     * @param array{totalGross: float, totalNet: float, vatGroups: array<string, float>, deliveryCost: float, deliveryVat: float, totalOrderSum: float} $prices
     *
     * @return array<string, mixed>
     */
    private function priceFields(array $prices): array
    {
        $fields = [
            'oxtotalnetsum' => $prices['totalNet'],
            'oxtotalbrutsum' => $prices['totalGross'],
            'oxtotalordersum' => $prices['totalOrderSum'],
            'oxdelcost' => $prices['deliveryCost'],
            'oxdelvat' => $prices['deliveryVat'],
            'oxpaycost' => 0,
            'oxpayvat' => 0,
            'oxwrapcost' => 0,
            'oxwrapvat' => 0,
            'oxgiftcardcost' => 0,
            'oxgiftcardvat' => 0,
            'oxdiscount' => 0,
            'oxvoucherdiscount' => 0,
        ];

        // oxorder has two VAT slots; an everysize order has one item, so one rate.
        $slot = 1;
        foreach ($prices['vatGroups'] as $rate => $amount) {
            if ($slot > 2) {
                throw new ImportException('everysize order has more than two VAT rates');
            }
            $fields['oxartvat'.$slot] = (float) $rate;
            $fields['oxartvatprice'.$slot] = $amount;
            ++$slot;
        }

        return $fields;
    }

    /**
     * @return array{firstName: string, lastName: string, street: string, streetNr: string, addInfo: string, zip: string, city: string, countryId: string}
     */
    private function address($address, string $field): array
    {
        if (!\is_array($address)) {
            throw new ImportException(sprintf('everysize order has no %s', $field));
        }

        [$street, $streetNr] = AddressSplitter::split($this->requireString($address, 'addressLine1', $field.'.addressLine1'));
        $countryCode = strtoupper($this->requireString($address, 'countryCode', $field.'.countryCode'));
        $countryId = DatabaseProvider::getDb()->getOne(
            'SELECT OXID FROM oxcountry WHERE OXISOALPHA2 = :iso',
            [':iso' => $countryCode]
        );
        if (!$countryId) {
            throw new ImportException(sprintf('Unknown country %s in %s', $countryCode, $field));
        }

        return [
            'firstName' => trim((string) ($address['firstName'] ?? '')),
            'lastName' => $this->requireString($address, 'lastName', $field.'.lastName'),
            'street' => $street,
            'streetNr' => $streetNr,
            'addInfo' => trim((string) ($address['addressLine2'] ?? '')),
            'zip' => $this->requireString($address, 'postalCode', $field.'.postalCode'),
            'city' => $this->requireString($address, 'city', $field.'.city'),
            'countryId' => (string) $countryId,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireString(array $data, string $key, ?string $label = null): string
    {
        $value = $data[$key] ?? null;
        if (!\is_scalar($value) || trim((string) $value) === '') {
            throw new ImportException(sprintf('everysize order has no %s', $label ?? $key));
        }

        return trim((string) $value);
    }

    /**
     * everysize sends ISO 8601 with offset; OXID stores local shop time.
     */
    private function localDate($value): string
    {
        $timestamp = \is_string($value) ? strtotime($value) : false;

        return date('Y-m-d H:i:s', $timestamp !== false ? $timestamp : time());
    }
}
