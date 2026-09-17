<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_1_0($module)
{
    return Db::getInstance()->update(
        'carrier',
        array(
            'shipping_external' => 0,
            'need_range' => 1,
        ),
        "`external_module_name` = '".pSQL($module->name)."'"
    );
}
