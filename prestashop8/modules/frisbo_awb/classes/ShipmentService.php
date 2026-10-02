<?php

class ShipmentService
{
    private $clientFactory;
    private $shipmentRepository;
    private $carrierRepository;
    private $codDetector;

    public function __construct(
        FrisboClientFactory $clientFactory,
        ShipmentRepository $shipmentRepository,
        CarrierRepository $carrierRepository,
        CodDetector $codDetector
    ) {
        $this->clientFactory = $clientFactory;
        $this->shipmentRepository = $shipmentRepository;
        $this->carrierRepository = $carrierRepository;
        $this->codDetector = $codDetector;
    }

    public function getLabel(Order $order, $backendCarrierId = null)
    {
        $client = $this->clientFactory->create();
        $shipmentRecord = $this->shipmentRepository->findByOrderId($order->id);

        if (!$shipmentRecord || $shipmentRecord['status'] !== 'generated') {
            if ($shipmentRecord && $shipmentRecord['status'] === 'creating') {
                throw new PrestaShopException('A Frisbo shipment is already being generated for this order.');
            }
            if ($shipmentRecord && $shipmentRecord['status'] === 'uncertain') {
                throw new PrestaShopException('Frisbo reported an uncertain shipment result. Reconcile it before retrying.');
            }
            $externalId = $this->getGenerationExternalId($order, $shipmentRecord);
            $externalReference = 'prestashop:'.$externalId;
            $payload = $this->buildShipment($order, $backendCarrierId, $externalId);
            if (!$this->shipmentRepository->begin(
                $order->id,
                $externalReference,
                $externalReference
            )) {
                $shipmentRecord = $this->shipmentRepository->findByOrderId($order->id);
                if (!$shipmentRecord || $shipmentRecord['status'] !== 'generated') {
                    throw new PrestaShopException('A Frisbo shipment is already being generated for this order.');
                }
            } else {
                try {
                    $result = $client->generateShipment($payload);
                    $this->shipmentRepository->markGenerated($order->id, $result);
                } catch (FrisboApiException $exception) {
                    $this->shipmentRepository->markFailed(
                        $order->id,
                        $exception->getMessage(),
                        in_array($exception->getHttpStatus(), array(409, 502), true)
                    );
                    throw $exception;
                } catch (Exception $exception) {
                    $this->shipmentRepository->markFailed($order->id, $exception->getMessage());
                    throw $exception;
                }
            }

            $shipmentRecord = $this->shipmentRepository->findByOrderId($order->id);
        }

        $externalId = $this->getStoredExternalId($order, $shipmentRecord);
        $remoteShipment = $client->getShipmentByExternalId('prestashop', $externalId);
        if ($this->isCancelledRemoteShipment($remoteShipment)) {
            $this->shipmentRepository->markCancelled($order->id);
            throw new PrestaShopException('This Frisbo AWB has been cancelled. Reload the order to generate a new one.');
        }
        $this->synchronizeTracking($order, $remoteShipment);

        return $client->downloadLabelByExternalId('prestashop', $externalId);
    }

    public function hasGeneratedShipment(Order $order)
    {
        $shipment = $this->shipmentRepository->findByOrderId($order->id);

        return $shipment && $shipment['status'] === 'generated';
    }

    public function hasCancelledShipment(Order $order)
    {
        $shipment = $this->shipmentRepository->findByOrderId($order->id);

        return $shipment && $shipment['status'] === 'cancelled';
    }

    public function getShipmentStatus(Order $order)
    {
        $shipment = $this->shipmentRepository->findByOrderId($order->id);

        return $shipment && !empty($shipment['status']) ? (string) $shipment['status'] : 'none';
    }

    public function cancelShipment(Order $order)
    {
        $shipment = $this->shipmentRepository->findByOrderId($order->id);
        if ($shipment && $shipment['status'] === 'cancelled') {
            return array('uid' => (string) $shipment['shipment_uid'], 'status' => 'cancelled');
        }
        if (!$shipment || $shipment['status'] !== 'generated' || empty($shipment['shipment_uid'])) {
            throw new PrestaShopException('No generated Frisbo AWB is available to cancel for this order.');
        }

        $client = $this->clientFactory->create();
        $remoteShipment = $client->getShipmentByExternalId(
            'prestashop',
            $this->getStoredExternalId($order, $shipment)
        );
        if ($this->isCancelledRemoteShipment($remoteShipment)) {
            if (!$this->shipmentRepository->markCancelled($order->id)) {
                throw new PrestaShopException('The AWB is cancelled in Frisbo, but its local status could not be saved.');
            }

            return array('uid' => (string) $shipment['shipment_uid'], 'status' => 'cancelled');
        }

        try {
            $result = $client->cancelShipmentByUid($shipment['shipment_uid']);
        } catch (FrisboApiException $exception) {
            // A timeout, 409, or 502 can be returned after the backend has
            // accepted the cancellation. Reconcile before reporting failure.
            try {
                $remoteShipment = $client->getShipmentByExternalId(
                    'prestashop',
                    $this->getStoredExternalId($order, $shipment)
                );
                if ($this->isCancelledRemoteShipment($remoteShipment)) {
                    if (!$this->shipmentRepository->markCancelled($order->id)) {
                        throw new PrestaShopException('The AWB is cancelled in Frisbo, but its local status could not be saved.');
                    }

                    return array('uid' => (string) $shipment['shipment_uid'], 'status' => 'cancelled');
                }
            } catch (PrestaShopException $reconciliationException) {
                throw $reconciliationException;
            } catch (Exception $reconciliationException) {
                // Preserve the original Frisbo cancellation error when the
                // reconciliation lookup itself is unavailable.
            }

            throw $exception;
        }
        if ((string) $result['uid'] !== (string) $shipment['shipment_uid']) {
            throw new PrestaShopException('Frisbo returned a different shipment UID during cancellation.');
        }
        if (!$this->shipmentRepository->markCancelled($order->id)) {
            throw new PrestaShopException('The AWB was cancelled in Frisbo, but its local status could not be saved.');
        }

        return $result;
    }

