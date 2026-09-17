<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__.'/classes/FrisboApiException.php';
require_once __DIR__.'/classes/FrisboClient.php';
require_once __DIR__.'/classes/FrisboClientFactory.php';
require_once __DIR__.'/classes/CarrierRepository.php';
require_once __DIR__.'/classes/OrderRepository.php';
require_once __DIR__.'/classes/ShipmentRepository.php';
require_once __DIR__.'/classes/CodDetector.php';
require_once __DIR__.'/classes/CarrierSyncService.php';
require_once __DIR__.'/classes/ShipmentService.php';

class Frisbo_Awb extends CarrierModule
{
    const CONFIG_CLIENT_ID = 'FRISBO_AWB_CLIENT_ID';
    const CONFIG_TOKEN = 'FRISBO_AWB_TOKEN';

    /** @var int Set by Cart before asking a carrier module for its price. */
    public $id_carrier = 0;

    private static $awbButtonRendered = false;

    public function __construct()
    {
        $this->name = 'frisbo_awb';
        $this->tab = 'shipping_logistics';
        $this->version = '2.1.0';
        $this->author = 'Frisbo';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->controllers = array('AdminFrisboAwb');
        $this->ps_versions_compliancy = array('min' => '8.0.1', 'max' => '8.2.8');

        parent::__construct();

        $this->displayName = 'frisbo-awb';
        $this->description = $this->l('Creates Frisbo AWB shipments from configured PrestaShop carriers.');
    }

    public function install()
    {
        return parent::install()
            && $this->installDatabase()
            && $this->installAdminTab()
            && $this->registerHook('actionCarrierUpdate')
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('displayBackOfficeOrderActions')
            && $this->registerHook('displayAdminOrderMain')
            && $this->registerHook('displayAdminOrder');
    }

    public function uninstall()
    {
        $this->disableManagedCarriers();
        $result = $this->uninstallAdminTab()
            && Configuration::deleteByName(self::CONFIG_CLIENT_ID)
            && Configuration::deleteByName(self::CONFIG_TOKEN)
            && $this->uninstallDatabase();

        return $result && parent::uninstall();
    }

    public function getContent()
    {
        $output = '';
        try {
            if (Tools::isSubmit('submitFrisboRefresh')) {
                $this->saveCredentialsFromRequest();
                $count = $this->getCarrierSyncService()->refreshFromFrisbo($this->getClientFactory()->create());
                $output .= $this->displayConfirmation(sprintf($this->l('%d Frisbo couriers refreshed.'), $count));
            } elseif (Tools::isSubmit('submitFrisboCouriers')) {
                $this->saveCourierConfiguration();
                $this->getCarrierSyncService()->synchronizeAll();
                $output .= $this->displayConfirmation($this->l('Courier configuration saved.'));
            } elseif (Tools::isSubmit('submitFrisboCredentials')) {
                $this->saveCredentialsFromRequest();
                $output .= $this->displayConfirmation($this->l('Frisbo connection settings saved.'));
            }
        } catch (Exception $exception) {
            $output .= $this->displayError($exception->getMessage());
        }

        return $output.$this->renderConfiguration();
    }

    public function getOrderShippingCost($cart, $shippingCost)
    {
        if (!$cart || !$this->id_carrier) {
            return false;
        }

        $repository = new CarrierRepository((int) $cart->id_shop);
        $configuredCourier = $repository->findByCarrierId((int) $this->id_carrier, true);
        if (!$configuredCourier) {
            return false;
        }

        return (float) $shippingCost;
    }

    public function getOrderShippingCostExternal($cart)
    {
        return false;
    }

    public function hookActionCarrierUpdate($params)
    {
        if (empty($params['id_carrier']) || empty($params['carrier']) || !($params['carrier'] instanceof Carrier)) {
            return;
        }

        $newCarrier = $params['carrier'];
        if ($newCarrier->external_module_name !== $this->name) {
            return;
        }

        $this->getCarrierRepository()->updateAfterCarrierReplacement((int) $params['id_carrier'], $newCarrier);
    }

