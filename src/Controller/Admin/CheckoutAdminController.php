<?php

declare(strict_types=1);

namespace Everysize\Checkout\Controller\Admin;

use Everysize\Checkout\Api\ApiException;
use Everysize\Checkout\Api\ApiUrl;
use Everysize\Checkout\Api\Client;
use Everysize\Checkout\Protocol;
use Everysize\Checkout\Settings;
use OxidEsales\Eshop\Application\Controller\Admin\AdminController;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;

/**
 * Admin page "Bestellungen → everysize Checkout": credentials, mapping,
 * connection test and the sync protocol.
 */
class CheckoutAdminController extends AdminController
{
    private const PAGE_SIZE = 50;
    private const CARRIER_MAX_LENGTH = 31;

    protected $_sThisTemplate = '@everysize_checkout/admin/everysize_checkout_admin';

    /** @var array{type: string, text: string}|null */
    private ?array $message = null;

    public function render()
    {
        parent::render();

        $settings = $this->settings();
        $protocol = $this->protocol();
        $filter = (string) Registry::getRequest()->getRequestParameter('protocolFilter');
        $state = $filter === 'ok' ? true : ($filter === 'error' ? false : null);
        $page = max(0, (int) Registry::getRequest()->getRequestParameter('protocolPage'));
        $total = $protocol->count($state);

        $environment = 'production';
        $environmentError = '';
        try {
            $apiUrl = ApiUrl::resolve();
            if (!ApiUrl::isProduction($apiUrl)) {
                $environment = 'test';
            }
        } catch (ApiException $e) {
            $apiUrl = '';
            $environment = 'invalid';
            $environmentError = $e->getMessage();
        }

        $this->addTplParam('everysizeMessage', $this->message);
        $this->addTplParam('everysizeApiUrl', $apiUrl);
        $this->addTplParam('everysizeEnvironment', $environment);
        $this->addTplParam('everysizeEnvironmentError', $environmentError);
        $this->addTplParam('everysizeClientId', $settings->getClientId());
        $this->addTplParam('everysizeHasSecret', $settings->getClientSecret() !== '');
        $this->addTplParam('everysizeDeliverySetId', $settings->getDeliverySetId());
        $this->addTplParam('everysizeDeliverySets', $this->deliverySets());
        $this->addTplParam('everysizeCarrier', $settings->getCarrier());
        $this->addTplParam('everysizeCarriers', Settings::CARRIERS);
        $this->addTplParam('everysizeAutoRefundDecline', $settings->isAutoRefundDecline());
        $this->addTplParam('everysizeProtocol', $protocol->getEntries($page * self::PAGE_SIZE, self::PAGE_SIZE, $state));
        $this->addTplParam('everysizeProtocolFilter', $filter);
        $this->addTplParam('everysizeProtocolPage', $page);
        $this->addTplParam('everysizeProtocolPages', max(1, (int) ceil($total / self::PAGE_SIZE)));
        $this->addTplParam('everysizeProtocolTotal', $total);

        return $this->_sThisTemplate;
    }

    public function save(): void
    {
        if (!$this->checkChallenge()) {
            return;
        }

        $values = $this->postedValues();
        $settings = $this->settings();

        if ($values['deliverySetId'] !== '' && !\array_key_exists($values['deliverySetId'], $this->deliverySets())) {
            $this->error('EVERYSIZE_CHECKOUT_ERROR_DELIVERYSET');

            return;
        }
        if ($values['carrier'] === '' || mb_strlen($values['carrier']) > self::CARRIER_MAX_LENGTH) {
            $this->error('EVERYSIZE_CHECKOUT_ERROR_CARRIER');

            return;
        }

        $settings->set(Settings::CLIENT_ID, $values['clientId']);
        // An empty secret field keeps the stored secret — it is never sent back to the browser.
        if ($values['clientSecret'] !== '') {
            $settings->set(Settings::CLIENT_SECRET, $values['clientSecret']);
        }
        $settings->set(Settings::DELIVERY_SET, $values['deliverySetId']);
        $settings->set(Settings::CARRIER, $values['carrier']);
        // Switched on now: only cancellations from now on are refunded automatically, never older ones.
        if ($values['autoRefundDecline'] === '1' && (!$settings->isAutoRefundDecline() || $settings->getAutoRefundSince() === '')) {
            $settings->set(Settings::AUTO_REFUND_SINCE, date('Y-m-d H:i:s'));
        }
        $settings->set(Settings::AUTO_REFUND_DECLINE, $values['autoRefundDecline'] === '1' ? '1' : '0');

        $this->message = ['type' => 'ok', 'text' => $this->translate('EVERYSIZE_CHECKOUT_SAVED')];
    }

