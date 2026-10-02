<?php

require_once dirname(__FILE__).'/../../frisbo_awb.php';

class AdminFrisboAwbController extends ModuleAdminController
{
    public function postProcess()
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'POST') {
            $this->renderError('Only POST AWB requests are accepted.', 405);
        }
        if (!$this->access('edit')) {
            $this->renderError('You do not have permission to manage an AWB.', 403);
        }

        $order = new Order((int) Tools::getValue('id_order'));
        if (!Validate::isLoadedObject($order)) {
            $this->renderError('Order not found.', 404);
        }

        try {
            $action = (string) Tools::getValue('frisbo_awb_operation');
            if (!in_array($action, array('generate', 'download', 'cancel', 'status'), true)) {
                $this->renderError('Invalid AWB operation.', 400);
            }

            $service = $this->module->getShipmentService((int) $order->id_shop);
            if ($action === 'status') {
                $this->renderJson(array(
                    'success' => true,
                    'status' => $service->getShipmentStatus($order),
                ), 200);
            }
            if ($action === 'download' && !$service->hasGeneratedShipment($order)) {
                $this->renderError('No generated Frisbo AWB is available for this order.', 404);
            }
            if ($action === 'cancel') {
                $service->cancelShipment($order);
                if (Tools::getValue('frisbo_awb_ajax')) {
                    $this->renderJson(array('success' => true, 'message' => 'The Frisbo AWB was cancelled successfully.'), 200);
                }
                $this->renderSuccess('The Frisbo AWB was cancelled successfully.');
            }

            $backendCarrierId = $action === 'generate'
                ? trim((string) Tools::getValue('frisbo_awb_courier_id'))
                : null;
            $label = $service->getLabel($order, $backendCarrierId);
            while (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Type: '.$label['content_type']);
            header('Content-Disposition: inline; filename="'.str_replace('"', '', $label['filename']).'"');
            header('Content-Length: '.strlen($label['content']));
            header('X-Content-Type-Options: nosniff');
            echo $label['content'];
            exit;
        } catch (Exception $exception) {
            if (Tools::getValue('frisbo_awb_ajax')) {
                $this->renderJson(array('success' => false, 'message' => $exception->getMessage()), 400);
            }
            $this->renderError($exception->getMessage(), 400);
        }
    }

    private function renderError($message, $status)
    {
        http_response_code((int) $status);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Frisbo AWB</title></head><body>';
        echo '<h1>Frisbo AWB</h1><p>'.htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8').'</p>';
        echo '</body></html>';
        exit;
    }

    private function renderSuccess($message)
    {
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Frisbo AWB</title></head><body>';
        echo '<h1>Frisbo AWB</h1><p>'.htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8').'</p>';
        echo '<p><button type="button" onclick="window.close()">Close</button></p>';
        echo '</body></html>';
        exit;
    }

    private function renderJson(array $payload, $status)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
}
