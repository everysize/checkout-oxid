<?php

declare(strict_types=1);

namespace Everysize\Checkout;

final class Module
{
    public const ID = 'everysize_checkout';
    public const VERSION = '1.1.3';

    /** Payment method the imported orders carry; created on activation, not offered in the storefront. */
    public const PAYMENT_ID = 'everysize_checkout';
}
