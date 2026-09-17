<?php

class FrisboClient
{
    private $clientId;
    private $token;
    private $baseUrl;

    public function __construct($clientId, $token)
    {
        $this->clientId = (string) $clientId;
        $this->token = (string) $token;

        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', $this->clientId)) {
            throw new FrisboApiException('The Frisbo Client ID is invalid.');
        }

        $tenantHostnameId = str_replace('-', '', $this->clientId);
        $this->baseUrl = 'https://tenant-'.$tenantHostnameId.'.frisbo.workers.dev';
    }

    public function getCouriers()
    {
        $response = $this->requestJson('GET', '/api/couriers');
        if (!is_array($response)) {
            throw new FrisboApiException('Frisbo returned an invalid courier list.');
        }

        $couriers = array();
        foreach ($response as $courier) {
            if (!is_array($courier) || !isset($courier['id'], $courier['name'])) {
                throw new FrisboApiException('Frisbo returned an invalid courier record.');
            }

            $couriers[] = array(
                'id' => (string) $courier['id'],
                'name' => (string) $courier['name'],
            );
        }

        return $couriers;
    }

    public function generateShipment(array $shipment)
    {
        $payload = $this->mapShipment($shipment);
        $response = $this->requestJson('POST', '/api/shipments', $payload, array(201));

        if (!is_array($response) || empty($response['uid']) || empty($response['external_reference'])) {
            throw new FrisboApiException('Frisbo returned an invalid shipment response.');
        }

        return array(
            'uid' => (string) $response['uid'],
            'external_reference' => (string) $response['external_reference'],
        );
    }

    public function downloadLabelByExternalId($platform, $externalId)
    {
        $externalReference = $this->buildExternalReference($platform, $externalId);
        $shipments = $this->requestJson(
            'GET',
            '/api/shipments?external_reference='.rawurlencode($externalReference)
        );

        if (!is_array($shipments) || count($shipments) < 1) {
            throw new FrisboApiException('No Frisbo shipment was found for this order.', 404);
        }

        $shipment = null;
        foreach ($shipments as $candidate) {
            if (is_array($candidate)
                && isset($candidate['external_reference'])
                && (string) $candidate['external_reference'] === $externalReference
            ) {
                $shipment = $candidate;
                break;
            }
        }

        if ($shipment === null || empty($shipment['documents']) || !is_array($shipment['documents'])) {
            throw new FrisboApiException('The Frisbo shipment does not have a label yet.', 404);
        }

        $document = reset($shipment['documents']);
        if (!is_array($document) || empty($document['download_url'])) {
            throw new FrisboApiException('Frisbo returned an invalid label document.', 404);
        }

        $downloadUrl = $this->resolveDocumentUrl((string) $document['download_url']);
        $response = $this->request('GET', $downloadUrl, null, array(200), true);

        $filename = 'frisbo-awb-'.preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $externalId).'.pdf';
        if (!empty($response['content_disposition'])
            && preg_match('/filename\*?=(?:UTF-8\'\')?["\']?([^"\';]+)/i', $response['content_disposition'], $matches)
        ) {
            $candidate = basename(rawurldecode($matches[1]));
            if ($candidate !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $candidate)) {
                $filename = $candidate;
            }
        }

        return array(
            'content' => $response['body'],
            'content_type' => $response['content_type'] ?: 'application/pdf',
            'filename' => $filename,
        );
    }

    private function mapShipment(array $shipment)
    {
        foreach (array('external', 'courier', 'recipient', 'cod', 'parcels') as $required) {
            if (!isset($shipment[$required]) || !is_array($shipment[$required])) {
                throw new FrisboApiException('The normalized shipment is missing '.$required.'.');
            }
        }

        $recipient = $shipment['recipient'];
        $payload = array(
            'external_reference' => $this->buildExternalReference(
                $shipment['external']['platform'],
                $shipment['external']['id']
            ),
            'courier_id' => (string) $shipment['courier']['id'],
            'address_to' => array(
                'name' => (string) $recipient['name'],
                'contact_name' => (string) $recipient['contact_name'],
                'phone_number' => (string) $recipient['phone_number'],
                'country_code' => (string) $recipient['country_code'],
                'administrative_area_level_1' => (string) $recipient['administrative_area_level_1'],
                'administrative_area_level_2' => (string) $recipient['administrative_area_level_2'],
                'postal_code' => (string) $recipient['postal_code'],
                'address_line1' => (string) $recipient['address_line1'],
            ),
            'payment' => array(
                'currency' => (string) $shipment['cod']['currency'],
                'cash_on_delivery' => (bool) $shipment['cod']['enabled'],
            ),
            'parcels' => array(),
            'shipment_notes' => $this->buildShipmentNotes($shipment),
        );

        if (!empty($recipient['email'])) {
            $payload['address_to']['email'] = (string) $recipient['email'];
        }
        if (!empty($recipient['address_line2'])) {
            $payload['address_to']['address_line2'] = (string) $recipient['address_line2'];
        }
        if (!empty($recipient['locker_pudo_id'])) {
            $payload['address_to']['locker_pudo_id'] = (string) $recipient['locker_pudo_id'];
        }
        if ($shipment['cod']['enabled']) {
            $payload['payment']['cash_on_delivery_value'] = (float) $shipment['cod']['amount'];
        }
        if (isset($shipment['totals']['declared_value'])) {
            $payload['payment']['declared_value'] = max(0, (float) $shipment['totals']['declared_value']);
        }

        foreach ($shipment['parcels'] as $parcel) {
            $payload['parcels'][] = array(
                'weight' => max(0.01, (float) $parcel['weight']),
                'length' => max(0.01, (float) $parcel['length']),
                'width' => max(0.01, (float) $parcel['width']),
                'height' => max(0.01, (float) $parcel['height']),
            );
        }

        if (empty($payload['parcels'])) {
            throw new FrisboApiException('At least one parcel is required.');
        }

        return $payload;
    }

    private function buildShipmentNotes(array $shipment)
    {
        $notes = array(
            'PrestaShop order: '.(string) $shipment['external']['id'],
            'Courier name: '.(string) $shipment['courier']['name'],
        );

        if (!empty($shipment['notes'])) {
            $notes[] = (string) $shipment['notes'];
        }

        return trim(preg_replace('/[\r\n]+/', ' | ', implode(' | ', $notes)));
    }

    private function buildExternalReference($platform, $externalId)
    {
        $platform = strtolower(trim((string) $platform));
        $externalId = trim((string) $externalId);
        if (!preg_match('/^[a-z0-9_-]+$/', $platform) || $externalId === '') {
            throw new FrisboApiException('The external shipment identity is invalid.');
        }

        $reference = $platform.':'.$externalId;
        if (strlen($reference) > 200) {
            throw new FrisboApiException('The external shipment identity is too long.');
        }

        return $reference;
    }

    private function resolveDocumentUrl($url)
    {
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            return $this->baseUrl.$url;
        }

        $baseHost = parse_url($this->baseUrl, PHP_URL_HOST);
        $urlHost = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'https' || $urlHost !== $baseHost) {
            throw new FrisboApiException('Frisbo returned an unsafe label URL.');
        }

        return $url;
    }

    private function requestJson($method, $path, ?array $payload = null, array $expectedStatuses = array(200))
    {
        $response = $this->request($method, $this->baseUrl.$path, $payload, $expectedStatuses, false);
        $decoded = json_decode($response['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new FrisboApiException('Frisbo returned invalid JSON.', $response['status']);
        }

        return $decoded;
    }

    private function request($method, $url, ?array $payload = null, array $expectedStatuses = array(200), $binary = false)
    {
        $headers = array(
            'Accept: '.($binary ? 'application/pdf, application/octet-stream' : 'application/json'),
            'Authorization: Bearer '.$this->token,
        );
        $body = null;
        if ($payload !== null) {
            $body = json_encode($payload);
            if ($body === false) {
                throw new FrisboApiException('Could not encode the Frisbo request.');
            }
            $headers[] = 'Content-Type: application/json';
        }

        $responseHeaders = array();
        $handle = curl_init($url);
        curl_setopt_array($handle, array(
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) {
                $length = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $length;
            },
        ));

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $message = curl_error($handle);
            curl_close($handle);
            throw new FrisboApiException('Frisbo request failed: '.$message);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        curl_close($handle);

        if (!in_array($status, $expectedStatuses, true)) {
            throw new FrisboApiException($this->formatApiError($status, $responseBody), $status);
        }

        return array(
            'status' => $status,
            'body' => $responseBody,
            'content_type' => $contentType,
            'content_disposition' => isset($responseHeaders['content-disposition']) ? $responseHeaders['content-disposition'] : '',
        );
    }

    private function formatApiError($status, $body)
    {
        $message = '';
        $responseBody = trim((string) $body);
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            foreach (array('message', 'error', 'detail') as $key) {
                if (isset($decoded[$key]) && is_scalar($decoded[$key])) {
                    $message = (string) $decoded[$key];
                    break;
                }
            }
        }

        if ((int) $status === 422 && $responseBody !== '') {
            $message = $responseBody;
        }

        return 'Frisbo API returned HTTP '.(int) $status.($message !== '' ? ': '.$message : '');
    }
}
