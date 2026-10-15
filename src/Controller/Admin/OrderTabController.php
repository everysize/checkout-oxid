<?php

declare(strict_types=1);

namespace Everysize\Checkout\Controller\Admin;

use Everysize\Checkout\Api\ApiException;
use Everysize\Checkout\Api\Client;
use Everysize\Checkout\Display;
use Everysize\Checkout\OrderLinks;
use Everysize\Checkout\RefundAmount;
use Everysize\Checkout\Settings;
use OxidEsales\Eshop\Application\Controller\Admin\AdminDetailsController;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;

/**
 * Tab "everysize" in the order admin: everysize order ID, PayPal capture ID,
 * the customer's original e-mail (information only), what was reported back,
 * and refunds through everysize.
 *
 * A refund goes to everysize (External API), which refunds the PayPal capture
 * on the merchant's behalf — no PayPal credentials in the shop. Every form
 * carries a fresh idempotency key: a double click or a resent form refunds
 * once.
 */
class OrderTabController extends AdminDetailsController
{
    private const REFUNDED_STATES = ['COMPLETED', 'PENDING'];

    protected $_sThisTemplate = '@everysize_checkout/admin/everysize_checkout_order';

    /** @var array{type: string, text: string}|null */
    private ?array $message = null;

    public function render()
    {
        parent::render();

        $orderId = (string) $this->getEditObjectId();
        $links = $this->service(OrderLinks::class);
        $link = $orderId !== '' && $orderId !== '-1' ? $links->getLink($orderId) : null;

        $this->addTplParam('oxid', $orderId);
        $this->addTplParam('everysizeLink', $link);
        $this->addTplParam('everysizeMessage', $this->message);
        if ($link !== null) {
            $this->addTplParam('everysizeImported', $this->dateTime($link['CREATED']));
            $this->addTplParam('everysizeNumberReported', $link['NUMBER_REPORTED'] !== null ? $this->dateTime($link['NUMBER_REPORTED']) : '');
            $this->addTplParam('everysizeReports', $this->reportRows($links->getReports($orderId)));
        }

        if ($link !== null && $link['PAYPAL_CAPTURE_ID'] !== '') {
            $this->addRefundParams($orderId, $link['EVERYSIZE_ORDER_ID']);
        }

        return $this->_sThisTemplate;
    }

    public function refund(): void
    {
        if (!Registry::getSession()->checkSessionChallenge()) {
            $this->error('EVERYSIZE_CHECKOUT_ERROR_SESSION');

            return;
        }

        $orderId = (string) $this->getEditObjectId();
        $links = $this->service(OrderLinks::class);
        $link = $links->getLink($orderId);
        if ($link === null || $link['PAYPAL_CAPTURE_ID'] === '') {
            $this->error('EVERYSIZE_CHECKOUT_REFUND_NOT_POSSIBLE');

            return;
        }

        $request = Registry::getRequest();
        $amount = RefundAmount::normalize((string) $request->getRequestParameter('everysize_refund_amount'));
        $key = (string) $request->getRequestParameter('everysize_refund_key');
        if ($amount === null) {
            $this->error('EVERYSIZE_CHECKOUT_REFUND_ERROR_AMOUNT');

            return;
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $key)) {
            $this->error('EVERYSIZE_CHECKOUT_ERROR_SESSION');

            return;
        }

        try {
            $refund = $this->service(Client::class)->refund($link['EVERYSIZE_ORDER_ID'], $amount, $key);
        } catch (ApiException $e) {
            $this->message = ['type' => 'error', 'text' => $this->refundError($e)];

            return;
        }

        // everysize answers a capture that is refunded completely already with 201 and this status, no refund ID.
        if (strtoupper((string) ($refund['status'] ?? '')) === 'ALREADY_REFUNDED') {
            $this->error('EVERYSIZE_CHECKOUT_REFUND_ALREADY');

            return;
        }

