<?php

declare(strict_types=1);

namespace Everysize\Checkout\Api;

use Everysize\Checkout\Settings;
use OxidEsales\Eshop\Core\ShopVersion;

final class ClientFactory
{
    public static function create(Settings $settings): Client
    {
        return new Client(new CurlTransport(), $settings, null, (string) ShopVersion::getVersion());
    }
}
