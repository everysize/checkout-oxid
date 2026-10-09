<?php

use Everysize\Checkout\Controller\Admin\CheckoutAdminController;
use Everysize\Checkout\Controller\Admin\OrderList;
use Everysize\Checkout\Controller\Admin\OrderTabController;
use Everysize\Checkout\Core\ModuleEvents;
use Everysize\Checkout\Module;

$sMetadataVersion = '2.1';

$aModule = [
    'id' => Module::ID,
    'title' => 'everysize Checkout',
    'description' => [
        'de' => 'Übernimmt Bestellungen aus dem everysize-Direktkauf und meldet Bestellnummer, Versand, Storno und Retoure zurück. Einstellungen, Zugangsdaten und Protokoll: Bestellungen → everysize Checkout (nicht hier unter „Einstell.“).',
        'en' => 'Imports orders from everysize direct checkout and reports order number, shipping, cancellation and return back. Settings, credentials and protocol: Orders → everysize Checkout (not here under "Settings").',
    ],
    'version' => Module::VERSION,
    'author' => 'everysize GmbH',
    'url' => 'https://www.everysize.com',
    'extend' => [
        \OxidEsales\Eshop\Application\Controller\Admin\OrderList::class => OrderList::class,
    ],
    'controllers' => [
        'everysize_checkout_admin' => CheckoutAdminController::class,
        'everysize_checkout_order' => OrderTabController::class,
    ],
    'events' => [
        'onActivate' => ModuleEvents::class.'::onActivate',
        'onDeactivate' => ModuleEvents::class.'::onDeactivate',
    ],
];
