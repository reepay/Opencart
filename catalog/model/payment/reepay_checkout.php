<?php

namespace Opencart\Catalog\Model\Extension\Frisbii\Payment;

class ReepayCheckout extends \Opencart\System\Engine\Model {

    const CHARGE_SESSION_URL    = 'https://checkout-api.frisbii.com/v1/session/charge';
    const GET_INVOICE_URL       = 'https://api.frisbii.com/v1/invoice/';
    const WEBHOOK_SETTINGS_URL  = 'https://api.frisbii.com/v1/account/webhook_settings';
    const WEBHOOK_SECRET_TTL    = 600; // 10 minutes in seconds
    // Accepted distance between the webhook timestamp and now, in seconds. Set to one hour so that
    // Frisbii's delivery retries (2, 5, 10, 20, 30 min, then hourly), which reuse the original
    // timestamp, still fall inside the window and can recover a 503'd event after a longer outage.
    // The wider window does not let a replayed webhook force a false state: every event is confirmed
    // against the invoice in the API and deduplicated shop-wide by event id (see handleInvoiceEvent()).
    const WEBHOOK_MAX_AGE       = 3600;
    const WEBHOOK_EVENT_KEEP_DAYS = 30; // how long processed webhook ids are remembered

    // Outcome of processWebhook(); the controller turns it into the HTTP status.
    const WEBHOOK_PROCESSED = 'processed'; // order was updated                         -> 200
    const WEBHOOK_IGNORED   = 'ignored';   // duplicate, unhandled type or rejected     -> 200
    const WEBHOOK_RETRY     = 'retry';     // could not be decided now, Frisbii retries -> 503

    // Result of confirming a webhook against the invoice in the Frisbii API.
    private const INVOICE_CONFIRMED     = 'confirmed';
    private const INVOICE_REJECTED      = 'rejected';
    private const INVOICE_LOOKUP_FAILED = 'lookup_failed';

    private bool $webhook_event_table_checked = false;
    private int $webhook_changed_order_id = 0; // order changed by the webhook being handled, 0 = none

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

        $logo_array = $this->config->get('payment_reepay_checkout_payment_logos');
        $terms      = is_array($logo_array) ? $this->getLogos($logo_array) : '';

