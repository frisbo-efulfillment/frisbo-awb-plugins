<?php

class FrisboClientFactory
{
    private $idShop;
    private $idShopGroup;

    public function __construct($idShop, $idShopGroup)
    {
        $this->idShop = (int) $idShop;
        $this->idShopGroup = (int) $idShopGroup;
    }

    public function create()
    {
        $clientId = (string) Configuration::get(
            'FRISBO_AWB_CLIENT_ID',
            null,
            $this->idShopGroup,
            $this->idShop
        );
        $token = (string) Configuration::get(
            'FRISBO_AWB_TOKEN',
            null,
            $this->idShopGroup,
            $this->idShop
        );

        if ($clientId === '' || $token === '') {
            throw new PrestaShopException('Frisbo Client ID and Token must be configured.');
        }

        return new FrisboClient($clientId, $token);
    }
}
