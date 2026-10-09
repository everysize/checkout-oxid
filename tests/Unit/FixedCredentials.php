<?php

declare(strict_types=1);

namespace Everysize\Checkout\Tests\Unit;

use Everysize\Checkout\Api\Credentials;

final class FixedCredentials implements Credentials
{
    private string $id;
    private string $secret;

    public function __construct(string $id, string $secret)
    {
        $this->id = $id;
        $this->secret = $secret;
    }

    public function getClientId(): string
    {
        return $this->id;
    }

    public function getClientSecret(): string
    {
        return $this->secret;
    }
}
