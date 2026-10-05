<?php

namespace Opencart\System\Library\Extension\Frisbii\Reepay\Catalog\Model\Extension\Payment;

trait Method {

    public function getMethods(array $address = []): array {
        $this->load->language('extension/frisbii/payment/reepay_checkout');

        if (!$this->config->get('payment_' . $this->payment_method . '_status')) {
            return [];
        }

        if (!$this->config->get('payment_reepay_checkout_geo_zone_id')) {
            $status = true;
        } else {
            $this->load->model('localisation/geo_zone');
            $results = $this->model_localisation_geo_zone->getGeoZone(
                (int)$this->config->get('payment_reepay_checkout_geo_zone_id'),
                (int)($address['country_id'] ?? 0),
                (int)($address['zone_id'] ?? 0)
            );
            $status = (bool)$results;
        }

        if (!$status) {
            return [];
        }

        $method_title_settings = $this->config->get('payment_' . $this->payment_method . '_method_title');
        $method_title = (strlen((string)$method_title_settings) > 3)
            ? $method_title_settings
            : $this->language->get('text_title_' . $this->payment_method);

        $option_data[$this->payment_method] = [
            'code' => $this->payment_method . '.' . $this->payment_method,
            'name' => $method_title,
        ];

        $img_style = 'height:25px;width:auto;vertical-align:middle;border-radius:4px;border:1px solid #f0f0f0;margin-right:8px;';
        $img_src   = 'extension/frisbii/image/reepay/' . $this->payment_method . '.png';
        $name_with_logo = '<img src="' . $img_src . '" style="' . $img_style . '" alt="' . htmlspecialchars($method_title) . '"/>' . $method_title;

        return [
            'code'       => $this->payment_method,
            'name'       => $name_with_logo,
            'option'     => $option_data,
            'sort_order' => $this->config->get('payment_reepay_checkout_sort_order'),
        ];
    }
}
