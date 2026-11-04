<?php

declare(strict_types=1);

namespace Everysize\Checkout\Api;

class ApiException extends \RuntimeException
{
    private int $status;
    private string $reason;

    private bool $notSent;

    /**
     * @param bool $notSent the request never left (token could not be fetched): nothing happened at everysize
     */
    public function __construct(string $message, int $status = 0, string $reason = '', bool $notSent = false)
    {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->reason = $reason;
        $this->notSent = $notSent;
    }

    /** The request did not go out (e.g. the token call failed before the refund POST). */
    public function isNotSent(): bool
    {
        return $this->notSent;
    }

    /**
     * The refund POST was refused in a way that proves no money moved: 422 with
     * PAYPAL_REFUND_FAILED or REFUND_AMOUNT_INVALID, or 400 with
     * ORDER_NOT_PAID_USING_EVERYSIZE_CHECKOUT_PAYMENTS. Every other 4xx proves nothing
     * (401, 403, 404, 408, 409, 425, 429 can come from a gateway in front of a booked refund).
     */
    public function isSafeRejection(): bool
    {
        if ($this->notSent) {
            return false;
        }

        return ($this->status === 422 && \in_array($this->reason, ['PAYPAL_REFUND_FAILED', 'REFUND_AMOUNT_INVALID'], true))
            || ($this->status === 400 && $this->reason === 'ORDER_NOT_PAID_USING_EVERYSIZE_CHECKOUT_PAYMENTS');
    }

    /** HTTP status of the failed call, 0 if the API was not reachable at all. */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * The outcome of the refund POST is unknown: it went out and was not refused
     * for sure. A refund that ends like this may have been booked.
     */
    public function isOutcomeUnclear(): bool
    {
        return !$this->notSent && !$this->isSafeRejection();
    }

    /** Machine-readable "reason" of an everysize problem response, e.g. MERCHANT_ORDER_NUMBER_CANNOT_BE_MODIFIED. */
    public function getReason(): string
    {
        return $this->reason;
    }
}
