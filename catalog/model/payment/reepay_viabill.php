<?php

namespace Opencart\Catalog\Model\Extension\Frisbii\Payment;

require_once DIR_EXTENSION . 'frisbii/system/library/reepay/catalog/model/extension/payment/method.php';

class ReepayViabill extends \Opencart\System\Engine\Model {
    public string $payment_method = 'reepay_viabill';
    use \Opencart\System\Library\Extension\Frisbii\Reepay\Catalog\Model\Extension\Payment\Method;
}
