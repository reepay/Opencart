<?php

namespace Opencart\Admin\Model\Extension\Frisbii\Payment;

class ReepayCheckout extends \Opencart\System\Engine\Model {

    const GET_INVOICE_URL  = 'https://api.frisbii.com/v1/invoice/';
    const SETTLE_URL       = 'https://api.frisbii.com/v1/charge/';
    const REFUND_URL       = 'https://api.frisbii.com/v1/refund';

    // Debug log redaction (see redactForLog()).
    // Keys whose value is never written to the log, wherever they appear in a request or response.
    private const LOG_REDACT_KEYS   = ['secret', 'alert_emails', 'email', 'phone', 'first_name', 'last_name', 'address', 'address2', 'city', 'postal_code', 'masked_card', 'fingerprint', 'exp_date'];
    // Blocks that describe a person: every value inside is redacted, except LOG_KEEP_KEYS.
    private const LOG_REDACT_BLOCKS = ['customer', 'billing_address', 'shipping_address'];
    private const LOG_KEEP_KEYS     = ['handle', 'generate_handle', 'test', 'country'];

    public function getInvoice(string $invoice_handle): string|false {
        return $this->sendCurl(self::GET_INVOICE_URL . rawurlencode($invoice_handle));
    }

    public function settleCharge(string $invoice_handle, string|float $amount): string|false {
        $amount = $this->prepareAmount($amount);
        return $this->sendCurl(self::SETTLE_URL . rawurlencode($invoice_handle) . '/settle', ['amount' => $amount]);
    }

    public function voidCharge(string $invoice_handle, string|float $amount): string|false {
        $amount = $this->prepareAmount($amount);
        return $this->sendCurl(self::SETTLE_URL . rawurlencode($invoice_handle) . '/cancel', ['amount' => $amount]);
    }

    public function refundCharge(string $invoice_handle, string|float $amount): string|false {
        $amount = $this->prepareAmount($amount);
        return $this->sendCurl(self::REFUND_URL, ['invoice' => $invoice_handle, 'amount' => $amount]);
    }

    public function sendCurl(string $url, array $params = [], bool $is_post = true): string|false {
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

        // Only redacted copies are logged; $params itself is sent to the API unchanged.
        $this->log('Request to server: ' . $url);
        $this->log($this->redactForLog($params));

        $key = $this->config->get('payment_reepay_checkout_test')
            ? trim($this->config->get('payment_reepay_checkout_private_key_test'))
            : trim($this->config->get('payment_reepay_checkout_private_key_live'));

        curl_setopt_array($ch, [
            CURLOPT_USERAGENT  => 'curl',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_URL        => $url,
            CURLOPT_USERPWD    => "$key:",
        ]);

        if (count($params) > 0) {
            $data      = json_encode($params, JSON_PRETTY_PRINT);
            $headers[] = 'Content-Length: ' . strlen($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response     = curl_exec($ch);
        $response_arr = json_decode($response, true);

        // The response can hold customer and card details (invoice, settle, cancel, refund).
        $this->log('Response from server:');
        $this->log($this->redactForLog($response_arr));

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

    protected function prepareAmount(string|float $amount): int {
        return (int)(string)($amount * 100);
    }

    /**
     * Returns a copy of an API request or response that is safe to write to the log: the webhook
     * secret, personal data and card details are replaced with "[redacted]", everything else
     * (handles, states, amounts, error messages) stays readable. Only the logged copy is changed.
     */
    private function redactForLog(mixed $data, bool $in_block = false): mixed {
        if (!is_array($data)) {
            // A response that is not a JSON object or list has no keys to judge it by.
            return $data === null ? null : '[not logged: not a JSON object]';
        }

        foreach ($data as $key => $value) {
            $name = is_string($key) ? strtolower($key) : '';

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if (in_array($name, self::LOG_REDACT_KEYS, true)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redactForLog($value, $in_block || in_array($name, self::LOG_REDACT_BLOCKS, true));
            } elseif ($in_block && !in_array($name, self::LOG_KEEP_KEYS, true)) {
                $data[$key] = '[redacted]';
            }
        }

        return $data;
    }

    public function log(mixed $data): void {
        if ($this->config->get('payment_reepay_checkout_debug')) {
            $log = new \Opencart\System\Library\Log('reepay_checkout.log');
            $log->write($data);
        }
    }

    // For security-relevant events: written even when the debug setting is off.
    public function logAlways(string $message): void {
        $log = new \Opencart\System\Library\Log('reepay_checkout.log');
        $log->write($message);
    }
}