    public function buildShipment(Order $order, $backendCarrierId = null, $externalId = null)
    {
        $address = new Address((int) $order->id_address_delivery);
        $customer = new Customer((int) $order->id_customer);
        $country = new Country((int) $address->id_country, (int) $order->id_lang);
        $state = $address->id_state ? new State((int) $address->id_state) : null;
        $currency = new Currency((int) $order->id_currency);

        if (!Validate::isLoadedObject($address)
            || !Validate::isLoadedObject($country)
            || !Validate::isLoadedObject($currency)
        ) {
            throw new PrestaShopException('The order delivery address or currency is invalid.');
        }

        $products = array();
        $weight = 0.0;
        $length = 1.0;
        $width = 1.0;
        $height = 1.0;
        foreach ($order->getProducts() as $orderProduct) {
            $quantity = max(1, (int) $orderProduct['product_quantity']);
            $weight += max(0, (float) $orderProduct['product_weight']) * $quantity;
            $product = new Product((int) $orderProduct['product_id']);
            if (Validate::isLoadedObject($product)) {
                $length = max($length, (float) $product->depth);
                $width = max($width, (float) $product->width);
                $height = max($height, (float) $product->height);
            }
            $products[] = array(
                'sku' => (string) $orderProduct['product_reference'],
                'name' => (string) $orderProduct['product_name'],
                'quantity' => $quantity,
                'unit_price' => (float) $orderProduct['unit_price_tax_incl'],
            );
        }

        $companyOrPerson = trim((string) $address->company);
        $contactName = trim($address->firstname.' '.$address->lastname);
        $phone = trim($address->phone_mobile ? $address->phone_mobile : $address->phone);
        $areaLevel1 = ($state && Validate::isLoadedObject($state)) ? $state->name : $country->name;
        $areaLevel2 = trim((string) $address->city);

        $selectedCourier = $this->resolveCourier($backendCarrierId);
        $isCod = $this->codDetector->isCod($order);

        return array(
            'external' => array(
                'platform' => 'prestashop',
                'id' => $externalId === null ? (string) $order->id : (string) $externalId,
            ),
            'courier' => array(
                'id' => $selectedCourier['id'],
                'name' => $selectedCourier['name'],
            ),
            'cod' => array(
                'enabled' => $isCod,
                'amount' => $isCod ? $this->codDetector->getAmount($order) : null,
                'currency' => (string) $currency->iso_code,
            ),
            'recipient' => array(
                'name' => $companyOrPerson !== '' ? $companyOrPerson : $contactName,
                'contact_name' => $contactName,
                'phone_number' => $phone,
                'email' => Validate::isLoadedObject($customer) ? (string) $customer->email : '',
                'country_code' => strtoupper((string) $country->iso_code),
                'administrative_area_level_1' => (string) $areaLevel1,
                'administrative_area_level_2' => $areaLevel2 !== '' ? $areaLevel2 : (string) $areaLevel1,
                'postal_code' => trim((string) $address->postcode) !== '' ? (string) $address->postcode : '000000',
                'address_line1' => (string) $address->address1,
                'address_line2' => (string) $address->address2,
            ),
            'products' => $products,
            'parcels' => array(array(
                'weight' => max(0.01, $weight),
                'length' => max(1, $length),
                'width' => max(1, $width),
                'height' => max(1, $height),
            )),
            'totals' => array(
                'declared_value' => (float) $order->total_paid_tax_incl,
                'shipping' => (float) $order->total_shipping_tax_incl,
            ),
            'notes' => 'Order reference: '.(string) $order->reference,
        );
    }

    private function getStoredExternalId(Order $order, array $shipment = null)
    {
        $prefix = 'prestashop:';
        if ($shipment
            && isset($shipment['external_reference'])
            && strpos((string) $shipment['external_reference'], $prefix) === 0
        ) {
            return Tools::substr((string) $shipment['external_reference'], Tools::strlen($prefix));
        }

        return (string) $order->id;
    }

