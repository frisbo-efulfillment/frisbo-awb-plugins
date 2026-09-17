<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Devprobe extends Module
{
    public function __construct()
    {
        $this->name = 'devprobe';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Local development probe';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Development probe');
        $this->description = $this->l('A tiny module used to verify the local module workflow.');
    }

    public function probeMarker()
    {
        return 'updated-without-rebuild';
    }
}
