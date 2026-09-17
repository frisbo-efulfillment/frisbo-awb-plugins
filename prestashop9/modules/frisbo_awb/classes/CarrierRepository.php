<?php

class CarrierRepository
{
    private $idShop;

    public function __construct($idShop)
    {
        $this->idShop = (int) $idShop;
    }

    public function upsertDiscovered(array $courier)
    {
        $existing = $this->findByBackendId($courier['id']);
        if ($existing) {
            return Db::getInstance()->update(
                'frisbo_awb_carrier',
                array(
                    'backend_name' => pSQL($courier['name']),
                    'date_upd' => date('Y-m-d H:i:s'),
                ),
                'id_frisbo_awb_carrier = '.(int) $existing['id_frisbo_awb_carrier']
            );
        }

        return Db::getInstance()->insert('frisbo_awb_carrier', array(
            'id_shop' => $this->idShop,
            'backend_carrier_id' => pSQL($courier['id']),
            'backend_name' => pSQL($courier['name']),
            'friendly_name' => pSQL($courier['name']),
            'shipping_price' => 0,
            'id_carrier' => null,
            'id_reference' => null,
            'enabled' => 1,
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ), true, true, Db::INSERT_IGNORE);
    }

    public function all()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `'._DB_PREFIX_.'frisbo_awb_carrier`
             WHERE `id_shop` = '.(int) $this->idShop.'
             ORDER BY `backend_name`, `backend_carrier_id`'
        );

        return is_array($rows) ? $rows : array();
    }

    public function findById($id)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `'._DB_PREFIX_.'frisbo_awb_carrier`
             WHERE `id_shop` = '.(int) $this->idShop.'
               AND `id_frisbo_awb_carrier` = '.(int) $id
        );
    }

    public function findByBackendId($backendCarrierId)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `'._DB_PREFIX_.'frisbo_awb_carrier`
             WHERE `id_shop` = '.(int) $this->idShop.'
               AND `backend_carrier_id` = \''.pSQL($backendCarrierId).'\''
        );
    }

    public function findByCarrierId($idCarrier, $enabledOnly = false)
    {
        $carrier = new Carrier((int) $idCarrier);
        if (!Validate::isLoadedObject($carrier)) {
            return false;
        }

        return $this->findByReference((int) $carrier->id_reference, $enabledOnly);
    }

    public function findByReference($idReference, $enabledOnly = false)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `'._DB_PREFIX_.'frisbo_awb_carrier`
             WHERE `id_shop` = '.(int) $this->idShop.'
               AND `id_reference` = '.(int) $idReference.
            ($enabledOnly ? ' AND `enabled` = 1' : '')
        );
    }

    public function updateConfiguration($id, $friendlyName, $shippingPrice, $enabled)
    {
        return Db::getInstance()->update(
            'frisbo_awb_carrier',
            array(
                'friendly_name' => pSQL($friendlyName),
                'shipping_price' => (float) $shippingPrice,
                'enabled' => (int) (bool) $enabled,
                'date_upd' => date('Y-m-d H:i:s'),
            ),
            '`id_shop` = '.(int) $this->idShop.' AND `id_frisbo_awb_carrier` = '.(int) $id
        );
    }

    public function updateNativeCarrier($id, $idCarrier, $idReference)
    {
        return Db::getInstance()->update(
            'frisbo_awb_carrier',
            array(
                'id_carrier' => (int) $idCarrier,
                'id_reference' => (int) $idReference,
                'date_upd' => date('Y-m-d H:i:s'),
            ),
            '`id_shop` = '.(int) $this->idShop.' AND `id_frisbo_awb_carrier` = '.(int) $id
        );
    }

    public function updateAfterCarrierReplacement($oldCarrierId, Carrier $newCarrier)
    {
        return Db::getInstance()->execute(
            'UPDATE `'._DB_PREFIX_.'frisbo_awb_carrier`
             SET `id_carrier` = '.(int) $newCarrier->id.',
                 `id_reference` = '.(int) $newCarrier->id_reference.',
                 `date_upd` = \''.pSQL(date('Y-m-d H:i:s')).'\'
             WHERE `id_shop` = '.(int) $this->idShop.'
               AND (`id_carrier` = '.(int) $oldCarrierId.'
                    OR `id_reference` = '.(int) $newCarrier->id_reference.')'
        );
    }
}