    private function getGenerationExternalId(Order $order, array $shipment = null)
    {
        if (!$shipment || $shipment['status'] !== 'cancelled') {
            return $this->getStoredExternalId($order, $shipment);
        }

        $current = $this->getStoredExternalId($order, $shipment);
        $orderId = (string) $order->id;
        if ($current === $orderId) {
            return $orderId.':2';
        }
        if (preg_match('/^'.preg_quote($orderId, '/').':([2-9][0-9]*)$/', $current, $matches)) {
            return $orderId.':'.((int) $matches[1] + 1);
        }

        throw new PrestaShopException('The stored Frisbo external reference is invalid.');
    }

    private function isCancelledRemoteShipment(array $shipment)
    {
        return (isset($shipment['status']) && (string) $shipment['status'] === 'cancelled')
            || (isset($shipment['cancellation_status'])
                && (string) $shipment['cancellation_status'] === 'cancelled');
    }

    private function resolveCourier($backendCarrierId)
    {
        $backendCarrierId = trim((string) $backendCarrierId);
        if ($backendCarrierId === '' || $backendCarrierId === 'auto') {
            return array('id' => null, 'name' => 'Auto');
        }

        $courier = $this->carrierRepository->findByBackendId($backendCarrierId);
        if (!$courier) {
            throw new PrestaShopException('The selected Frisbo courier is not available for this shop.');
        }

        $name = trim((string) $courier['backend_name']);

        return array(
            'id' => (string) $courier['backend_carrier_id'],
            'name' => $name !== '' ? $name : (string) $courier['backend_carrier_id'],
        );
    }

    private function synchronizeTracking(Order $order, array $shipment)
    {
        $this->synchronizeCarrier($order, isset($shipment['courier_id']) ? $shipment['courier_id'] : null);

        $trackingNumber = empty($shipment['tracking_number'])
            ? null
            : Tools::substr((string) $shipment['tracking_number'], 0, 191);
        $trackingUrl = empty($shipment['tracking_url'])
            ? null
            : Tools::substr((string) $shipment['tracking_url'], 0, 2048);

        if (!$this->shipmentRepository->updateTracking($order->id, $trackingNumber, $trackingUrl)) {
            throw new PrestaShopException('Could not save the Frisbo tracking details.');
        }
        if ($trackingNumber === null) {
            return;
        }

        $nativeTrackingNumber = Tools::substr($trackingNumber, 0, 64);
        if (!Validate::isTrackingNumber($nativeTrackingNumber)) {
            return;
        }

        $idOrderCarrier = (int) $order->getIdOrderCarrier();
        if ($idOrderCarrier) {
            $orderCarrier = new OrderCarrier($idOrderCarrier);
            if (Validate::isLoadedObject($orderCarrier)) {
                $orderCarrier->tracking_number = $nativeTrackingNumber;
                if (!$orderCarrier->update()) {
                    throw new PrestaShopException('Could not save the tracking number on the PrestaShop order carrier.');
                }
            }
        }

        $nativeShipmentTable = _DB_PREFIX_.'shipment';
        $hasNativeShipmentTable = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = \''.pSQL($nativeShipmentTable).'\''
        );
        if ($hasNativeShipmentTable) {
            Db::getInstance()->update(
                'shipment',
                array('tracking_number' => pSQL($nativeTrackingNumber)),
                '`id_order` = '.(int) $order->id
            );
        }
    }

    private function synchronizeCarrier(Order $order, $backendCarrierId)
    {
        $backendCarrierId = trim((string) $backendCarrierId);
        if ($backendCarrierId === '') {
            return;
        }

        $configuredCarrier = $this->carrierRepository->findByBackendId($backendCarrierId);
        if (!$configuredCarrier) {
            return;
        }

        $carrier = empty($configuredCarrier['id_carrier'])
            ? false
            : new Carrier((int) $configuredCarrier['id_carrier']);
        if (!$carrier || !Validate::isLoadedObject($carrier) || $carrier->deleted) {
            $carrierSync = new CarrierSyncService(
                $this->carrierRepository,
                (int) $order->id_lang,
                (int) $order->id_shop
            );
            $carrier = $carrierSync->ensureHiddenCarrierForBackendId($backendCarrierId);
        }
        $idCarrier = (int) $carrier->id;

        $db = Db::getInstance();
        $db->execute('START TRANSACTION');
        try {
            if (!$db->update(
                'orders',
                array('id_carrier' => $idCarrier),
                '`id_order` = '.(int) $order->id.' AND `id_shop` = '.(int) $order->id_shop
            ) || !$db->update(
                'order_carrier',
                array('id_carrier' => $idCarrier),
                '`id_order` = '.(int) $order->id
            )) {
                throw new PrestaShopException('Could not save the Frisbo courier on the PrestaShop shipment.');
            }
            $db->execute('COMMIT');
            $order->id_carrier = $idCarrier;
        } catch (Exception $exception) {
            $db->execute('ROLLBACK');
            throw $exception;
        }
    }
}