    public function hookActionValidateOrder($params)
    {
        if (empty($params['order']) || !($params['order'] instanceof Order)) {
            return;
        }

        $order = $params['order'];
        $carrierRepository = new CarrierRepository((int) $order->id_shop);
        $configuredCourier = $carrierRepository->findByCarrierId((int) $order->id_carrier, true);
        if (!$configuredCourier) {
            return;
        }

        $codDetector = new CodDetector();
        $isCod = $codDetector->isCod($order);
        $currency = new Currency((int) $order->id_currency);
        $repository = new OrderRepository((int) $order->id_shop);
        $repository->createSnapshot(array(
            'id_order' => (int) $order->id,
            'backend_carrier_id' => $configuredCourier['backend_carrier_id'],
            'courier_name' => $configuredCourier['friendly_name'],
            'shipping_price' => (float) $order->total_shipping_tax_incl,
            'is_cod' => $isCod,
            'cod_amount' => $isCod ? $codDetector->getAmount($order) : null,
            'currency_iso' => Validate::isLoadedObject($currency) ? $currency->iso_code : '',
        ));
    }

    public function hookDisplayBackOfficeOrderActions($params)
    {
        return $this->renderAwbButton($params);
    }

    public function hookDisplayAdminOrder($params)
    {
        return $this->renderAwbButton($params);
    }

    public function hookDisplayAdminOrderMain($params)
    {
        return $this->renderAwbButton($params);
    }

    public function getShipmentService($idShop)
    {
        $shop = new Shop((int) $idShop);
        if (!Validate::isLoadedObject($shop)) {
            throw new PrestaShopException('Invalid shop.');
        }

        $factory = new FrisboClientFactory((int) $shop->id, (int) $shop->id_shop_group);

        return new ShipmentService(
            $factory,
            new OrderRepository((int) $shop->id),
            new ShipmentRepository((int) $shop->id)
        );
    }

    private function renderAwbButton($params)
    {
        if (self::$awbButtonRendered || empty($params['id_order'])) {
            return '';
        }

        $order = new Order((int) $params['id_order']);
        if (!Validate::isLoadedObject($order)) {
            return '';
        }

        $snapshot = (new OrderRepository((int) $order->id_shop))->findByOrderId($order->id);
        if (!$snapshot) {
            return '';
        }

        self::$awbButtonRendered = true;
        $this->context->smarty->assign(array(
            'frisbo_awb_action' => $this->context->link->getAdminLink('AdminFrisboAwb'),
            'frisbo_awb_order_id' => (int) $order->id,
        ));

        return $this->display(__FILE__, 'views/templates/hook/awb_button.tpl');
    }

    private function saveCredentialsFromRequest()
    {
        $clientId = trim((string) Tools::getValue('FRISBO_AWB_CLIENT_ID'));
        $token = (string) Tools::getValue('FRISBO_AWB_TOKEN');
        if ($clientId === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', $clientId)) {
            throw new PrestaShopException($this->l('A valid Frisbo Client ID is required.'));
        }

        Configuration::updateValue(
            self::CONFIG_CLIENT_ID,
            $clientId,
            false,
            (int) $this->context->shop->id_shop_group,
            (int) $this->context->shop->id
        );
        if ($token !== '') {
            Configuration::updateValue(
                self::CONFIG_TOKEN,
                $token,
                false,
                (int) $this->context->shop->id_shop_group,
                (int) $this->context->shop->id
            );
        }

        if (!(string) Configuration::get(
            self::CONFIG_TOKEN,
            null,
            (int) $this->context->shop->id_shop_group,
            (int) $this->context->shop->id
        )) {
            throw new PrestaShopException($this->l('Frisbo Client ID and Token must be configured.'));
        }
    }

    private function saveCourierConfiguration()
    {
        $repository = $this->getCarrierRepository();
        $validated = array();
        foreach ($repository->all() as $courier) {
            $id = (int) $courier['id_frisbo_awb_carrier'];
            $friendlyName = trim((string) Tools::getValue('friendly_name_'.$id));
            $enabled = (bool) Tools::getValue('enabled_'.$id);

            if ($friendlyName === '' || Tools::strlen($friendlyName) > 64 || !Validate::isCarrierName($friendlyName)) {
                throw new PrestaShopException(sprintf($this->l('Invalid friendly name for courier %s.'), $courier['backend_carrier_id']));
            }
            $validated[] = array($id, $friendlyName, $enabled);
        }

        foreach ($validated as $values) {
            $repository->updateConfiguration($values[0], $values[1], $values[2]);
        }
    }

