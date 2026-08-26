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
        $invoice_id_arr = explode('-', $this->request->get['invoice'] ?? '');
        $order_id       = $invoice_id_arr[0] ?? null;

        if (!isset($order_id)) {
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
            return;
        }

        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);

        if (empty($order_info)) {
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
            return;
        }

        $this->load->model('extension/frisbii/payment/reepay_checkout');
        $result = $this->model_extension_frisbii_payment_reepay_checkout->getInvoice($this->request->get['invoice']);

        if ('success' == $result['status']) {
            if (!in_array($result['body']['state'], ['authorized', 'settled'])) {
                $this->response->redirect($this->url->link('checkout/checkout', '', true));
                return;
            }
        } else {
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
            return;
        }

        if (strstr($this->request->get['invoice'], '-')) {
            $this->updatePaymentCustomField(json_encode(['invoice_id' => $this->request->get['invoice']]), $order_id);
        }

        $this->model_checkout_order->addHistory($order_info['order_id'], (int)$this->config->get('payment_reepay_checkout_order_status_id'));

        $this->response->redirect($this->url->link('checkout/success', '', true));
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
