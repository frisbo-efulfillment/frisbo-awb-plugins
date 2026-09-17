<?php

class ShipmentRepository
{
    private $idShop;

    public function __construct($idShop)
    {
        $this->idShop = (int) $idShop;
    }

    public function findByOrderId($idOrder)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `'._DB_PREFIX_.'frisbo_awb_shipment`
             WHERE `id_shop` = '.(int) $this->idShop.'
               AND `id_order` = '.(int) $idOrder
        );
    }

    public function begin($idOrder, $externalReference, $requestUid)
    {
        $now = date('Y-m-d H:i:s');
        $db = Db::getInstance();
        $inserted = $db->insert('frisbo_awb_shipment', array(
            'id_shop' => $this->idShop,
            'id_order' => (int) $idOrder,
            'external_reference' => pSQL($externalReference),
            'request_uid' => pSQL($requestUid),
            'shipment_uid' => null,
            'status' => 'creating',
            'tracking_number' => null,
            'last_error' => null,
            'date_add' => $now,
            'date_upd' => $now,
        ), true, true, Db::INSERT_IGNORE);

        if ($inserted && $db->Affected_Rows() > 0) {
            return true;
        }

        $existing = $this->findByOrderId($idOrder);
        if (!$existing || in_array($existing['status'], array('generated', 'creating', 'uncertain'), true)) {
            return false;
        }

        $updated = $db->update(
            'frisbo_awb_shipment',
            array(
                'status' => 'creating',
                'external_reference' => pSQL($externalReference),
                'request_uid' => pSQL($requestUid),
                'last_error' => null,
                'date_upd' => $now,
            ),
            '`id_shop` = '.(int) $this->idShop.'
             AND `id_order` = '.(int) $idOrder.'
             AND `status` = \'failed\''
        );

        return $updated && $db->Affected_Rows() > 0;
    }

    public function markGenerated($idOrder, array $result)
    {
        return Db::getInstance()->update(
            'frisbo_awb_shipment',
            array(
                'shipment_uid' => pSQL($result['uid']),
                'external_reference' => pSQL($result['external_reference']),
                'status' => 'generated',
                'last_error' => null,
                'date_upd' => date('Y-m-d H:i:s'),
            ),
            '`id_shop` = '.(int) $this->idShop.' AND `id_order` = '.(int) $idOrder
        );
    }

    public function markFailed($idOrder, $message, $uncertain = false)
    {
        return Db::getInstance()->update(
            'frisbo_awb_shipment',
            array(
                'status' => $uncertain ? 'uncertain' : 'failed',
                'last_error' => pSQL(Tools::substr((string) $message, 0, 1000)),
                'date_upd' => date('Y-m-d H:i:s'),
            ),
            '`id_shop` = '.(int) $this->idShop.' AND `id_order` = '.(int) $idOrder
        );
    }
}