        $logos_html = '';
        if (is_array($logo_array)) {
            $img_style = 'height:25px;width:auto;vertical-align:middle;border-radius:4px;border:1px solid #f0f0f0;margin-right:4px;';
            foreach ($logo_array as $logo) {
                $logos_html .= '<img src="extension/frisbii/image/reepay/reepay_' . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') . '.png" style="' . $img_style . '" alt="' . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') . '"/>';
            }
        }

        $option_data['reepay_checkout'] = [
            'code' => 'reepay_checkout.reepay_checkout',
            'name' => $method_title,
        ];

        return [
            'code'       => 'reepay_checkout',
            'name'       => $logos_html . $method_title,
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
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 60,
        ]);

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $this->log('Request to server: ' . $url);
        $safe_params = $params;
        if (isset($safe_params['order']['customer']))        $safe_params['order']['customer']         = '[redacted]';
        if (isset($safe_params['order']['billing_address'])) $safe_params['order']['billing_address']  = '[redacted]';
        $this->log($safe_params);

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
            if ($curl_error !== '') {
                // Connection-level failure (e.g. TLS certificate could not be verified).
                $this->logAlways('cURL error calling Frisbii API: ' . $curl_error);
            }
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
        // Defense in depth: the handle is already validated by the caller, but encode it
        // anyway so it can never be (mis)used to alter the request path sent to the API.
        $url = self::GET_INVOICE_URL . rawurlencode($invoiceId);
        return json_decode($this->sendCurl($url, [], false), true);
    }

    /**
     * True only when the invoice was issued for exactly what the order costs right now:
     * same amount in minor units (same calculation as generateRequestParams()) and same currency.
     * Fails closed when either side is missing or unreadable. Every rejection is logged,
     * regardless of the debug setting.
     */
    public function invoiceMatchesOrder(array $order_info, mixed $invoice): bool {
        $order_id          = (int)($order_info['order_id'] ?? 0);
        $expected_currency = (string)($order_info['currency_code'] ?? '');
        $expected_amount   = null;

        if ($order_id && $expected_currency !== '' && isset($order_info['total'])) {
            // Currency::format() returns '' for a currency the store does not know.
            $formatted = $this->currency->format((float)$order_info['total'], $expected_currency, (float)($order_info['currency_value'] ?? 0), false);

            if (is_numeric($formatted)) {
                $expected_amount = $this->prepareAmount($formatted);
            }
        }

        $actual_amount   = is_array($invoice) ? ($invoice['amount'] ?? null) : null;
        $actual_currency = is_array($invoice) ? ($invoice['currency'] ?? null) : null;

        $amount_ok = $expected_amount !== null
            && (is_int($actual_amount) || (is_string($actual_amount) && ctype_digit($actual_amount)))
            && (int)$actual_amount === $expected_amount;

        $currency_ok = is_string($actual_currency)
            && $actual_currency !== ''
            && strtoupper($actual_currency) === strtoupper($expected_currency);

        if ($amount_ok && $currency_ok) {
            return true;
        }

        $this->logAlways(sprintf(
            'Frisbii: REJECTED payment confirmation for order %d (invoice handle "%s"): expected %s %s, invoice has %s %s',
            $order_id,
            is_array($invoice) && is_scalar($invoice['handle'] ?? null) ? (string)$invoice['handle'] : '',
            $expected_amount === null ? 'n/a' : (string)$expected_amount,
            $expected_currency === '' ? 'n/a' : $expected_currency,
            is_scalar($actual_amount) ? (string)$actual_amount : 'n/a',
            is_scalar($actual_currency) ? (string)$actual_currency : 'n/a'
        ));

        return false;
    }

    /**
     * Webhook payloads only carry the invoice handle, and only timestamp + id are signed, so the
     * event type and the handle can be forged on a replayed webhook. Before any webhook may change
     * an order, the invoice is fetched from the API and must really be in the situation the event
     * claims:
     *   - the API returns exactly the handle that was asked for,
     *   - its state is one of $allowed_states (skipped when the list is empty),
     *   - it shows a refund when $require_refund is set,
     *   - it matches the order's amount and currency when $check_amount is set.
     *
     * A failed lookup (cURL error, non-success status, undecodable body) is reported separately
     * from a real mismatch, so the caller can ask Frisbii to retry instead of dropping the event.
     */
    private function confirmWebhookInvoice(array $order_info, string $handle, string $event, array $allowed_states, bool $check_amount, bool $require_refund): string {
        $order_id = (int)$order_info['order_id'];
        $result   = $this->getInvoice($handle);
        $body     = is_array($result) ? ($result['body'] ?? null) : null;

        if (!is_array($result) || ($result['status'] ?? null) !== 'success' || !is_array($body)) {
            $this->logAlways('Frisbii webhook ' . $event . ': NOT PROCESSED, could not fetch invoice "' . $handle . '" for order ' . $order_id . ', asking Frisbii to retry');
            return self::INVOICE_LOOKUP_FAILED;
        }

        if (($body['handle'] ?? null) !== $handle) {
            $this->logAlways('Frisbii webhook ' . $event . ': REJECTED, API returned a different invoice than "' . $handle . '" for order ' . $order_id);
            return self::INVOICE_REJECTED;
        }

        if ($allowed_states && !in_array($body['state'] ?? null, $allowed_states, true)) {
            $this->logAlways('Frisbii webhook ' . $event . ': REJECTED, invoice "' . $handle . '" is in state "' . $this->logValue($body['state'] ?? null) . '" for order ' . $order_id);
            return self::INVOICE_REJECTED;
        }

        if ($require_refund && (!is_int($body['refunded_amount'] ?? null) || $body['refunded_amount'] <= 0)) {
            $this->logAlways('Frisbii webhook ' . $event . ': REJECTED, invoice "' . $handle . '" has no refund (refunded_amount "' . $this->logValue($body['refunded_amount'] ?? null) . '") for order ' . $order_id);
            return self::INVOICE_REJECTED;
        }

        // invoiceMatchesOrder() writes its own rejection log line.
        if ($check_amount && !$this->invoiceMatchesOrder($order_info, $body)) {
            return self::INVOICE_REJECTED;
        }

        return self::INVOICE_CONFIRMED;
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
        $timestamp = is_string($payload['timestamp'] ?? null) || is_int($payload['timestamp'] ?? null) ? (string)$payload['timestamp'] : '';
        $id        = is_string($payload['id'] ?? null) || is_int($payload['id'] ?? null) ? (string)$payload['id'] : '';
        $signature = is_string($payload['signature'] ?? null) ? $payload['signature'] : '';
        $context   = ' (event type "' . $this->logValue($payload['event_type'] ?? null) . '", invoice "' . $this->logValue($payload['invoice'] ?? null) . '")';

        if ($timestamp === '' || $id === '' || $signature === '') {
            $this->logAlways('Frisbii webhook: REJECTED, missing signature fields in payload' . $context);
            return false;
        }

        // Reject timestamps outside the allowed window (old or future) to limit replay attacks.
        // A timestamp that cannot be parsed fails closed.
        $time = $this->parseWebhookTimestamp($timestamp);

        if ($time === false || abs(time() - $time) > self::WEBHOOK_MAX_AGE) {
            $this->logAlways('Frisbii webhook: REJECTED, timestamp "' . $this->logValue($timestamp) . '" is ' . ($time === false ? 'not a valid timestamp' : 'outside the ' . self::WEBHOOK_MAX_AGE . ' second window') . $context);
            return false;
        }

        $secret = $this->getWebhookSecret();

        if (!$secret) {
            $this->logAlways('Frisbii webhook: REJECTED, no webhook secret available for verification' . $context);
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
            $this->logAlways('Frisbii webhook: REJECTED, no webhook secret available for verification' . $context);
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . $id, $secret);

        if (!hash_equals($expected, $signature)) {
            $this->logAlways('Frisbii webhook: REJECTED, signature verification failed' . $context);
            return false;
        }

        return true;
    }

    /**
     * Frisbii documents the webhook timestamp as ISO-8601 ("2015-06-25T12:10:00.64Z"); epoch
     * seconds are accepted as well. Anything else (relative formats such as "now", dates without
     * a time or time zone, ...) returns false, so the caller fails closed.
     */
    private function parseWebhookTimestamp(string $timestamp): int|false {
        if (preg_match('/^\d{9,10}$/D', $timestamp)) {
            return (int)$timestamp;
        }

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:?\d{2})$/D', $timestamp, $match)
            || !checkdate((int)$match[2], (int)$match[3], (int)$match[1])
        ) {
            return false;
        }

        return strtotime($timestamp);
    }

    /**
     * Handles a webhook whose signature has already been verified and returns one of the
     * WEBHOOK_* outcomes. Nothing here trusts the payload: event type and invoice handle are not
     * signed, so every handled event is confirmed against the invoice in the Frisbii API.
     */
    public function processWebhook(array $payload): string {
        $event_id   = is_string($payload['id'] ?? null) || is_int($payload['id'] ?? null) ? (string)$payload['id'] : '';
        $event_type = $this->logValue($payload['event_type'] ?? null);

        if ($event_id === '') {
            $this->logAlways('Frisbii webhook ' . $event_type . ': REJECTED, missing event id');
            return self::WEBHOOK_IGNORED;
        }

        // A signed webhook id is good for one definitive answer, whatever that answer was. The id
        // is the only signed part besides the timestamp, so an id that was merely ignored
        // (unhandled type, rejected by the API check) could otherwise be re-sent later with another
        // event type and invoice. The lock makes "check, handle, store" atomic for one id, also
        // when the same id is sent for two orders at the same moment.
        $event_lock = 'frisbii_event_' . md5($event_id);

        if (!$this->acquireLock($event_lock)) {
            $this->logAlways('Frisbii webhook ' . $event_type . ': NOT PROCESSED, could not acquire lock for event "' . $this->logValue($event_id) . '", asking Frisbii to retry');
            return self::WEBHOOK_RETRY;
        }

        try {
            if ($this->isWebhookEventProcessed($event_id)) {
                $this->logAlways('Frisbii webhook ' . $event_type . ': REJECTED, duplicate event "' . $this->logValue($event_id) . '" (already handled), invoice "' . $this->logValue($payload['invoice'] ?? null) . '", nothing changed');
                return self::WEBHOOK_IGNORED;
            }

            $this->webhook_changed_order_id = 0;
            $outcome = $this->dispatchWebhook($payload);

            // WEBHOOK_RETRY is the one outcome that is not final: the id stays unused so Frisbii's
            // next delivery of the same webhook is handled. order_id 0 = no order was changed.
            if ($outcome !== self::WEBHOOK_RETRY) {
                $this->markWebhookEventProcessed($event_id, $this->webhook_changed_order_id, $event_type);
            }

            return $outcome;
        } finally {
            $this->releaseLock($event_lock);
        }
    }

    private function dispatchWebhook(array $payload): string {
        $raw_event = $payload['event_type'] ?? '';

        switch ($raw_event) {
            case 'invoice_authorized':
                return $this->handleInvoiceAuthorized($payload);
            case 'invoice_settled':
                return $this->handleInvoiceSettled($payload);
            case 'invoice_cancelled':
                return $this->handleInvoiceCancelled($payload);
            case 'invoice_refund':
                return $this->handleInvoiceRefund($payload);
            default:
                $this->log('Frisbii webhook: ignoring unhandled event type: ' . $this->logValue($raw_event));
                return self::WEBHOOK_IGNORED;
        }
    }

    private function handleInvoiceAuthorized(array $payload): string {
        // Never confirm an order whose total/currency differs from what was actually paid.
        return $this->handleInvoiceEvent($payload, 'invoice_authorized', 'payment_reepay_checkout_order_status_authorized_id', ['authorized', 'settled'], true, false);
    }

    private function handleInvoiceSettled(array $payload): string {
        // Never confirm an order whose total/currency differs from what was actually paid.
        return $this->handleInvoiceEvent($payload, 'invoice_settled', 'payment_reepay_checkout_order_status_settled_id', ['settled'], true, false);
    }

    private function handleInvoiceCancelled(array $payload): string {
        return $this->handleInvoiceEvent($payload, 'invoice_cancelled', 'payment_reepay_checkout_order_status_cancelled_id', ['cancelled'], false, false);
    }

    private function handleInvoiceRefund(array $payload): string {
        // A refunded invoice keeps its state; the refund shows as refunded_amount > 0.
        // The credit note handle comes from the unsigned payload: only a plain handle is put in the note.
        $credit_note_handle = is_string($payload['credit_note'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $payload['credit_note']) ? $payload['credit_note'] : '';
        $note = 'Refund processed via Frisbii webhook.' . ($credit_note_handle ? ' Credit note: ' . $credit_note_handle : '');

        return $this->handleInvoiceEvent($payload, 'invoice_refund', 'payment_reepay_checkout_order_status_refunded_id', [], false, true, $note);
    }

    /**
     * Shared flow of the four invoice events:
     * strict handle -> order lock -> API confirmation -> status change.
     * The duplicate check and the storing of the event id are done by processWebhook(), which
     * holds the event lock around this call.
     */
    private function handleInvoiceEvent(array $payload, string $event, string $status_key, array $allowed_states, bool $check_amount, bool $require_refund, string $note = ''): string {
        $handle = $payload['invoice'] ?? null;

        // Handle must be exactly "<order_id>" or "<order_id>-<timestamp>" (see generateRequestParams()).
        if (!is_string($handle) || !preg_match('/^(\d+)(?:-\d+)?$/D', $handle, $match) || (int)$match[1] < 1) {
            $this->logAlways('Frisbii webhook ' . $event . ': REJECTED, malformed invoice handle "' . $this->logValue($handle) . '"');
            return self::WEBHOOK_IGNORED;
        }

        $order_id = (int)$match[1];

        if (!$this->acquireOrderLock($order_id)) {
            $this->logAlways('Frisbii webhook ' . $event . ': NOT PROCESSED, could not acquire lock for order ' . $order_id . ', asking Frisbii to retry');
            return self::WEBHOOK_RETRY;
        }

        try {
            $this->load->model('checkout/order');
            $order_info = $this->model_checkout_order->getOrder($order_id);

            if (empty($order_info)) {
                $this->logAlways('Frisbii webhook ' . $event . ': REJECTED, order ' . $order_id . ' not found');
                return self::WEBHOOK_IGNORED;
            }

            $confirmation = $this->confirmWebhookInvoice($order_info, $handle, $event, $allowed_states, $check_amount, $require_refund);

            if ($confirmation === self::INVOICE_LOOKUP_FAILED) {
                return self::WEBHOOK_RETRY;
            }

            if ($confirmation !== self::INVOICE_CONFIRMED) {
                return self::WEBHOOK_IGNORED;
            }

            $status_id = (int)$this->config->get($status_key);
            if (!$status_id) {
                $status_id = (int)$this->config->get('payment_reepay_checkout_order_status_id');
            }

            $this->model_checkout_order->addHistory($order_id, $status_id, $note, false);
            $this->webhook_changed_order_id = $order_id;
            $this->log('Frisbii webhook ' . $event . ': order ' . $order_id . ' → status ' . $status_id);

            return self::WEBHOOK_PROCESSED;
        } finally {
            $this->releaseOrderLock($order_id);
        }
    }

    public function acquireOrderLock(int $order_id): bool {
        return $this->acquireLock('frisbii_order_' . $order_id);
    }

    public function releaseOrderLock(int $order_id): void {
        $this->releaseLock('frisbii_order_' . $order_id);
    }

    private function acquireLock(string $lock_name): bool {
        $result = $this->db->query(
            "SELECT GET_LOCK('" . $this->db->escape($lock_name) . "', 30) AS acquired"
        );
        return (bool)($result->row['acquired'] ?? false);
    }

    private function releaseLock(string $lock_name): void {
        $this->db->query(
            "SELECT RELEASE_LOCK('" . $this->db->escape($lock_name) . "')"
        );
    }

    public function mergePaymentField(int $order_id, array $data): void {
        $result = $this->db->query(
            "SELECT `payment_custom_field` FROM `" . DB_PREFIX . "order` WHERE order_id = '" . (int)$order_id . "'"
        );
        $field = json_decode($result->row['payment_custom_field'] ?? '{}', true) ?: [];
        $field = array_merge($field, $data);
        $this->db->query(
            "UPDATE `" . DB_PREFIX . "order`
             SET `payment_custom_field` = '" . $this->db->escape(json_encode($field)) . "'
             WHERE order_id = '" . (int)$order_id . "'"
        );
    }

    /**
     * The id of every signature-verified webhook that got a final answer (applied or ignored) is
     * kept in one table for the whole shop (not per order), so an id is also recognised when it
     * is replayed with another event type or against another order. order_id 0 = nothing changed.
     * The table is created by the admin install(); this covers shops upgraded without reinstalling.
     */
    private function ensureWebhookEventTable(): void {
        if ($this->webhook_event_table_checked) {
            return;
        }

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

        $this->webhook_event_table_checked = true;
    }

    public function isWebhookEventProcessed(string $event_id): bool {
        $this->ensureWebhookEventTable();

        // Looked up by hash: fixed length and exact, whatever the id looks like.
        $result = $this->db->query(
            "SELECT `event_hash` FROM `" . DB_PREFIX . "frisbii_webhook_event`
             WHERE `event_hash` = '" . hash('sha256', $event_id) . "' LIMIT 1"
        );

        return (bool)$result->num_rows;
    }

    public function markWebhookEventProcessed(string $event_id, int $order_id, string $event_type): void {
        $this->ensureWebhookEventTable();

        $this->db->query(
            "INSERT IGNORE INTO `" . DB_PREFIX . "frisbii_webhook_event` SET
             `event_hash` = '" . hash('sha256', $event_id) . "',
             `event_id` = '" . $this->db->escape(substr($event_id, 0, 255)) . "',
             `event_type` = '" . $this->db->escape(substr($event_type, 0, 64)) . "',
             `order_id` = '" . (int)$order_id . "',
             `date_added` = NOW()"
        );

        // Ids only matter while a webhook with that id can still pass the timestamp window;
        // old rows are removed so the table does not grow without limit.
        $this->db->query(
            "DELETE FROM `" . DB_PREFIX . "frisbii_webhook_event`
             WHERE `date_added` < DATE_SUB(NOW(), INTERVAL " . (int)self::WEBHOOK_EVENT_KEEP_DAYS . " DAY)"
        );
    }

    // Payload values are attacker-controlled: keep log lines to one short line of plain characters.
    private function logValue(mixed $value): string {
        if (!is_scalar($value)) {
            return 'n/a';
        }

        return substr(preg_replace('/[^A-Za-z0-9_.:+ -]/', '?', (string)$value), 0, 64);
    }

    public function log(mixed $data): void {
        if ($this->config->get('payment_reepay_checkout_debug')) {
            $log = new \Opencart\System\Library\Log('reepay_checkout.log');
            $log->write($data);
        }
    }

    // For security-relevant rejections: written even when the debug setting is off.
    private function logAlways(string $message): void {
        $log = new \Opencart\System\Library\Log('reepay_checkout.log');
        $log->write($message);
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
            $logos .= '<img src="extension/frisbii/image/reepay/reepay_' . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') . '.png" height="32px" style="' . $style . '" width="93px"/>';
            if ($i % 4 == 0) {
                $logos .= '<br/>';
            }
        }

        return $logos;
    }
}
