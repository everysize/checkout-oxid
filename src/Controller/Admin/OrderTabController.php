<?php

declare(strict_types=1);

namespace Everysize\Checkout\Controller\Admin;

use Everysize\Checkout\Api\Amount;
use Everysize\Checkout\Api\ApiException;
use Everysize\Checkout\Api\Client;
use Everysize\Checkout\Display;
use Everysize\Checkout\Export\RevokedNotice;
use Everysize\Checkout\ManualRefund;
use Everysize\Checkout\OrderLinks;
use Everysize\Checkout\Protocol;
use Everysize\Checkout\Settings;
use OxidEsales\Eshop\Application\Controller\Admin\AdminDetailsController;
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
            $this->addTplParam('everysizeRevokedNote', $this->revokedNote($links, $orderId));
        }

        if ($link !== null && $link['PAYPAL_CAPTURE_ID'] !== '') {
            try {
                $this->addRefundParams($orderId, $link['EVERYSIZE_ORDER_ID']);
            } catch (\Throwable $e) {
                // E.g. the module update is not finished (table missing): a notice instead of a broken tab, no forms.
                $this->addTplParam('everysizeRefunds', []);
                $this->addTplParam('everysizeRefundError', $this->translate('EVERYSIZE_CHECKOUT_SCHEMA_UPDATE_NEEDED').' '.$e->getMessage());
                $this->addTplParam('everysizeCanRefund', false);
                $this->addTplParam('everysizeUnclear', false);
            }
        }

        return $this->_sThisTemplate;
    }

    public function refund(): void
    {
        if (!Registry::getSession()->checkSessionChallenge()) {
            $this->error('EVERYSIZE_CHECKOUT_ERROR_SESSION');

            return;
        }

        // The checkout waits up to 60 s for PayPal, the API call up to 100 s.
        @set_time_limit(180);

        $orderId = (string) $this->getEditObjectId();
        $links = $this->service(OrderLinks::class);
        $link = $links->getLink($orderId);
        if ($link === null || $link['PAYPAL_CAPTURE_ID'] === '') {
            $this->error('EVERYSIZE_CHECKOUT_REFUND_NOT_POSSIBLE');

            return;
        }

        // The rules (only a clear refusal counts, the open entry, repeating reads the PayPal list first, stale tab,
        // no refund of the rest from an empty field) live in ManualRefund; this method shows the outcome.
        $request = Registry::getRequest();
        $known = (string) $request->getRequestParameter('everysize_known_refunded');
        $result = (new ManualRefund($this->service(Client::class), $links))->refund(
            $orderId,
            $link['EVERYSIZE_ORDER_ID'],
            (string) $request->getRequestParameter('everysize_refund_amount'),
            (string) $request->getRequestParameter('everysize_refund_repeat') === '1',
            (string) $request->getRequestParameter('everysize_refund_key'),
            preg_match('/^\d+(\.\d{1,2})?$/', $known) === 1 ? $known : null
        );

        switch ($result['outcome']) {
            case ManualRefund::DONE:
            case ManualRefund::FOUND:
                // The amount of the refund itself: a repeated "rest" has no amount of its own.
                $paid = (string) ($result['refund']['amount'] ?? $result['amount']);
                $this->message = ['type' => 'ok', 'text' => sprintf(
                    $this->translate($result['outcome'] === ManualRefund::FOUND ? 'EVERYSIZE_CHECKOUT_REFUND_FOUND' : 'EVERYSIZE_CHECKOUT_REFUND_OK'),
                    $this->formatAmount((float) $paid),
                    (string) ($result['refund']['status'] ?? '')
                )];
                break;
            case ManualRefund::ALREADY_REFUNDED:
                // everysize answers a capture that is refunded completely already with 201 and this status, no refund ID.
                $this->error('EVERYSIZE_CHECKOUT_REFUND_ALREADY');
                break;
            case ManualRefund::INVALID_AMOUNT:
                $this->error('EVERYSIZE_CHECKOUT_REFUND_ERROR_AMOUNT');
                break;
            case ManualRefund::OTHER_AMOUNT:
                $this->error('EVERYSIZE_CHECKOUT_REFUND_UNCLEAR_OTHER_AMOUNT');
                break;
            case ManualRefund::NOTHING_OPEN:
                $this->error('EVERYSIZE_CHECKOUT_REFUND_NOTHING_OPEN');
                break;
            case ManualRefund::RUNNING:
                $this->error('EVERYSIZE_CHECKOUT_REFUND_RUNNING');
                break;
            case ManualRefund::STALE:
                $this->error('EVERYSIZE_CHECKOUT_REFUND_STALE');
                break;
            case ManualRefund::LIST_UNREADABLE:
                $this->error('EVERYSIZE_CHECKOUT_REFUND_LIST_UNREADABLE');
                break;
            case ManualRefund::REJECTED_REPEAT:
                $this->message = ['type' => 'error', 'text' => $this->translate('EVERYSIZE_CHECKOUT_REFUND_REJECTED_REPEAT').' '.$result['message']];
                break;
            case ManualRefund::NOT_SENT:
                $this->message = ['type' => 'error', 'text' => $this->translate('EVERYSIZE_CHECKOUT_REFUND_NOT_SENT').' '.$result['message']];
                break;
            case ManualRefund::REJECTED:
                $this->message = ['type' => 'error', 'text' => $this->refundError($result['reason'], $result['message'])];
                break;
            default: // ManualRefund::UNCLEAR
                $this->error('EVERYSIZE_CHECKOUT_REFUND_UNCLEAR');
        }
    }

    /** "Mark as settled": sends nothing, ends the open refund for good (not before 150 s). */
    public function settle(): void
    {
        if (!Registry::getSession()->checkSessionChallenge()) {
            $this->error('EVERYSIZE_CHECKOUT_ERROR_SESSION');

            return;
        }

        $orderId = (string) $this->getEditObjectId();
        $links = $this->service(OrderLinks::class);
        if ($links->getLink($orderId) === null) {
            $this->error('EVERYSIZE_CHECKOUT_REFUND_NOT_POSSIBLE');

            return;
        }

        $result = (new ManualRefund($this->service(Client::class), $links))->markSettled($orderId, (string) Registry::getRequest()->getRequestParameter('everysize_settle_key'));
        switch ($result['outcome']) {
            case ManualRefund::SETTLED:
                $this->service(Protocol::class)->add(
                    'Refund marked as settled',
                    sprintf('Order %s: the open refund with an unclear result was marked as settled by the merchant, nothing was sent. The automatic refund stays off for this order.', $orderId),
                    true
                );
                $this->message = ['type' => 'ok', 'text' => $this->translate('EVERYSIZE_CHECKOUT_REFUND_SETTLED_OK')];
                break;
            case ManualRefund::RUNNING:
                $this->error('EVERYSIZE_CHECKOUT_REFUND_RUNNING');
                break;
            case ManualRefund::NOTHING_OPEN:
                $this->error('EVERYSIZE_CHECKOUT_REFUND_NOTHING_OPEN');
                break;
            default:
                $this->message = ['type' => 'error', 'text' => $this->translate('EVERYSIZE_CHECKOUT_REFUND_NOT_SENT').' '.$result['message']];
        }
    }

    private function addRefundParams(string $orderId, string $everysizeOrderId): void
    {
        // "Paid" is what everysize charged (grossPrice): the shop's own total drops when a position is cancelled
        // (recalculateOrder), which would hide the open amount and the form.
        $paid = 0.0;
        $refunds = [];
        $refunded = 0.0;
        $refundError = '';
        try {
            $client = $this->service(Client::class);
            $paid = Amount::parse($client->getOrder($everysizeOrderId)['grossPrice'] ?? null, 'grossPrice');
            foreach ($client->getRefunds($everysizeOrderId) as $refund) {
                $amount = Amount::parse($refund['amount'] ?? '0', 'refund amount');
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
        $unclear = $this->service(OrderLinks::class)->getOpenRefund($orderId);
        // A refund with an unknown outcome blocks every other one: only repeating it (same key, same amount) is offered.
        // Not with an unreadable list: the form's check against the PayPal list would refuse it anyway.
        $this->addTplParam('everysizeCanRefund', $unclear === null && $refundError === '' && $open > 0);
        $this->addTplParam('everysizeKnownRefunded', number_format(round($refunded, 2), 2, '.', ''));
        $this->addTplParam('everysizeUnclear', $unclear !== null);
        if ($unclear !== null) {
            $what = $unclear['amount'] !== '' ? $this->formatAmount((float) $unclear['amount']) : $this->translate('EVERYSIZE_CHECKOUT_REFUND_REST');
            $running = ($time = strtotime($unclear['sent'] ?? $unclear['created'])) !== false && time() - $time < ManualRefund::MIN_AGE;
            $this->addTplParam('everysizeUnclearText', sprintf($this->translate('EVERYSIZE_CHECKOUT_REFUND_UNCLEAR_TEXT'), $what, $this->dateTime($unclear['created']))
                .($running ? ' '.$this->translate('EVERYSIZE_CHECKOUT_REFUND_RUNNING') : ''));
            $this->addTplParam('everysizeUnclearKey', $unclear['key']);
            $this->addTplParam('everysizeUnclearButton', sprintf($this->translate('EVERYSIZE_CHECKOUT_REFUND_REPEAT_BUTTON'), $what));
            $this->addTplParam('everysizeUnclearConfirm', sprintf($this->translate('EVERYSIZE_CHECKOUT_REFUND_REPEAT_CONFIRM'), $what));
        }
        $this->addTplParam('everysizeRefundKey', bin2hex(random_bytes(16)));
        $this->addTplParam('everysizeAutoRefundDecline', $this->service(Settings::class)->isAutoRefundDecline());
    }

    /** Known problem reasons of the refund endpoint in plain words, the API's own text behind it. */
    private function refundError(string $reason, string $message): string
    {
        $ident = [
            'ORDER_NOT_PAID_USING_EVERYSIZE_CHECKOUT_PAYMENTS' => 'EVERYSIZE_CHECKOUT_REFUND_NOT_POSSIBLE',
            'REFUND_AMOUNT_INVALID' => 'EVERYSIZE_CHECKOUT_REFUND_AMOUNT_REJECTED',
            'PAYPAL_REFUND_FAILED' => 'EVERYSIZE_CHECKOUT_REFUND_PAYPAL_REJECTED',
        ][$reason] ?? 'EVERYSIZE_CHECKOUT_REFUND_FAILED';

        return $this->translate($ident).' ('.$message.')';
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
            if ($kind === OrderLinks::KIND_REFUND && strpos($sku, 'settled-') === 0) {
                $subject = '';
            }
            // A refund from the tab stores its amount as reason; the automatic one the refund ID or why it was not done.
            if ($kind === OrderLinks::KIND_REVOKED_AT_EVERYSIZE) {
                $reason = ''; // OPEN / SHIPPED is the kind of the hint, shown above the tab
            }
            $detail = $kind === OrderLinks::KIND_REFUND && is_numeric($reason) ? $this->formatAmount((float) $reason) : $reason;
            $resultIdent = $kind === OrderLinks::KIND_REFUND && $result !== OrderLinks::RESULT_SENT
                ? 'EVERYSIZE_CHECKOUT_RESULT_REFUND_'.$result
                : 'EVERYSIZE_CHECKOUT_RESULT_'.$result;
            if ($kind === OrderLinks::KIND_REVOKED_AT_EVERYSIZE) {
                $resultIdent = 'EVERYSIZE_CHECKOUT_RESULT_REVOKED_'.$result;
            }

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

    /**
     * The hint of the cancellation check in the language of the admin: the order is cancelled at everysize. Empty if
     * there is none: not cancelled there, or closed in the shop by now (cancelled, refunded in full, every item
     * cancelled), then there is nothing left to warn about.
     */
    private function revokedNote(OrderLinks $links, string $orderId): string
    {
        $notes = $links->getRevokedNotes([$orderId]);
        $info = isset($notes[$orderId]) ? $links->getRevokedCheckInfo($orderId) : null;
        if ($info === null) {
            return '';
        }

        // getLanguageAbbr() without an id is the language being edited, not the one of the admin interface.
        $language = Registry::getLang();

        return RevokedNotice::text($notes[$orderId], (string) $language->getLanguageAbbr($language->getTplLanguage()), $info['number']);
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
