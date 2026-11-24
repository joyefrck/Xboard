<?php

namespace Plugin\Btcpay;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['BTCPay'] = [
                    'name' => $this->getConfig('display_name', 'BTCPay'),
                    'icon' => $this->getConfig('icon', '₿'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin'
                ];
            }
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'btcpay_url' => [
                'label' => 'API接口所在网址',
                'type' => 'string',
                'required' => true,
                'description' => '包含最后的斜杠，例如：https://your-btcpay.com/'
            ],
            'btcpay_storeId' => [
                'label' => 'Store ID',
                'type' => 'string',
                'required' => true,
                'description' => 'BTCPay商店标识符'
            ],
            'btcpay_api_key' => [
                'label' => 'API KEY',
                'type' => 'string',
                'required' => true,
                'description' => '个人设置中的API KEY(非商店设置中的)'
            ],
            'btcpay_webhook_key' => [
                'label' => 'WEBHOOK KEY',
                'type' => 'string',
                'required' => true,
                'description' => 'Webhook通知密钥'
            ],
        ];
    }

    public function pay($order): array
    {
        $params = [
            'jsonResponse' => true,
            'amount' => sprintf('%.2f', $order['total_amount'] / 100),
            'currency' => 'CNY',
            'metadata' => [
                'orderId' => $order['trade_no']
            ]
        ];

        $params_string = @json_encode($params);
        $ret_raw = $this->curlPost($this->getConfig('btcpay_url') . 'api/v1/stores/' . $this->getConfig('btcpay_storeId') . '/invoices', $params_string);
        $ret = @json_decode($ret_raw, true);

        if (empty($ret['checkoutLink'])) {
            throw new ApiException("error!");
        }
        
        return [
            'type' => 1,
            'data' => $ret['checkoutLink'],
        ];
    }

    public function notify($params): array|bool|string
    {
        $payload = trim(request()->getContent());
        $secret = $this->getConfig('btcpay_webhook_key');
        if (!is_string($secret) || trim($secret) === '') {
            return false;
        }

        $computedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($computedSignature, (string) request()->header('Btcpay-Sig', ''))) {
            throw new ApiException('HMAC signature does not match', 400);
        }

        $event = json_decode($payload, true);
        $invoiceId = is_array($event) ? ($event['invoiceId'] ?? null) : null;
        if (!is_string($invoiceId) || trim($invoiceId) === '') {
            return false;
        }

        // Webhooks also announce unpaid invoices. Only the live invoice state proves settlement.
        $invoice = $this->fetchInvoice($invoiceId);
        if (!is_array($invoice) || ($invoice['id'] ?? null) !== $invoiceId) {
            throw new ApiException('Unable to verify BTCPay invoice', 502);
        }
        if (($invoice['status'] ?? null) !== 'Settled') {
            return 'ignored';
        }

        $tradeNo = $invoice['metadata']['orderId'] ?? null;
        if (!is_string($tradeNo) || trim($tradeNo) === '') {
            return false;
        }

        return [
            'trade_no' => $tradeNo,
            'callback_no' => $invoiceId
        ];
    }

    protected function fetchInvoice(string $invoiceId): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'header' => 'Authorization: token ' . $this->getConfig('btcpay_api_key') . "\r\n"
            ]
        ]);
        $body = @file_get_contents(
            $this->getConfig('btcpay_url') . 'api/v1/stores/' . $this->getConfig('btcpay_storeId')
                . '/invoices/' . rawurlencode($invoiceId),
            false,
            $context
        );
        $invoice = is_string($body) ? json_decode($body, true) : null;

        return is_array($invoice) ? $invoice : null;
    }

    private function curlPost($url, $params = false)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array('Authorization:' . 'token ' . $this->getConfig('btcpay_api_key'), 'Content-Type: application/json')
        );
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }
}
