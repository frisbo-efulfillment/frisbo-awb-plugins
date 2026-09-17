<?php

class CodDetector
{
    private $moduleNames = array('cashondelivery', 'ps_cashondelivery');

    public function isCod(Order $order)
    {
        return in_array((string) $order->module, $this->moduleNames, true);
    }

    public function getAmount(Order $order)
    {
        return $this->isCod($order) ? (float) $order->total_paid_tax_incl : null;
    }
}
