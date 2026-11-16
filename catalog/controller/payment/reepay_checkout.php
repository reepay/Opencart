<?php

namespace Opencart\Catalog\Controller\Extension\Frisbii\Payment;

class ReepayCheckout extends \Opencart\System\Engine\Controller {

    public function index(): string {
        $this->load->language('extension/frisbii/payment/reepay_checkout');
        $data['checkout_url']   = $this->url->link('checkout/checkout', '', true);
        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['text_loading']   = $this->language->get('text_loading');
        return $this->load->view('extension/frisbii/payment/reepay_checkout', $data);
    }

    public function confirm(): void {
        $this->load->model('extension/frisbii/payment/reepay_checkout');

        $charge_session_result = $this->model_extension_frisbii_payment_reepay_checkout->getChargeSession();

        if ('overlay' == $this->config->get('payment_reepay_checkout_checkout_type')) {
            $charge_session_result_arr = json_decode($charge_session_result, true);

            $this->session->data['payment_method']['reepay']['charge_session_id'] =
                isset($charge_session_result_arr['body']['id']) ? $charge_session_result_arr['body']['id'] : null;

            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode([
                'status' => $charge_session_result_arr['status'],
                'body'   => ['url' => $this->url->link('extension/frisbii/payment/reepay_checkout.initOverlay', '', true)],
            ]));
        } else {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput($charge_session_result);
        }
    }

    public function accept(): void {
        $invoice = (string)($this->request->get['invoice'] ?? '');

        // Handle must be exactly "<order_id>" or "<order_id>-<timestamp>" (see getChargeSession()),
        // so it can never carry path-traversal or other characters into the API URL.
        if (!preg_match('/^(\d+)(?:-\d+)?$/', $invoice, $invoice_match)) {
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
            return;
        }

        $order_id = (int)$invoice_match[1];

        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);

        if (empty($order_info)) {
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
            return;
        }

        $this->load->model('extension/frisbii/payment/reepay_checkout');
        $result = $this->model_extension_frisbii_payment_reepay_checkout->getInvoice($invoice);

        if ('success' == ($result['status'] ?? null)) {
            // The handle returned by the API must be the exact handle we asked for, so a
            // settled invoice for a different order can never confirm this order.
            if (($result['body']['handle'] ?? null) !== $invoice) {
                $this->response->redirect($this->url->link('checkout/checkout', '', true));
                return;
            }

            if (!in_array($result['body']['state'] ?? null, ['authorized', 'settled'])) {
                $this->response->redirect($this->url->link('checkout/checkout', '', true));
                return;
            }

            // The invoice must have been paid for exactly what the order costs now: the order
            // can be edited (cart changed, checkout re-confirmed) while an older, cheaper
            // payment session for the same handle is still payable.
            if (!$this->model_extension_frisbii_payment_reepay_checkout->invoiceMatchesOrder($order_info, $result['body'])) {
                $this->response->redirect($this->url->link('checkout/checkout', '', true));
                return;
            }
        } else {
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
            return;
        }

        if (strstr($invoice, '-')) {
            $this->model_extension_frisbii_payment_reepay_checkout->mergePaymentField($order_id, ['invoice_id' => $invoice]);
        }

        $this->model_checkout_order->addHistory($order_info['order_id'], (int)$this->config->get('payment_reepay_checkout_order_status_id'));

        $this->response->redirect($this->url->link('checkout/success', '', true));
    }

    public function webhook(): void {
        // Fix #3: reject non-POST
        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->response->addHeader('HTTP/1.1 400 Bad Request');
            $this->response->setOutput('Bad Request');
            return;
        }

        $raw = file_get_contents('php://input');

        // Fix #1: empty body → 400
        if (empty($raw)) {
            $this->response->addHeader('HTTP/1.1 400 Bad Request');
            $this->response->setOutput('Bad Request');
            return;
        }

        $payload = json_decode($raw, true);

        // Fix #1: malformed JSON or missing/mistyped required fields → 400.
        // `invoice` is not required here: events without an invoice are of an unhandled type and
        // are answered with 200 below; the invoice handlers validate the handle themselves.
        if (!is_array($payload)
            || empty($payload['timestamp']) || !(is_string($payload['timestamp']) || is_int($payload['timestamp']))
            || empty($payload['id']) || !(is_string($payload['id']) || is_int($payload['id']))
            || empty($payload['signature']) || !is_string($payload['signature'])
            || empty($payload['event_type']) || !is_string($payload['event_type'])
        ) {
            $this->response->addHeader('HTTP/1.1 400 Bad Request');
            $this->response->setOutput('Bad Request');
            return;
        }

        $this->load->model('extension/frisbii/payment/reepay_checkout');
        $model = $this->model_extension_frisbii_payment_reepay_checkout;

        // Fix #1: bad/unverifiable signature or timestamp outside the window → 401 (logged by the model)
        if (!$model->verifyWebhookSignature($payload)) {
            $this->response->addHeader('HTTP/1.1 401 Unauthorized');
            $this->response->setOutput('Unauthorized');
            return;
        }

        // Process first, answer afterwards: the status code depends on the outcome.
        // 200 = applied, duplicate, unhandled type or definitively rejected by the API check.
        // 503 = could not be decided right now (invoice lookup failed, order lock not acquired);
        //       nothing was changed or stored, and Frisbii delivers the webhook again.
        $outcome = $model->processWebhook($payload);

        // ($model is an OpenCart proxy object, so the constant is read from the model class itself.)
        if ($outcome === \Opencart\Catalog\Model\Extension\Frisbii\Payment\ReepayCheckout::WEBHOOK_RETRY) {
            $this->response->addHeader('HTTP/1.1 503 Service Unavailable');
            $this->response->addHeader('Retry-After: 120');
            $this->response->setOutput('Service Unavailable');
            return;
        }

        $this->response->addHeader('HTTP/1.1 200 OK');
        $this->response->setOutput('OK');
    }

    public function cancel(): void {
        $this->response->redirect($this->url->link('checkout/checkout', '', true));
    }

    public function initOverlay(): void {
        if ('overlay' !== $this->config->get('payment_reepay_checkout_checkout_type')) {
            $this->response->redirect($this->url->link('checkout/cart', '', true));
            return;
        }

        $session_id = $this->session->data['payment_method']['reepay']['charge_session_id'] ?? null;

        $this->response->setOutput($this->load->view('extension/frisbii/payment/reepay_checkout_overlay', [
            'charge_session_id' => $session_id,
            'accept_url'        => $this->url->link('extension/frisbii/payment/reepay_checkout.accept', '', true),
            'cancel_url'        => $this->url->link('extension/frisbii/payment/reepay_checkout.cancel', '', true),
        ]));
    }

    protected function updatePaymentCustomField(string $data, int|string $order_id): void {
        $this->db->query("UPDATE `" . DB_PREFIX . "order` SET `payment_custom_field` = '" . $this->db->escape($data) . "' WHERE order_id = '" . (int)$order_id . "'");
    }
}
