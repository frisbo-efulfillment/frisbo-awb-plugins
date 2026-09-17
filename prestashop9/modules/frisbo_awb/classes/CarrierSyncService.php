<?php

class CarrierSyncService
{
    private $repository;
    private $idLang;
    private $idShop;

    public function __construct(CarrierRepository $repository, $idLang, $idShop)
    {
        $this->repository = $repository;
        $this->idLang = (int) $idLang;
        $this->idShop = (int) $idShop;
    }

    public function refreshFromFrisbo(FrisboClient $client)
    {
        $couriers = $client->getCouriers();
        foreach ($couriers as $courier) {
            $this->repository->upsertDiscovered($courier);
        }
        $this->synchronizeAll();

        return count($couriers);
    }

    public function synchronizeAll()
    {
        foreach ($this->repository->all() as $configuredCourier) {
            $this->synchronize($configuredCourier);
        }
    }

    public function synchronize(array $configuredCourier)
    {
        $carrier = false;
        if (!empty($configuredCourier['id_carrier'])) {
            $candidate = new Carrier((int) $configuredCourier['id_carrier']);
            if (Validate::isLoadedObject($candidate) && !$candidate->deleted) {
                $carrier = $candidate;
            }
        }

        if (!$carrier && !empty($configuredCourier['id_reference'])) {
            $idCarrier = (int) Db::getInstance()->getValue(
                'SELECT `id_carrier` FROM `'._DB_PREFIX_.'carrier`
                 WHERE `id_reference` = '.(int) $configuredCourier['id_reference'].'
                   AND `deleted` = 0
                 ORDER BY `id_carrier` DESC'
            );
            if ($idCarrier) {
                $candidate = new Carrier($idCarrier);
                if (Validate::isLoadedObject($candidate)) {
                    $carrier = $candidate;
                }
            }
        }

        if (!$carrier && !(bool) $configuredCourier['enabled']) {
            return;
        }

        if (!$carrier) {
            $carrier = $this->createCarrier($configuredCourier);
        } else {
            $carrier->name = Tools::substr($configuredCourier['friendly_name'], 0, 64);
            $carrier->active = (bool) $configuredCourier['enabled'];
            $carrier->is_module = true;
            $carrier->external_module_name = 'frisbo_awb';
            $carrier->shipping_external = false;
            $carrier->need_range = true;
            if (!$carrier->update()) {
                throw new PrestaShopException('Could not update the PrestaShop carrier.');
            }
        }

        $this->repository->updateNativeCarrier(
            $configuredCourier['id_frisbo_awb_carrier'],
            $carrier->id,
            $carrier->id_reference
        );
    }

    private function createCarrier(array $configuredCourier)
    {
        $carrier = new Carrier();
        $carrier->name = Tools::substr($configuredCourier['friendly_name'], 0, 64);
        $carrier->active = (bool) $configuredCourier['enabled'];
        $carrier->deleted = false;
        $carrier->is_module = true;
        $carrier->external_module_name = 'frisbo_awb';
        $carrier->shipping_external = false;
        $carrier->need_range = true;
        $carrier->shipping_method = Carrier::SHIPPING_METHOD_PRICE;
        $carrier->range_behavior = false;
        $carrier->is_free = false;

        foreach (Language::getLanguages(true, $this->idShop) as $language) {
            $carrier->delay[(int) $language['id_lang']] = 'Delivery by '.Tools::substr($configuredCourier['friendly_name'], 0, 64);
        }

        if (!$carrier->add()) {
            throw new PrestaShopException('Could not create the PrestaShop carrier.');
        }
        // Carrier::add() writes id_reference directly in SQL but does not
        // consistently refresh the in-memory object across supported versions.
        $carrier->id_reference = (int) $carrier->id;

        foreach (Zone::getZones(true) as $zone) {
            $carrier->addZone((int) $zone['id_zone']);
        }

        $range = new RangePrice();
        $range->id_carrier = (int) $carrier->id;
        $range->delimiter1 = 0;
        $range->delimiter2 = 1000000000;
        if (!$range->add()) {
            throw new PrestaShopException('Could not create the PrestaShop carrier price range.');
        }

        $groupIds = array();
        foreach (Group::getGroups($this->idLang, $this->idShop) as $group) {
            $groupIds[] = (int) $group['id_group'];
        }
        $carrier->setGroups($groupIds);

        return $carrier;
    }
}
