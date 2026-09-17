<?php

class OrderRepository
{
    private $idShop;

    public function __construct($idShop)
    {
        $this->idShop = (int) $idShop;
    }

    public function createSnapshot(array $snapshot)
    {
        return Db::getInstance()->insert('frisbo_awb_order', array(
            'id_shop' => $this->idShop,
            'id_order' => (int) $snapshot['id_order'],
            'backend_carrier_id' => pSQL($snapshot['backend_carrier_id']),
            'courier_name' => pSQL($snapshot['courier_name']),
            'shipping_price' => (float) $snapshot['shipping_price'],
            'is_cod' => (int) (bool) $snapshot['is_cod'],
            'cod_amount' => $snapshot['cod_amount'] === null ? null : (float) $snapshot['cod_amount'],
            'currency_iso' => pSQL($snapshot['currency_iso']),
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ), true, true, Db::INSERT_IGNORE);
    }

    public function findByOrderId($idOrder)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `'._DB_PREFIX_.'frisbo_awb_order`
             WHERE `id_shop` = '.(int) $this->idShop.'
               AND `id_order` = '.(int) $idOrder
        );
    }
}
