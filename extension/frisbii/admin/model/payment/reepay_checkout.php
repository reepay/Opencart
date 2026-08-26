<?php

namespace Opencart\Admin\Model\Extension\Frisbii\Payment;

class ReepayCheckout extends \Opencart\System\Engine\Model {

    const GET_INVOICE_URL  = 'https://api.frisbii.com/v1/invoice/';
    const SETTLE_URL       = 'https://api.frisbii.com/v1/charge/';
    const REFUND_URL       = 'https://api.frisbii.com/v1/refund';

    public function getInvoice(string $invoice_handle): string|false {
        return $this->sendCurl(self::GET_INVOICE_URL . $invoice_handle);
    }

    public function settleCharge(string $invoice_handle, string|float $amount): string|false {
        $amount = $this->prepareAmount($amount);
        return $this->sendCurl(self::SETTLE_URL . $invoice_handle . '/settle', ['amount' => $amount]);
    }

    public function voidCharge(string $invoice_handle, string|float $amount): string|false {
        $amount = $this->prepareAmount($amount);
        return $this->sendCurl(self::SETTLE_URL . $invoice_handle . '/cancel', ['amount' => $amount]);
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

        if (count($params) > 0) {
            $data      = json_encode($params, JSON_PRETTY_PRINT);
            $headers[] = 'Content-Length: ' . strlen($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
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

    protected function prepareAmount(string|float $amount): int {
        return (int)(string)($amount * 100);
    }

    public function log(mixed $data): void {
        if ($this->config->get('payment_reepay_checkout_debug')) {
            $log = new \Opencart\System\Library\Log('reepay_checkout.log');
            $log->write($data);
        }
    }
}
