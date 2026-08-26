<?php

namespace Opencart\System\Library\Extension\Frisbii\Reepay\Catalog\Controller\Extension\Payment;

trait Method {

    public function index(): string {
        return $this->load->view('extension/frisbii/payment/reepay_checkout', []);
    }
}