    private function renderConfiguration()
    {
        $clientId = (string) Configuration::get(
            self::CONFIG_CLIENT_ID,
            null,
            (int) $this->context->shop->id_shop_group,
            (int) $this->context->shop->id
        );
        $hasToken = (bool) Configuration::get(
            self::CONFIG_TOKEN,
            null,
            (int) $this->context->shop->id_shop_group,
            (int) $this->context->shop->id
        );
        $couriers = $this->getCarrierRepository()->all();

        $html = '<div class="panel"><h3><i class="icon-lock"></i> '.$this->l('Frisbo connection').'</h3>';
        $html .= '<form method="post" action="">';
        $html .= '<div class="form-group"><label>'.$this->l('Client ID').'</label>';
        $html .= '<input class="form-control" type="text" name="FRISBO_AWB_CLIENT_ID" value="'.$this->escape($clientId).'" required></div>';
        $html .= '<div class="form-group"><label>'.$this->l('Token').'</label>';
        $html .= '<input class="form-control" type="password" name="FRISBO_AWB_TOKEN" value="" autocomplete="new-password" placeholder="'.($hasToken ? $this->l('Saved — leave empty to preserve') : '').'">';
        $html .= '<p class="help-block">'.$this->l('The saved token is never displayed. Leave this field empty to preserve it.').'</p></div>';
        $html .= '<button class="btn btn-default" type="submit" name="submitFrisboCredentials"><i class="icon-save"></i> '.$this->l('Save connection').'</button> ';
        $html .= '<button class="btn btn-primary" type="submit" name="submitFrisboRefresh"><i class="icon-refresh"></i> '.$this->l('Test connection / Refresh couriers').'</button>';
        $html .= '</form></div>';

        $html .= '<div class="panel"><h3><i class="icon-truck"></i> '.$this->l('Courier configuration').'</h3>';
        $html .= '<form method="post" action=""><div class="table-responsive"><table class="table">';
        $html .= '<thead><tr><th>'.$this->l('Frisbo courier').'</th><th>'.$this->l('Friendly checkout name').'</th><th>'.$this->l('PrestaShop carrier').'</th><th>'.$this->l('Enabled').'</th></tr></thead><tbody>';
        if (!$couriers) {
            $html .= '<tr><td colspan="4">'.$this->l('No couriers have been refreshed from Frisbo yet.').'</td></tr>';
        } else {
            foreach ($couriers as $courier) {
                $id = (int) $courier['id_frisbo_awb_carrier'];
                $backendLabel = $courier['backend_carrier_id'];
                if ($courier['backend_name']) {
                    $backendLabel .= ' — '.$courier['backend_name'];
                }
                $html .= '<tr><td><code>'.$this->escape($backendLabel).'</code></td>';
                $html .= '<td><input class="form-control" type="text" maxlength="64" name="friendly_name_'.$id.'" value="'.$this->escape($courier['friendly_name']).'" required></td>';
                if (!empty($courier['id_carrier'])) {
                    $carrierUrl = $this->context->link->getAdminLink(
                        'AdminCarrierWizard',
                        true,
                        array(),
                        array('id_carrier' => (int) $courier['id_carrier'])
                    );
                    $html .= '<td><a class="btn btn-default" href="'.$this->escape($carrierUrl).'"><i class="icon-cog"></i> '.$this->l('Configure carrier').'</a></td>';
                } else {
                    $html .= '<td>'.$this->l('Save to create the PrestaShop carrier.').'</td>';
                }
                $html .= '<td><input type="checkbox" name="enabled_'.$id.'" value="1"'.($courier['enabled'] ? ' checked' : '').'></td></tr>';
            }
        }
        $html .= '</tbody></table></div>';
        if ($couriers) {
            $html .= '<button class="btn btn-primary pull-right" type="submit" name="submitFrisboCouriers"><i class="icon-save"></i> '.$this->l('Save').'</button>';
        }
        $html .= '<div class="clearfix"></div></form></div>';

        return $html;
    }

