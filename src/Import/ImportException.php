<?php

declare(strict_types=1);

namespace Everysize\Checkout\Import;

/** An everysize order cannot be imported (unknown SKU, missing data …); it is retried on the next run. */
class ImportException extends \RuntimeException
{
}
