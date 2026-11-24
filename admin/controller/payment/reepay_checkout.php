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
            'payment_reepay_checkout_order_status_authorized_id',
            'payment_reepay_checkout_order_status_settled_id',
            'payment_reepay_checkout_order_status_cancelled_id',
            'payment_reepay_checkout_order_status_refunded_id',
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
            'klarna'           => 'Klarna',
            'klarna_pay_later' => 'Klarna Pay Later',
            'klarna_pay_now'   => 'Klarna Pay Now',
            'klarna_slice_it'  => 'Klarna Slice It!',
        ];

        $scheme      = (!empty($this->request->server['HTTPS']) && $this->request->server['HTTPS'] !== 'off') ? 'https' : 'http';
        $host        = $this->request->server['HTTP_HOST'];
        $admin_path  = $this->request->server['SCRIPT_NAME']; // e.g. /opencart_frisbii/admin/index.php
        $catalog_dir = rtrim(dirname(dirname($admin_path)), '/') . '/'; // e.g. /opencart_frisbii/
        $data['webhook_url'] = $scheme . '://' . $host . $catalog_dir . 'index.php?route=extension/frisbii/payment/reepay_checkout.webhook';

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
            // Fix #2: invalidate cached webhook secret when the API key changes
            $old_live = trim((string)$this->config->get('payment_reepay_checkout_private_key_live'));
            $old_test = trim((string)$this->config->get('payment_reepay_checkout_private_key_test'));
            $new_live = trim((string)($this->request->post['payment_reepay_checkout_private_key_live'] ?? ''));
            $new_test = trim((string)($this->request->post['payment_reepay_checkout_private_key_test'] ?? ''));

            if ($old_live !== $new_live || $old_test !== $new_test) {
                $this->db->query(
                    "DELETE FROM `" . DB_PREFIX . "setting`
                     WHERE `key` IN (
                         'payment_reepay_checkout_webhook_secret',
                         'payment_reepay_checkout_webhook_secret_cached_at'
                     ) AND store_id = '0'"
                );
            }

            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting('payment_reepay_checkout', $this->request->post);
            $json['success'] = $this->language->get('text_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function order(): string {
        $data['user_token'] = $this->session->data['user_token'];
        // Always an integer: this value is printed into the admin order view.
        $order_id         = (int)($this->request->get['order_id'] ?? 0);
        $data['order_id'] = $order_id;

        $res    = $this->getOrderPaymentCustomField((string)$order_id);
        $result = isset($res->row['payment_custom_field'])
            ? json_decode($res->row['payment_custom_field'], true)
            : null;

        // A stored invoice handle is only trusted when it is "<order_id>-<timestamp>" for this
        // very order; anything else (e.g. a value stored before input validation existed) is ignored.
        $invoice_id = is_array($result) && is_string($result['invoice_id'] ?? null) ? $result['invoice_id'] : '';

        if ($this->isValidHandle($invoice_id) && (int)$invoice_id === $order_id) {
            $data['order_id'] = $invoice_id;
        }

        return $this->load->view('extension/frisbii/payment/reepay_checkout_order', $data);
    }

    public function control(): void {
        $this->load->language('extension/frisbii/payment/reepay_checkout');
        $this->load->model('extension/frisbii/payment/reepay_checkout');

        $handle = (string)($this->request->get['order_id'] ?? '');

        if (!$this->isValidHandle($handle)) {
            return;
        }

        $result         = $this->model_extension_frisbii_payment_reepay_checkout->getInvoice($handle);
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
        $data['order_id']          = $handle;

        $payment_type = $data['transactions'][0]['payment_type'] ?? '';
        $data['payment_type'] = $payment_type;

        // card_type becomes part of an image file name, so only a plain identifier is allowed.
        $card_type = $data['transactions'][0][$payment_type . '_transaction']['card_type'] ?? null;
        $data['card_type'] = is_string($card_type) && preg_match('/^[a-z0-9_]+$/', $card_type) ? $card_type : null;
        // The admin pages are not served from the shop root, so the logo URL starts at the catalog URL.
        $data['card_logo'] = $data['card_type'] ? HTTP_CATALOG . \Opencart\System\Library\Extension\Frisbii\Reepay\Logo::path('reepay_' . $data['card_type'] . '.png') : null;
        $data['exp_date']    = $data['transactions'][0][$payment_type . '_transaction']['exp_date']    ?? null;
        $data['masked_card'] = $data['transactions'][0][$payment_type . '_transaction']['masked_card'] ?? null;

        $this->response->setOutput($this->load->view('extension/frisbii/payment/reepay_checkout_order_control', $data));
    }

    public function charge_settle(): void {
        if (!$this->checkModifyPermission('charge_settle')) {
            return;
        }

        $handle = $this->getPostedHandle();
        if (null === $handle) {
            return;
        }

        $this->load->model('extension/frisbii/payment/reepay_checkout');
        $this->response->setOutput(
            $this->model_extension_frisbii_payment_reepay_checkout->settleCharge($handle, $this->request->post['amount'])
        );
    }

    public function void_charge(): void {
        if (!$this->checkModifyPermission('void_charge')) {
            return;
        }

        $handle = $this->getPostedHandle();
        if (null === $handle) {
            return;
        }

        $this->load->model('extension/frisbii/payment/reepay_checkout');
        $this->response->setOutput(
            $this->model_extension_frisbii_payment_reepay_checkout->voidCharge($handle, $this->request->post['amount'])
        );
    }

    public function refund_charge(): void {
        if (!$this->checkModifyPermission('refund_charge')) {
            return;
        }

        $handle = $this->getPostedHandle();
        if (null === $handle) {
            return;
        }

        $this->load->model('extension/frisbii/payment/reepay_checkout');
        $this->response->setOutput(
            $this->model_extension_frisbii_payment_reepay_checkout->refundCharge($handle, $this->request->post['amount'])
        );
    }

    /**
     * Capture, refund and cancel move money, so they need the "modify" permission. OpenCart itself
     * only checks "access" for an extension route, which also covers read-only users.
     * Returns false after writing a failure response (same JSON shape as the model's sendCurl()).
     */
    protected function checkModifyPermission(string $action): bool {
        if ($this->user->hasPermission('modify', 'extension/frisbii/payment/reepay_checkout')) {
            return true;
        }

        $this->load->language('extension/frisbii/payment/reepay_checkout');
        $this->load->model('extension/frisbii/payment/reepay_checkout');

        // The user name is chosen by an admin; keep the log line plain.
        $user_name = substr(preg_replace('/[^A-Za-z0-9_.@-]/', '?', $this->user->getUserName()), 0, 64);
        $this->model_extension_frisbii_payment_reepay_checkout->logAlways('Admin ' . $action . ': REFUSED, user "' . $user_name . '" has no modify permission for Frisbii Payments');

        $this->response->setOutput(json_encode(['status' => 'failure', 'error' => $this->language->get('error_permission')]));

        return false;
    }

    /**
     * Invoice handles are always "<order_id>" or "<order_id>-<timestamp>"
     * (see generateRequestParams() in the catalog model).
     */
    protected function isValidHandle(string $handle): bool {
        return 1 === preg_match('/^\d+(?:-\d+)?$/', $handle);
    }

    /**
     * Returns the posted invoice handle, or null after writing a failure response
     * (same JSON shape as the model's sendCurl()) when it is missing or malformed.
     */
    protected function getPostedHandle(): ?string {
        $handle = $this->request->post['handle'] ?? '';

        if (is_string($handle) && $this->isValidHandle($handle)) {
            return $handle;
        }

        $this->response->setOutput(json_encode(['status' => 'failure', 'error' => 'Invalid invoice handle']));

        return null;
    }

    public function install(): void {
        // Processed webhook ids, shop-wide, so a webhook id cannot be replayed against another order.
        // Same definition as ensureWebhookEventTable() in the catalog model, which creates the
        // table on demand for shops that are upgraded without reinstalling the extension.
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "frisbii_webhook_event` (
                `event_hash` char(64) NOT NULL,
                `event_id` varchar(255) NOT NULL,
                `event_type` varchar(64) NOT NULL,
                `order_id` int(11) NOT NULL,
                `date_added` datetime NOT NULL,
                PRIMARY KEY (`event_hash`),
                KEY `date_added` (`date_added`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public function uninstall(): void {
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "frisbii_webhook_event`");
    }

    protected function getOrderPaymentCustomField(string $order_id): object {
        return $this->db->query("SELECT `payment_custom_field` FROM `" . DB_PREFIX . "order` WHERE order_id = '" . (int)$order_id . "'");
    }

    protected function formatAmount(int $amount): string {
        return number_format($amount / 100, 2, '.', '');
    }
}
