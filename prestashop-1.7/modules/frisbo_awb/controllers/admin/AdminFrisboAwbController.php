<?php

require_once dirname(__FILE__).'/../../frisbo_awb.php';

class AdminFrisboAwbController extends ModuleAdminController
{
    public function postProcess()
    {
        if (!Tools::isSubmit('submitFrisboAwb')) {
            $this->renderError('Only POST AWB requests are accepted.', 405);
        }
        if (!$this->access('edit')) {
            $this->renderError('You do not have permission to generate an AWB.', 403);
        }

        $order = new Order((int) Tools::getValue('id_order'));
        if (!Validate::isLoadedObject($order)) {
            $this->renderError('Order not found.', 404);
        }

        try {
            $label = $this->module->getShipmentService((int) $order->id_shop)->getLabel($order);
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
}