    /** Removes client ID and secret — the module then makes no API calls at all. */
    public function removeCredentials(): void
    {
        if (!$this->checkChallenge()) {
            return;
        }

        $this->settings()->set(Settings::CLIENT_ID, '');
        $this->settings()->set(Settings::CLIENT_SECRET, '');
        $this->message = ['type' => 'ok', 'text' => $this->translate('EVERYSIZE_CHECKOUT_CREDENTIALS_REMOVED')];
    }

    /** Requests a token with the entered (or, if empty, the stored) credentials. */
    public function test(): void
    {
        if (!$this->checkChallenge()) {
            return;
        }

        $values = $this->postedValues();
        $settings = $this->settings();
        $clientId = $values['clientId'] !== '' ? $values['clientId'] : $settings->getClientId();
        $clientSecret = $values['clientSecret'] !== '' ? $values['clientSecret'] : $settings->getClientSecret();
        if ($clientId === '' || $clientSecret === '') {
            $this->error('EVERYSIZE_CHECKOUT_ERROR_NO_CREDENTIALS');

            return;
        }

        try {
            $client = $this->service(Client::class);
            $token = $client->requestToken($clientId, $clientSecret);
            $this->message = ['type' => 'ok', 'text' => sprintf(
                $this->translate('EVERYSIZE_CHECKOUT_TEST_OK'),
                $token['shop_id'],
                $client->getApiUrl()
            )];
        } catch (ApiException $e) {
            $this->message = ['type' => 'error', 'text' => $this->translate('EVERYSIZE_CHECKOUT_TEST_FAILED').' '.$e->getMessage()];
        }
    }

    /**
     * @return array<string, string> delivery set id => title
     */
    private function deliverySets(): array
    {
        $rows = DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getAll(
            'SELECT OXID, OXTITLE, OXACTIVE FROM oxdeliveryset WHERE OXSHOPID = :shopId ORDER BY OXPOS, OXTITLE',
            [':shopId' => Registry::getConfig()->getShopId()]
        );
        $sets = [];
        foreach ($rows as $row) {
            $sets[(string) $row['OXID']] = (string) $row['OXTITLE']
                .((int) $row['OXACTIVE'] === 1 ? '' : ' ('.$this->translate('EVERYSIZE_CHECKOUT_INACTIVE').')');
        }

        return $sets;
    }

    /**
     * @return array{clientId: string, clientSecret: string, deliverySetId: string, carrier: string, autoRefundDecline: string}
     */
    private function postedValues(): array
    {
        $posted = Registry::getRequest()->getRequestParameter('everysize');
        $posted = \is_array($posted) ? $posted : [];
        $value = static function (string $key) use ($posted): string {
            return \is_string($posted[$key] ?? null) ? trim($posted[$key]) : '';
        };

        return [
            'clientId' => $value('clientId'),
            'clientSecret' => $value('clientSecret'),
            'deliverySetId' => $value('deliverySetId'),
            'carrier' => $value('carrier'),
            'autoRefundDecline' => $value('autoRefundDecline'),
        ];
    }

    private function checkChallenge(): bool
    {
        if (Registry::getSession()->checkSessionChallenge()) {
            return true;
        }
        $this->error('EVERYSIZE_CHECKOUT_ERROR_SESSION');

        return false;
    }

    private function error(string $ident): void
    {
        $this->message = ['type' => 'error', 'text' => $this->translate($ident)];
    }

    private function translate(string $ident): string
    {
        return (string) Registry::getLang()->translateString($ident, null, true);
    }

    private function settings(): Settings
    {
        return $this->service(Settings::class);
    }

    private function protocol(): Protocol
    {
        return $this->service(Protocol::class);
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
