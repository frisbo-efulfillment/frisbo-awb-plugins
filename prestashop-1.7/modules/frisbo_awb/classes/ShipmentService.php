<?php

class ShipmentService
{
    private $clientFactory;
    private $orderRepository;
    private $shipmentRepository;

    public function __construct(
        FrisboClientFactory $clientFactory,
        OrderRepository $orderRepository,
        ShipmentRepository $shipmentRepository
    ) {
        $this->clientFactory = $clientFactory;
        $this->orderRepository = $orderRepository;
        $this->shipmentRepository = $shipmentRepository;
    }

    public function getLabel(Order $order)
    {
        $savedOrder = $this->orderRepository->findByOrderId($order->id);
        if (!$savedOrder) {
            throw new PrestaShopException('This order does not have a Frisbo courier snapshot.');
        }

        $client = $this->clientFactory->create();
        $shipmentRecord = $this->shipmentRepository->findByOrderId($order->id);

        if (!$shipmentRecord || $shipmentRecord['status'] !== 'generated') {
            if ($shipmentRecord && $shipmentRecord['status'] === 'creating') {
                throw new PrestaShopException('A Frisbo shipment is already being generated for this order.');
            }
            if ($shipmentRecord && $shipmentRecord['status'] === 'uncertain') {
                throw new PrestaShopException('Frisbo reported an uncertain shipment result. Reconcile it before retrying.');
            }

            $payload = $this->buildShipment($order, $savedOrder);
            if (!$this->shipmentRepository->begin(
                $order->id,
                'prestashop:'.$order->id,
                'prestashop:'.$order->id
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
        }

        return $client->downloadLabelByExternalId('prestashop', (string) $order->id);
    }

    public function buildShipment(Order $order, array $savedOrder)
    {
        $address = new Address((int) $order->id_address_delivery);
        $customer = new Customer((int) $order->id_customer);
        $country = new Country((int) $address->id_country, (int) $order->id_lang);
        $state = $address->id_state ? new State((int) $address->id_state) : null;

        if (!Validate::isLoadedObject($address) || !Validate::isLoadedObject($country)) {
            throw new PrestaShopException('The delivery address is invalid.');
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

        return array(
            'external' => array(
                'platform' => 'prestashop',
                'id' => (string) $order->id,
            ),
            'courier' => array(
                'id' => $savedOrder['backend_carrier_id'],
                'name' => $savedOrder['courier_name'],
            ),
            'cod' => array(
                'enabled' => (bool) $savedOrder['is_cod'],
                'amount' => $savedOrder['cod_amount'] === null ? null : (float) $savedOrder['cod_amount'],
                'currency' => $savedOrder['currency_iso'],
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
                'shipping' => (float) $savedOrder['shipping_price'],
            ),
            'notes' => 'Order reference: '.(string) $order->reference,
        );
    }
}