    private function getCarrierRepository()
    {
        return new CarrierRepository((int) $this->context->shop->id);
    }

    private function getClientFactory()
    {
        return new FrisboClientFactory(
            (int) $this->context->shop->id,
            (int) $this->context->shop->id_shop_group
        );
    }

    private function getCarrierSyncService()
    {
        return new CarrierSyncService(
            $this->getCarrierRepository(),
            (int) $this->context->language->id,
            (int) $this->context->shop->id
        );
    }

    private function escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    private function installDatabase()
    {
        $queries = array(
            'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'frisbo_awb_carrier` (
                `id_frisbo_awb_carrier` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL,
                `backend_carrier_id` VARCHAR(191) NOT NULL,
                `backend_name` VARCHAR(255) NULL,
                `friendly_name` VARCHAR(255) NOT NULL,
                `id_carrier` INT UNSIGNED NULL,
                `id_reference` INT UNSIGNED NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_frisbo_awb_carrier`),
                UNIQUE KEY `uniq_frisbo_carrier_shop_backend` (`id_shop`, `backend_carrier_id`),
                KEY `idx_frisbo_carrier_reference` (`id_shop`, `id_reference`)
            ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'frisbo_awb_order` (
                `id_frisbo_awb_order` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL,
                `id_order` INT UNSIGNED NOT NULL,
                `backend_carrier_id` VARCHAR(191) NOT NULL,
                `courier_name` VARCHAR(255) NOT NULL,
                `shipping_price` DECIMAL(20,6) NOT NULL,
                `is_cod` TINYINT(1) NOT NULL DEFAULT 0,
                `cod_amount` DECIMAL(20,6) NULL,
                `currency_iso` VARCHAR(8) NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_frisbo_awb_order`),
                UNIQUE KEY `uniq_frisbo_order_shop_order` (`id_shop`, `id_order`)
            ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'frisbo_awb_shipment` (
                `id_frisbo_awb_shipment` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL,
                `id_order` INT UNSIGNED NOT NULL,
                `external_reference` VARCHAR(200) NOT NULL,
                `request_uid` VARCHAR(191) NOT NULL,
                `shipment_uid` VARCHAR(191) NULL,
                `status` VARCHAR(32) NOT NULL,
                `tracking_number` VARCHAR(191) NULL,
                `last_error` TEXT NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_frisbo_awb_shipment`),
                UNIQUE KEY `uniq_frisbo_shipment_shop_order` (`id_shop`, `id_order`),
                UNIQUE KEY `uniq_frisbo_shipment_shop_external` (`id_shop`, `external_reference`)
            ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        foreach ($queries as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    private function uninstallDatabase()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'frisbo_awb_shipment`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'frisbo_awb_order`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'frisbo_awb_carrier`');
    }

    private function installAdminTab()
    {
        if (Tab::getIdFromClassName('AdminFrisboAwb')) {
            return true;
        }

        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminFrisboAwb';
        $tab->module = $this->name;
        $tab->id_parent = -1;
        foreach (Language::getLanguages(true) as $language) {
            $tab->name[(int) $language['id_lang']] = 'Frisbo AWB';
        }

        return $tab->add();
    }

    private function uninstallAdminTab()
    {
        $idTab = (int) Tab::getIdFromClassName('AdminFrisboAwb');
        if (!$idTab) {
            return true;
        }

        return (new Tab($idTab))->delete();
    }

    private function disableManagedCarriers()
    {
        if (!$this->active) {
            return;
        }
        foreach ($this->getCarrierRepository()->all() as $configuredCourier) {
            if (!empty($configuredCourier['id_carrier'])) {
                $carrier = new Carrier((int) $configuredCourier['id_carrier']);
                if (Validate::isLoadedObject($carrier)) {
                    $carrier->active = false;
                    $carrier->update();
                }
            }
        }
    }
}
