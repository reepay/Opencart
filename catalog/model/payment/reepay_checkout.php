<?php

namespace Opencart\Catalog\Model\Extension\Frisbii\Payment;

class ReepayCheckout extends \Opencart\System\Engine\Model {

    const CHARGE_SESSION_URL    = 'https://checkout-api.frisbii.com/v1/session/charge';
    const GET_INVOICE_URL       = 'https://api.frisbii.com/v1/invoice/';
    const WEBHOOK_SETTINGS_URL  = 'https://api.frisbii.com/v1/account/webhook_settings';
    const WEBHOOK_SECRET_TTL    = 600; // 10 minutes in seconds

    public function getMethods(array $address = []): array {
        $this->load->language('extension/frisbii/payment/reepay_checkout');

        if (!$this->config->get('payment_reepay_checkout_status')) {
            return [];
        }

        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('payment_reepay_checkout_geo_zone_id') . "' AND country_id = '" . (int)($address['country_id'] ?? 0) . "' AND (zone_id = '" . (int)($address['zone_id'] ?? 0) . "' OR zone_id = '0')");

        $total = $address['total'] ?? 0;

        if ($this->config->get('payment_reepay_checkout_total') > 0 && $this->config->get('payment_reepay_checkout_total') > $total) {
            $status = false;
        } elseif (!$this->config->get('payment_reepay_checkout_geo_zone_id')) {
            $status = true;
        } elseif ($query->num_rows) {
            $status = true;
        } else {
            $status = false;
        }

        if (!$status) {
            return [];
        }

        $method_title_settings = $this->config->get('payment_reepay_checkout_method_title');
        $method_title = (strlen((string)$method_title_settings) > 3) ? $method_title_settings : $this->language->get('text_title');

        $terms = '';
        $logo_array = $this->config->get('payment_reepay_checkout_payment_logos');
        if (is_array($logo_array)) {
            $terms = $this->getLogos($logo_array);
        }

        $option_data['reepay_checkout'] = [
            'code' => 'reepay_checkout.reepay_checkout',
            'name' => $method_title,
        ];

