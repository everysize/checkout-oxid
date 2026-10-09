<?php

declare(strict_types=1);

namespace Everysize\Checkout\Api;

interface Credentials
{
    public function getClientId(): string;

    public function getClientSecret(): string;
}
