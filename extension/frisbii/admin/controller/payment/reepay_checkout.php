<?php

namespace Opencart\Admin\Controller\Extension\Frisbii\Payment;

class ReepayCheckout extends \Opencart\System\Engine\Controller {

    public function index(): void {
        $this->load->language('extension/frisbii/payment/reepay_checkout');

        $this->document->setTitle($this->language->get('heading_title'));

        $data['breadcrumbs'] = [];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment')
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/frisbii/payment/reepay_checkout', 'user_token=' . $this->session->data['user_token'])
        ];

        $data['save'] = $this->url->link('extension/frisbii/payment/reepay_checkout.save', 'user_token=' . $this->session->data['user_token']);
        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');

        $fields = [
            'payment_reepay_checkout_total',
            'payment_reepay_checkout_method_title',
            'payment_reepay_checkout_status',
            'payment_reepay_checkout_sort_order',
            'payment_reepay_checkout_geo_zone_id',
            'payment_reepay_checkout_order_status_id',
            'payment_reepay_checkout_private_key_live',
            'payment_reepay_checkout_private_key_test',
            'payment_reepay_checkout_checkout_type',
            'payment_reepay_checkout_order_lines',
            'payment_reepay_checkout_test',
            'payment_reepay_checkout_instant_settle',
            'payment_reepay_checkout_debug',
            'payment_reepay_checkout_payment_methods',
            'payment_reepay_checkout_payment_logos',
        ];

        foreach ($fields as $field) {
            $data[$field] = $this->request->post[$field] ?? $this->config->get($field);
        }

        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $data['payment_types'] = [
            ['id' => 'window',  'name' => 'Window'],
            ['id' => 'overlay', 'name' => 'Overlay'],
        ];

        $data['yes_no_options'] = [
            ['option' => 1, 'name' => 'Yes'],
            ['option' => 0, 'name' => 'No'],
        ];

        $data['payment_methods'] = [
            'card'             => 'All available debit / credit cards',
            'dankort'          => 'Dankort',
            'visa'             => 'VISA',
            'anyday'           => 'Anyday',
            'visa_elec'        => 'VISA Electron',
            'mc'               => 'MasterCard',
            'mobilepay'        => 'MobilePay',
            'viabill'          => 'ViaBill',
            'swish'            => 'Swish',
            'vipps'            => 'Vipps',
            'diners'           => 'Diners Club',
            'maestro'          => 'Maestro',
            'discover'         => 'Discover',
            'jcb'              => 'JCB',
            'ffk'              => 'Forbrugsforeningen',
            'paypal'           => 'PayPal',
            'applepay'         => 'Apple Pay',
            'googlepay'        => 'Google Pay',
            'klarna_pay_later' => 'Klarna Pay Later',
            'klarna_pay_now'   => 'Klarna Pay Now',
            'klarna_slice_it'  => 'Klarna Slice It!',
        ];

        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/frisbii/payment/reepay_checkout', $data));
    }

    public function save(): void {
        $this->load->language('extension/frisbii/payment/reepay_checkout');

        $json = [];

        if (!$this->user->hasPermission('modify', 'extension/frisbii/payment/reepay_checkout')) {
            $json['error']['warning'] = $this->language->get('error_permission');
        } else {
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting('payment_reepay_checkout', $this->request->post);
            $json['success'] = $this->language->get('text_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function order(): string {
        $data['user_token'] = $this->session->data['user_token'];
        $data['order_id']   = $this->request->get['order_id'];

        $res    = $this->getOrderPaymentCustomField($this->request->get['order_id']);
        $result = isset($res->row['payment_custom_field'])
            ? json_decode($res->row['payment_custom_field'], true)
            : null;

        if ($result && isset($result['invoice_id']) && $result['invoice_id']) {
            $data['order_id'] = $result['invoice_id'];
        }

        return $this->load->view('extension/frisbii/payment/reepay_checkout_order', $data);
    }

    public function control(): void {
        $this->load->language('extension/frisbii/payment/reepay_checkout');
        $this->load->model('extension/frisbii/payment/reepay_checkout');

        $result         = $this->model_extension_frisbii_payment_reepay_checkout->getInvoice($this->request->get['order_id']);
        $result_decoded = json_decode($result, true);

        if ('success' !== ($result_decoded['status'] ?? '')) {
            return;
        }

        $data = $result_decoded['body'];

        array_walk($data['transactions'], function (&$item) {
            $item['amount'] = $this->formatAmount($item['amount']);
        });

        $amount_authorized = $data['authorized_amount'] ?? 0;

        if ('cancelled' === $data['state']) {
            $zero                    = $this->formatAmount(0);
            $data['amount_to_capture'] = $zero;
            $data['amount_to_refund']  = $zero;
        } else {
            $amount_to_capture = $amount_authorized - $data['settled_amount'];
            $data['amount_to_capture'] = $this->formatAmount(max(0, $amount_to_capture));
            $data['amount_to_refund']  = $this->formatAmount($data['settled_amount'] - $data['refunded_amount']);
        }

        $data['authorized_amount'] = $this->formatAmount($amount_authorized);
        $data['settled_amount']    = $this->formatAmount($data['settled_amount']);
        $data['refunded_amount']   = $this->formatAmount($data['refunded_amount']);
        $data['user_token']        = $this->session->data['user_token'];
        $data['order_id']          = $this->request->get['order_id'];

        $payment_type = $data['transactions'][0]['payment_type'] ?? '';
        $data['payment_type'] = $payment_type;

        $data['card_type']   = $data['transactions'][0][$payment_type . '_transaction']['card_type']   ?? null;
        $data['exp_date']    = $data['transactions'][0][$payment_type . '_transaction']['exp_date']    ?? null;
        $data['masked_card'] = $data['transactions'][0][$payment_type . '_transaction']['masked_card'] ?? null;

        $this->response->setOutput($this->load->view('extension/frisbii/payment/reepay_checkout_order_control', $data));
    }

    public function charge_settle(): void {
        $this->load->model('extension/frisbii/payment/reepay_checkout');
        $this->response->setOutput(
            $this->model_extension_frisbii_payment_reepay_checkout->settleCharge($this->request->post['handle'], $this->request->post['amount'])
        );
    }

    public function void_charge(): void {
        $this->load->model('extension/frisbii/payment/reepay_checkout');
        $this->response->setOutput(
            $this->model_extension_frisbii_payment_reepay_checkout->voidCharge($this->request->post['handle'], $this->request->post['amount'])
        );
    }

    public function refund_charge(): void {
        $this->load->model('extension/frisbii/payment/reepay_checkout');
        $this->response->setOutput(
            $this->model_extension_frisbii_payment_reepay_checkout->refundCharge($this->request->post['handle'], $this->request->post['amount'])
        );
    }

    public function install(): void {}

    public function uninstall(): void {}

    protected function getOrderPaymentCustomField(string $order_id): object {
        return $this->db->query("SELECT `payment_custom_field` FROM `" . DB_PREFIX . "order` WHERE order_id = '" . (int)$order_id . "'");
    }

    protected function formatAmount(int $amount): string {
        return number_format($amount / 100, 2, '.', '');
    }
}