        return [
            'code'       => 'reepay_checkout',
            'name'       => $method_title,
            'option'     => $option_data,
            'sort_order' => $this->config->get('payment_reepay_checkout_sort_order'),
            'terms'      => $terms,
        ];
    }

    public function getChargeSession(): false|string {
        $result_string = $this->createChargeSession();
        $result_array  = json_decode($result_string, true);

        if ('success' != $result_array['status']) {
            $error = json_decode($result_array['error'], true);

            if (400 == $error['http_status'] && in_array($error['code'], [105, 79, 29, 99, 72])) {
                $result_string = $this->createChargeSession(true);
                $result_array  = json_decode($result_string, true);
            }
        }

        if ('success' != $result_array['status']) {
            $this->session->data['error'] = 'Something went wrong during the payment, please choose another payment method';
        }

        return $result_string;
    }

    protected function generateRequestParams(bool $unique_invoice_handle = false): array {
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        $amount = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);

        $payment_methods = [];
        $code = $this->session->data['payment_method']['code'] ?? '';
        $base_code = explode('.', $code)[0];

        if ('reepay_checkout' === $base_code) {
            $payment_methods = $this->config->get('payment_reepay_checkout_payment_methods') ?? [];
        } else {
            $payment_methods[] = substr($base_code, 7);
        }

        // Prefer payment address; fall back to shipping; last resort: session shipping_address.
        $use_shipping = empty($order_info['payment_country_id']);
        $addr_firstname  = $use_shipping ? $order_info['shipping_firstname']  : $order_info['payment_firstname'];
        $addr_lastname   = $use_shipping ? $order_info['shipping_lastname']   : $order_info['payment_lastname'];
        $addr_company    = $use_shipping ? $order_info['shipping_company']    : $order_info['payment_company'];
        $addr_address1   = $use_shipping ? $order_info['shipping_address_1']  : $order_info['payment_address_1'];
        $addr_address2   = $use_shipping ? $order_info['shipping_address_2']  : $order_info['payment_address_2'];
        $addr_city       = $use_shipping ? $order_info['shipping_city']       : $order_info['payment_city'];
        $addr_postcode   = $use_shipping ? $order_info['shipping_postcode']   : $order_info['payment_postcode'];
        $addr_zone_id    = $use_shipping ? $order_info['shipping_zone_id']    : $order_info['payment_zone_id'];
        $addr_iso_code_2 = $use_shipping ? $order_info['shipping_iso_code_2'] : $order_info['payment_iso_code_2'];

        // Last-resort: pull country from the live session shipping address if order has none.
        if (empty($addr_iso_code_2)) {
            $sess_country_id = $this->session->data['shipping_address']['country_id'] ?? 0;
            if ($sess_country_id) {
                $this->load->model('localisation/country');
                $country_info    = $this->model_localisation_country->getCountry($sess_country_id);
                $addr_iso_code_2 = $country_info['iso_code_2'] ?? '';
            }
            if (empty($addr_firstname))  $addr_firstname  = $this->session->data['shipping_address']['firstname'] ?? '';
            if (empty($addr_lastname))   $addr_lastname   = $this->session->data['shipping_address']['lastname']  ?? '';
            if (empty($addr_city))       $addr_city       = $this->session->data['shipping_address']['city']      ?? '';
            if (empty($addr_postcode))   $addr_postcode   = $this->session->data['shipping_address']['postcode']  ?? '';
            if (empty($addr_address1))   $addr_address1   = $this->session->data['shipping_address']['address_1'] ?? '';
        }

        // Fourth fallback: customer's saved address in DB (covers no-shipping products like digital goods).
        if (empty($addr_iso_code_2) && $order_info['customer_id']) {
            $addr_query = $this->db->query("
                SELECT a.firstname, a.lastname, a.company, a.address_1, a.address_2,
                       a.city, a.postcode, a.zone_id, co.iso_code_2
                FROM `" . DB_PREFIX . "address` a
                LEFT JOIN `" . DB_PREFIX . "country` co ON a.country_id = co.country_id
                WHERE a.customer_id = '" . (int)$order_info['customer_id'] . "'
                ORDER BY a.address_id ASC
                LIMIT 1
            ");
            if ($addr_query->num_rows) {
                $saved = $addr_query->row;
                if (empty($addr_firstname))  $addr_firstname  = $saved['firstname'];
                if (empty($addr_lastname))   $addr_lastname   = $saved['lastname'];
                if (empty($addr_company))    $addr_company    = $saved['company'];
                if (empty($addr_address1))   $addr_address1   = $saved['address_1'];
                if (empty($addr_address2))   $addr_address2   = $saved['address_2'];
                if (empty($addr_city))       $addr_city       = $saved['city'];
                if (empty($addr_postcode))   $addr_postcode   = $saved['postcode'];
                if (empty($addr_zone_id))    $addr_zone_id    = $saved['zone_id'];
                $addr_iso_code_2 = $saved['iso_code_2'] ?? '';
            }
        }

        $params = [
            'order' => [
                'handle'      => $unique_invoice_handle ? $order_info['order_id'] . '-' . time() : $order_info['order_id'],
                'amount'      => false == $this->config->get('payment_reepay_checkout_order_lines') ? $this->prepareAmount($amount) : null,
                'order_lines' => $this->config->get('payment_reepay_checkout_order_lines') ? $this->getOrderLines() : null,
                'currency'    => $order_info['currency_code'],
                'customer'    => [
                    'test'       => $this->config->get('payment_reepay_checkout_test') ? true : false,
                    'email'      => $order_info['email'],
                    'address'    => $addr_address1,
                    'address2'   => $addr_address2,
                    'city'       => $addr_city,
                    'country'    => $addr_iso_code_2,
                    'phone'      => $order_info['telephone'],
                    'company'    => $addr_company,
                    'vat'        => '',
                    'first_name' => $addr_firstname,
                    'last_name'  => $addr_lastname,
                    'postal_code'=> $addr_postcode,
                ],
                'billing_address' => [
                    'attention'         => '',
                    'email'             => $order_info['email'],
                    'address'           => $addr_address1,
                    'address2'          => $addr_address2,
                    'city'              => $addr_city,
                    'country'           => $addr_iso_code_2,
                    'phone'             => $order_info['telephone'],
                    'company'           => $addr_company,
                    'vat'               => '',
                    'first_name'        => $addr_firstname,
                    'last_name'         => $addr_lastname,
                    'postal_code'       => $addr_postcode,
                    'state_or_province' => $addr_zone_id,
                ],
            ],
            'settle'          => $this->config->get('payment_reepay_checkout_instant_settle') ? true : false,
            'payment_methods' => $payment_methods,
            'accept_url'      => $this->url->link('extension/frisbii/payment/reepay_checkout.accept', '', true),
            'cancel_url'      => $this->url->link('extension/frisbii/payment/reepay_checkout.cancel', '', true),
        ];

        if ($order_info['customer_id']) {
            $params['order']['customer']['handle'] = $order_info['customer_id'];
        } else {
            $params['order']['customer']['generate_handle'] = true;
        }

        $order_lines       = $this->getOrderLines();
        $calculated_amount = 0;

        foreach ($order_lines as $order_line) {
            if (isset($order_line['quantity'])) {
                $calculated_amount += $order_line['amount'] * $order_line['quantity'];
            } else {
                $calculated_amount += $order_line['amount'];
            }
        }

        $amount = $this->prepareAmount($amount);

        if ($amount != $calculated_amount) {
            unset($params['order']['order_lines']);
            $params['order']['amount'] = $amount;
        }

        return $params;
    }

    public function sendCurl(string $url, array $params, bool $is_post = true): false|string {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 60,
        ]);

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $this->log('Request to server: ' . $url);
        $this->log($params);

        $key = $this->config->get('payment_reepay_checkout_test')
            ? trim($this->config->get('payment_reepay_checkout_private_key_test'))
            : trim($this->config->get('payment_reepay_checkout_private_key_live'));

        curl_setopt_array($ch, [
            CURLOPT_USERAGENT  => 'curl',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_URL        => $url,
            CURLOPT_USERPWD    => "$key:",
        ]);

        if ($is_post && count($params) > 0) {
            $data      = json_encode($params, JSON_PRETTY_PRINT);
            $headers[] = 'Content-Length: ' . strlen($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        } elseif (!$is_post) {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        $response     = curl_exec($ch);
        $response_arr = json_decode($response, true);

        $this->log('Response from server:');
        $this->log($response_arr);

        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (2 == intval($http_code / 100)) {
            $result = json_encode(['status' => 'success', 'body' => $response_arr]);
        } else {
            $curl_error = curl_error($ch);
            $result = json_encode(['status' => 'failure', 'error' => $response . $curl_error]);
        }

        return $result;
    }

    public function getOrderLines(): array {
        $items = [];

        foreach ($this->cart->getProducts() as $product) {
            $items[] = [
                'ordertext' => $product['name'],
                'amount'    => (string)($this->formatPrice($this->tax->calculate($product['price'], $product['tax_class_id'])) * 100),
                'quantity'  => $product['quantity'],
            ];
        }

        if (!empty($this->session->data['shipping_method']['cost'])) {
            $shipping_cost         = $this->session->data['shipping_method']['cost'];
            $shipping_tax_class_id = $this->session->data['shipping_method']['tax_class_id'];
            $amount                = $this->formatPrice($this->tax->calculate($shipping_cost, $shipping_tax_class_id)) * 100;
            settype($amount, 'string');
            $items[] = [
                'ordertext' => $this->session->data['shipping_method']['name'],
                'amount'    => $amount,
            ];
        }

        $this->load->model('checkout/cart');
        $totals = [];
        $taxes  = $this->cart->getTaxes();
        $total  = 0;
        ($this->model_checkout_cart->getTotals)($totals, $taxes, $total);

        $coupon_data = [];
        if ($totals) {
            foreach ($totals as $total) {
                if ($total['code'] == 'coupon' && !empty($total['value'])) {
                    $coupon_data = $total;
                }
            }
        }

        if ($coupon_data) {
            $items[] = [
                'ordertext' => $coupon_data['title'],
                'amount'    => $this->formatPrice((float)$coupon_data['value']) * 100,
            ];
        }

        return $items;
    }

    public function getInvoice(string $invoiceId): array {
        $url = self::GET_INVOICE_URL . $invoiceId;
        return json_decode($this->sendCurl($url, [], false), true);
    }

    public function getWebhookSecret(): string|null {
        $cached_secret = $this->db->query(
            "SELECT value FROM `" . DB_PREFIX . "setting`
             WHERE `key` = 'payment_reepay_checkout_webhook_secret'
             AND store_id = '0' LIMIT 1"
        );
        $cached_at = $this->db->query(
            "SELECT value FROM `" . DB_PREFIX . "setting`
             WHERE `key` = 'payment_reepay_checkout_webhook_secret_cached_at'
             AND store_id = '0' LIMIT 1"
        );

        if ($cached_secret->num_rows && $cached_at->num_rows) {
            if ((time() - (int)$cached_at->row['value']) < self::WEBHOOK_SECRET_TTL) {
                return $cached_secret->row['value'];
            }
        }

        $result = json_decode($this->sendCurl(self::WEBHOOK_SETTINGS_URL, [], false), true);

        if (($result['status'] ?? '') !== 'success' || empty($result['body']['secret'])) {
            $this->log('Frisbii: failed to fetch webhook secret from API');
            return null;
        }

        $secret = $result['body']['secret'];
        $now    = time();

        $this->db->query(
            "REPLACE INTO `" . DB_PREFIX . "setting` SET
             store_id = '0', code = 'payment_reepay_checkout',
             `key` = 'payment_reepay_checkout_webhook_secret',
             value = '" . $this->db->escape($secret) . "', serialized = '0'"
        );
        $this->db->query(
            "REPLACE INTO `" . DB_PREFIX . "setting` SET
             store_id = '0', code = 'payment_reepay_checkout',
             `key` = 'payment_reepay_checkout_webhook_secret_cached_at',
             value = '" . (int)$now . "', serialized = '0'"
        );

        return $secret;
    }

    public function clearCachedWebhookSecret(): void {
        $this->db->query(
            "DELETE FROM `" . DB_PREFIX . "setting`
             WHERE `key` IN (
                 'payment_reepay_checkout_webhook_secret',
                 'payment_reepay_checkout_webhook_secret_cached_at'
             ) AND store_id = '0'"
        );
    }

    public function verifyWebhookSignature(array $payload): bool {
        $timestamp = (string)($payload['timestamp'] ?? '');
        $id        = (string)($payload['id'] ?? '');
        $signature = (string)($payload['signature'] ?? '');

        if ($timestamp === '' || $id === '' || $signature === '') {
            $this->log('Frisbii webhook: missing signature fields in payload');
            return false;
        }

        $secret = $this->getWebhookSecret();

        if (!$secret) {
            $this->log('Frisbii webhook: no webhook secret available for verification');
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . $id, $secret);

        if (hash_equals($expected, $signature)) {
            return true;
        }

        // Signature mismatch — secret may be stale; retry once with a fresh fetch
        $this->log('Frisbii webhook: signature mismatch, clearing cache and retrying');
        $this->clearCachedWebhookSecret();
        $secret = $this->getWebhookSecret();

        if (!$secret) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . $id, $secret);

        if (!hash_equals($expected, $signature)) {
            $this->log('Frisbii webhook: signature verification failed after retry');
            return false;
        }

        return true;
    }

    public function processWebhook(array $payload): void {
        $event_type = $payload['invoice']['state'] ?? '';
        $raw_event  = $payload['event_type'] ?? '';

        switch ($raw_event) {
            case 'invoice_authorized':
                $this->handleInvoiceAuthorized($payload);
                break;
            case 'invoice_settled':
                $this->handleInvoiceSettled($payload);
                break;
            case 'invoice_cancelled':
                $this->handleInvoiceCancelled($payload);
                break;
            case 'invoice_refund':
                $this->handleInvoiceRefund($payload);
                break;
            default:
                $this->log('Frisbii webhook: ignoring unhandled event type: ' . $raw_event);
        }
    }

    private function handleInvoiceAuthorized(array $payload): void {
        $order_id  = $this->extractOrderId($payload);
        $event_id  = $payload['id'] ?? '';

        if (!$order_id || !$event_id) {
            $this->log('Frisbii webhook invoice_authorized: missing order_id or event_id');
            return;
        }

        if (!$this->acquireOrderLock($order_id)) {
            $this->log('Frisbii webhook invoice_authorized: could not acquire lock for order ' . $order_id);
            return;
        }

        try {
            if ($this->isWebhookEventProcessed($order_id, $event_id)) {
                $this->log('Frisbii webhook invoice_authorized: duplicate event ' . $event_id . ', skipping');
                return;
            }

            $status_id = (int)$this->config->get('payment_reepay_checkout_order_status_authorized_id');
            if (!$status_id) {
                $status_id = (int)$this->config->get('payment_reepay_checkout_order_status_id');
            }

            $this->load->model('checkout/order');
            $this->model_checkout_order->addHistory($order_id, $status_id, '', false);
            $this->markWebhookEventProcessed($order_id, $event_id);
            $this->log('Frisbii webhook invoice_authorized: order ' . $order_id . ' → status ' . $status_id);
        } finally {
            $this->releaseOrderLock($order_id);
        }
    }

    private function handleInvoiceSettled(array $payload): void {
        $order_id = $this->extractOrderId($payload);
        $event_id = $payload['id'] ?? '';

        if (!$order_id || !$event_id) {
            $this->log('Frisbii webhook invoice_settled: missing order_id or event_id');
            return;
        }

        if (!$this->acquireOrderLock($order_id)) {
            $this->log('Frisbii webhook invoice_settled: could not acquire lock for order ' . $order_id);
            return;
        }

        try {
            if ($this->isWebhookEventProcessed($order_id, $event_id)) {
                $this->log('Frisbii webhook invoice_settled: duplicate event ' . $event_id . ', skipping');
                return;
            }

            $status_id = (int)$this->config->get('payment_reepay_checkout_order_status_settled_id');
            if (!$status_id) {
                $status_id = (int)$this->config->get('payment_reepay_checkout_order_status_id');
            }

            $this->load->model('checkout/order');
            $this->model_checkout_order->addHistory($order_id, $status_id, '', false);
            $this->markWebhookEventProcessed($order_id, $event_id);
            $this->log('Frisbii webhook invoice_settled: order ' . $order_id . ' → status ' . $status_id);
        } finally {
            $this->releaseOrderLock($order_id);
        }
    }

    private function handleInvoiceCancelled(array $payload): void {
        $order_id = $this->extractOrderId($payload);
        $event_id = $payload['id'] ?? '';

        if (!$order_id || !$event_id) {
            $this->log('Frisbii webhook invoice_cancelled: missing order_id or event_id');
            return;
        }

        if (!$this->acquireOrderLock($order_id)) {
            $this->log('Frisbii webhook invoice_cancelled: could not acquire lock for order ' . $order_id);
            return;
        }

        try {
            if ($this->isWebhookEventProcessed($order_id, $event_id)) {
                $this->log('Frisbii webhook invoice_cancelled: duplicate event ' . $event_id . ', skipping');
                return;
            }

            $status_id = (int)$this->config->get('payment_reepay_checkout_order_status_cancelled_id');
            if (!$status_id) {
                $status_id = (int)$this->config->get('payment_reepay_checkout_order_status_id');
            }

            $this->load->model('checkout/order');
            $this->model_checkout_order->addHistory($order_id, $status_id, '', false);
            $this->markWebhookEventProcessed($order_id, $event_id);
            $this->log('Frisbii webhook invoice_cancelled: order ' . $order_id . ' → status ' . $status_id);
        } finally {
            $this->releaseOrderLock($order_id);
        }
    }

    private function handleInvoiceRefund(array $payload): void {
        $order_id = $this->extractOrderId($payload);
        $event_id = $payload['id'] ?? '';

        if (!$order_id || !$event_id) {
            $this->log('Frisbii webhook invoice_refund: missing order_id or event_id');
            return;
        }

        if (!$this->acquireOrderLock($order_id)) {
            $this->log('Frisbii webhook invoice_refund: could not acquire lock for order ' . $order_id);
            return;
        }

        try {
            if ($this->isWebhookEventProcessed($order_id, $event_id)) {
                $this->log('Frisbii webhook invoice_refund: duplicate event ' . $event_id . ', skipping');
                return;
            }

            $status_id = (int)$this->config->get('payment_reepay_checkout_order_status_refunded_id');
            if (!$status_id) {
                $status_id = (int)$this->config->get('payment_reepay_checkout_order_status_id');
            }

            $refunded_amount = $payload['credit_note']['amount'] ?? 0;
            $currency        = $payload['invoice']['currency'] ?? '';
            $amount_str      = number_format($refunded_amount / 100, 2) . ($currency ? ' ' . strtoupper($currency) : '');
            $note            = 'Refund processed via Frisbii webhook. Amount: ' . $amount_str;

            $this->load->model('checkout/order');
            $this->model_checkout_order->addHistory($order_id, $status_id, $note, false);
            $this->markWebhookEventProcessed($order_id, $event_id);
            $this->log('Frisbii webhook invoice_refund: order ' . $order_id . ' → status ' . $status_id . ', amount ' . $amount_str);
        } finally {
            $this->releaseOrderLock($order_id);
        }
    }

    private function extractOrderId(array $payload): int {
        $handle = $payload['invoice']['handle'] ?? '';
        // Handle format is either "ORDER_ID" or "ORDER_ID-TIMESTAMP"
        $parts = explode('-', $handle);
        return (int)($parts[0] ?? 0);
    }

    public function acquireOrderLock(int $order_id): bool {
        $lock_name = 'frisbii_order_' . $order_id;
        $result = $this->db->query(
            "SELECT GET_LOCK('" . $this->db->escape($lock_name) . "', 30) AS acquired"
        );
        return (bool)($result->row['acquired'] ?? false);
    }

    public function releaseOrderLock(int $order_id): void {
        $lock_name = 'frisbii_order_' . $order_id;
        $this->db->query(
            "SELECT RELEASE_LOCK('" . $this->db->escape($lock_name) . "')"
        );
    }

    public function isWebhookEventProcessed(int $order_id, string $event_id): bool {
        $result = $this->db->query(
            "SELECT `payment_custom_field` FROM `" . DB_PREFIX . "order`
             WHERE order_id = '" . (int)$order_id . "'"
        );

        if (!$result->num_rows) {
            return false;
        }

        $field = json_decode($result->row['payment_custom_field'] ?? '{}', true);
        $processed = $field['processed_webhook_ids'] ?? [];

        return in_array($event_id, $processed, true);
    }

    public function markWebhookEventProcessed(int $order_id, string $event_id): void {
        $result = $this->db->query(
            "SELECT `payment_custom_field` FROM `" . DB_PREFIX . "order`
             WHERE order_id = '" . (int)$order_id . "'"
        );

        $field = json_decode($result->row['payment_custom_field'] ?? '{}', true) ?: [];
        $processed = $field['processed_webhook_ids'] ?? [];

        if (!in_array($event_id, $processed, true)) {
            $processed[] = $event_id;
        }

        $field['processed_webhook_ids'] = $processed;

        $this->db->query(
            "UPDATE `" . DB_PREFIX . "order`
             SET `payment_custom_field` = '" . $this->db->escape(json_encode($field)) . "'
             WHERE order_id = '" . (int)$order_id . "'"
        );
    }

    public function log(mixed $data): void {
        if ($this->config->get('payment_reepay_checkout_debug')) {
            $log = new \Opencart\System\Library\Log('reepay_checkout.log');
            $log->write($data);
        }
    }

    protected function createChargeSession(bool $unique_invoice_handle = false): false|string {
        $payload = $this->generateRequestParams($unique_invoice_handle);
        return $this->sendCurl(self::CHARGE_SESSION_URL, $payload);
    }

    protected function prepareAmount(mixed $value): int {
        return (int)(string)($value * 100);
    }

    private function formatPrice(mixed $value): float {
        return $this->format($value, $this->session->data['currency'], '', false);
    }

    private function format(mixed $number, string $currency, string $value = '', bool $format = true): float {
        $decimal_place = $this->currency->getDecimalPlace($currency);
        if (empty($decimal_place)) {
            $decimal_place = 0;
        }
        if (!$value) {
            $value = $this->currency->getValue($currency);
        }
        $amount = $value ? (float)$number * $value : (float)$number;
        return round($amount, (int)$decimal_place);
    }

    private function getLogos(array $logo_array): string {
        $logos = '<br/>';
        $style = 'height:30px;width:auto;border-style:solid;border-width:1px;border-radius:4px;border-color:#f8f8f8;margin:2px;';
        $i = 0;

        foreach ($logo_array as $logo) {
            $i++;
            $logos .= '<img src="extension/frisbii/image/reepay/reepay_' . $logo . '.png" height="32px" style="' . $style . '" width="93px"/>';
            if ($i % 4 == 0) {
                $logos .= '<br/>';
            }
        }

        return $logos;
    }
}
