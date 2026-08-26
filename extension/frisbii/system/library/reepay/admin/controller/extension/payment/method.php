<?php

namespace Opencart\System\Library\Extension\Frisbii\Reepay\Admin\Controller\Extension\Payment;

trait Method {

    public function index(): void {
        $this->load->language('extension/frisbii/payment/' . $this->payment_method);

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        $data['breadcrumbs'] = [];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment')
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/frisbii/payment/' . $this->payment_method, 'user_token=' . $this->session->data['user_token'])
        ];

        $data['save'] = $this->url->link('extension/frisbii/payment/' . $this->payment_method . '.save', 'user_token=' . $this->session->data['user_token']);

        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');

        if (isset($this->request->post[$this->method_title_name])) {
            $data[$this->method_title_name] = $this->request->post[$this->method_title_name];
        } else {
            $data[$this->method_title_name] = $this->config->get($this->method_title_name);
        }

        if (isset($this->request->post[$this->method_status_name])) {
            $data[$this->method_status_name] = $this->request->post[$this->method_status_name];
        } else {
            $data[$this->method_status_name] = $this->config->get($this->method_status_name);
        }

        $data['method_title_name']  = $this->method_title_name;
        $data['method_status_name'] = $this->method_status_name;
        $data['method_title']       = $data[$this->method_title_name];
        $data['status']             = (bool)$data[$this->method_status_name];

        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/frisbii/payment/reepay_method', $data));
    }

    public function save(): void {
        $this->load->language('extension/frisbii/payment/' . $this->payment_method);

        $json = [];

        if (!$this->user->hasPermission('modify', 'extension/frisbii/payment/' . $this->payment_method)) {
            $json['error']['warning'] = $this->language->get('error_permission');
        } else {
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting('payment_' . $this->payment_method, $this->request->post);
            $json['success'] = $this->language->get('text_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function order(): string {
        $data['user_token'] = $this->session->data['user_token'];
        $data['order_id']   = $this->request->get['order_id'];

        return $this->load->view('extension/frisbii/payment/reepay_checkout_order', $data);
    }

    public function install(): void {}

    public function uninstall(): void {}
}
