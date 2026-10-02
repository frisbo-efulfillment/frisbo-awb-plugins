<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_0($module)
{
    if (!($module instanceof Frisbo_Awb)) {
        return false;
    }

    $column = Db::getInstance()->getValue(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = \'' . pSQL(_DB_PREFIX_ . 'frisbo_awb_shipment') . '\'
           AND COLUMN_NAME = \'tracking_url\''
    );
    if (!(int) $column && !Db::getInstance()->execute(
        'ALTER TABLE `' . _DB_PREFIX_ . 'frisbo_awb_shipment` ADD `tracking_url` VARCHAR(2048) NULL AFTER `tracking_number`'
    )) {
        return false;
    }

    return $module->unregisterHook('actionValidateOrder');
}
