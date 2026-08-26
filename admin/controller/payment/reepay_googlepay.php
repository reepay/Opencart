<?php

namespace Opencart\Admin\Controller\Extension\Frisbii\Payment;

require_once DIR_EXTENSION . 'frisbii/system/library/reepay/admin/controller/extension/payment/method.php';

class ReepayGooglepay extends \Opencart\System\Engine\Controller {

    public string $payment_method    = 'reepay_googlepay';
    public string $method_title_name  = 'payment_reepay_googlepay_method_title';
    public string $method_status_name = 'payment_reepay_googlepay_status';

    use \Opencart\System\Library\Extension\Frisbii\Reepay\Admin\Controller\Extension\Payment\Method;
}
