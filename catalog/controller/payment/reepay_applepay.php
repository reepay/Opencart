<?php

namespace Opencart\Catalog\Controller\Extension\Frisbii\Payment;

require_once DIR_EXTENSION . 'frisbii/system/library/reepay/catalog/controller/extension/payment/method.php';

class ReepayApplepay extends \Opencart\System\Engine\Controller {
    use \Opencart\System\Library\Extension\Frisbii\Reepay\Catalog\Controller\Extension\Payment\Method;
}