        $refundId = (string) ($refund['refundId'] ?? '');
        $reference = $refundId !== '' ? $refundId : $key;
        // The same idempotency key again (form sent twice) returns the same refund: record and announce it once.
        if (\in_array($reference, array_column($links->getReports($orderId), 'SKU'), true)) {
            $this->message = ['type' => 'ok', 'text' => $this->translate('EVERYSIZE_CHECKOUT_REFUND_DUPLICATE')];

            return;
        }
        $links->addReport($orderId, OrderLinks::KIND_REFUND, $reference, $amount, OrderLinks::RESULT_SENT);
        $this->message = ['type' => 'ok', 'text' => sprintf(
            $this->translate('EVERYSIZE_CHECKOUT_REFUND_OK'),
            $this->formatAmount((float) $amount),
            (string) ($refund['status'] ?? '')
        )];
    }

    private function addRefundParams(string $orderId, string $everysizeOrderId): void
    {
        $order = oxNew(Order::class);
        $paid = $order->load($orderId) ? (float) $order->getTotalOrderSum() : 0.0;

        $refunds = [];
        $refunded = 0.0;
        $refundError = '';
        try {
            foreach ($this->service(Client::class)->getRefunds($everysizeOrderId) as $refund) {
                $amount = (float) str_replace(',', '', (string) ($refund['amount'] ?? '0'));
                $status = strtoupper((string) ($refund['status'] ?? ''));
                if (\in_array($status, self::REFUNDED_STATES, true)) {
                    $refunded += $amount;
                }
                $refunds[] = [
                    'created' => $this->dateTime((string) ($refund['created'] ?? '')),
                    'amount' => $this->formatAmount($amount),
                    'status' => $status,
                    'refundId' => (string) ($refund['refundId'] ?? ''),
                ];
            }
        } catch (ApiException $e) {
            $refundError = $e->getMessage();
        }
        $open = max(0.0, round($paid - $refunded, 2));

        $this->addTplParam('everysizeRefunds', $refunds);
        $this->addTplParam('everysizeRefundError', $refundError);
        $this->addTplParam('everysizePaid', $this->formatAmount($paid));
        $this->addTplParam('everysizeRefunded', $this->formatAmount($refunded));
        $this->addTplParam('everysizeOpen', $this->formatAmount($open));
        $this->addTplParam('everysizeIsOpen', $open > 0);
        // Pre-filled with what is still open; empty if the refunds could not be loaded (the open amount is unknown then).
        $this->addTplParam('everysizeOpenInput', $refundError === '' ? Registry::getLang()->formatCurrency($open) : '');
        $this->addTplParam('everysizeCanRefund', $refundError !== '' || $open > 0);
        $this->addTplParam('everysizeRefundKey', bin2hex(random_bytes(16)));
        $this->addTplParam('everysizeAutoRefundDecline', $this->service(Settings::class)->isAutoRefundDecline());
    }

    /** Known problem reasons of the refund endpoint in plain words, the API's own text behind it. */
    private function refundError(ApiException $e): string
    {
        $ident = [
            'ORDER_NOT_PAID_USING_EVERYSIZE_CHECKOUT_PAYMENTS' => 'EVERYSIZE_CHECKOUT_REFUND_NOT_POSSIBLE',
            'REFUND_AMOUNT_INVALID' => 'EVERYSIZE_CHECKOUT_REFUND_AMOUNT_REJECTED',
            'PAYPAL_REFUND_FAILED' => 'EVERYSIZE_CHECKOUT_REFUND_PAYPAL_REJECTED',
        ][$e->getReason()] ?? 'EVERYSIZE_CHECKOUT_REFUND_FAILED';

        return $this->translate($ident).' ('.$e->getMessage().')';
    }

    /**
     * The reports as table rows with plain labels: a revocation is a
     * cancellation or a return by its reason, a refund's amount is shown as
     * money, empty cells as "–".
     *
     * @param array<int, array{KIND: string, SKU: string, REASON: string, RESULT: string, CREATED: string}> $reports
     *
     * @return array<int, array{time: string, kind: string, subject: string, detail: string, result: string}>
     */
    private function reportRows(array $reports): array
    {
        $rows = [];
        foreach ($reports as $report) {
            $kind = (string) $report['KIND'];
            $sku = (string) $report['SKU'];
            $reason = (string) $report['REASON'];
            $result = (string) $report['RESULT'];

            $kindIdent = 'EVERYSIZE_CHECKOUT_REPORT_'.$kind;
            if ($kind === OrderLinks::KIND_REVOCATION) {
                $kindIdent .= $reason === Client::REASON_RETOUR ? '_RETOUR' : '_DECLINE';
            }
            $subject = $kind === OrderLinks::KIND_REFUND && $sku === OrderLinks::AUTO_REFUND
                ? $this->translate('EVERYSIZE_CHECKOUT_REPORT_AUTO_REFUND')
                : $sku;
            // A refund from the tab stores its amount as reason; the automatic one the refund ID or why it was not done.
            $detail = $kind === OrderLinks::KIND_REFUND && is_numeric($reason) ? $this->formatAmount((float) $reason) : $reason;
            $resultIdent = $kind === OrderLinks::KIND_REFUND && $result !== OrderLinks::RESULT_SENT
                ? 'EVERYSIZE_CHECKOUT_RESULT_REFUND_'.$result
                : 'EVERYSIZE_CHECKOUT_RESULT_'.$result;

            $rows[] = [
                'time' => $this->dateTime((string) $report['CREATED']),
                'kind' => $this->translate($kindIdent),
                'subject' => $subject !== '' ? $subject : '–',
                'detail' => $detail !== '' ? $detail : '–',
                'result' => $this->translate($resultIdent),
            ];
        }

        return $rows;
    }

    /** Stored time or PayPal's ISO 8601 time as shop time, e.g. "09.10.2026 15:27". */
    private function dateTime(string $value): string
    {
        return Display::dateTime($value, $this->translate('EVERYSIZE_CHECKOUT_DATETIME_FORMAT'));
    }

    private function formatAmount(float $amount): string
    {
        return Registry::getLang()->formatCurrency($amount).' €';
    }

    private function error(string $ident): void
    {
        $this->message = ['type' => 'error', 'text' => $this->translate($ident)];
    }

    private function translate(string $ident): string
    {
        return (string) Registry::getLang()->translateString($ident, null, true);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id)
    {
        return ContainerFactory::getInstance()->getContainer()->get($id);
    }
}
