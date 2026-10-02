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

        return count($couriers);
    }

    public function synchronizeAll()
    {
        foreach ($this->repository->all() as $configuredCourier) {
            if (!empty($configuredCourier['id_carrier']) || !empty($configuredCourier['id_reference'])) {
                $this->synchronize($configuredCourier);
            }
        }
    }

    public function createPrestaShopCarrier($idConfiguredCourier)
    {
        $configuredCourier = $this->repository->findById((int) $idConfiguredCourier);
        if (!$configuredCourier) {
            throw new PrestaShopException('The Frisbo courier was not found.');
        }
        if (!empty($configuredCourier['id_carrier'])) {
            $linkedCarrier = new Carrier((int) $configuredCourier['id_carrier']);
            if (Validate::isLoadedObject($linkedCarrier) && !$linkedCarrier->deleted) {
                throw new PrestaShopException('This Frisbo courier is already linked to a PrestaShop carrier.');
            }
        }

        return $this->ensureHiddenCarrierForBackendId($configuredCourier['backend_carrier_id']);
    }

    public function ensureHiddenCarrierForBackendId($backendCarrierId)
    {
        $configuredCourier = $this->repository->findByBackendId((string) $backendCarrierId);
        if (!$configuredCourier) {
            throw new PrestaShopException('The Frisbo courier is not known. Refresh couriers in the module settings.');
        }

        if (!empty($configuredCourier['id_carrier'])) {
            $carrier = new Carrier((int) $configuredCourier['id_carrier']);
            if (Validate::isLoadedObject($carrier) && !$carrier->deleted) {
                return $carrier;
            }
        }

        $this->synchronize($configuredCourier);
        $configuredCourier = $this->repository->findByBackendId((string) $backendCarrierId);
        $carrier = $configuredCourier && !empty($configuredCourier['id_carrier'])
            ? new Carrier((int) $configuredCourier['id_carrier'])
            : false;
        if (!$carrier || !Validate::isLoadedObject($carrier) || $carrier->deleted) {
            throw new PrestaShopException('Could not create or restore the hidden PrestaShop carrier.');
        }

        return $carrier;
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

        if (!$carrier) {
            $carrier = $this->createCarrier($configuredCourier, false);
        } else {
            $carrier->name = Tools::substr($configuredCourier['friendly_name'], 0, 64);
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

    private function createCarrier(array $configuredCourier, $active)
    {
        $carrier = new Carrier();
        $carrier->name = Tools::substr($configuredCourier['friendly_name'], 0, 64);
        $carrier->active = (bool) $active;
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
        // Carrier::add() writes id_reference directly in SQL in PrestaShop 1.7.4,
        // but does not refresh the in-memory object.
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
