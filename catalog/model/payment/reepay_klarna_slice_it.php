<?php

namespace Opencart\Catalog\Model\Extension\Frisbii\Payment;

require_once DIR_EXTENSION . 'frisbii/system/library/reepay/catalog/model/extension/payment/method.php';

class ReepayKlarnaSliceIt extends \Opencart\System\Engine\Model {
    public string $payment_method = 'reepay_klarna_slice_it';
    use \Opencart\System\Library\Extension\Frisbii\Reepay\Catalog\Model\Extension\Payment\Method;
}
