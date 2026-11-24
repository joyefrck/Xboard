<?php

namespace Plugin\Coinbase;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['Coinbase'] = [
                    'name' => $this->getConfig('display_name', 'Coinbase'),
                    'icon' => $this->getConfig('icon', '🪙'),
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
            'coinbase_url' => [
                'label' => '接口地址',
                'type' => 'string',
                'required' => true,
                'description' => 'Coinbase Commerce API地址'
            ],
            'coinbase_api_key' => [
                'label' => 'API KEY',
                'type' => 'string',
                'required' => true,
                'description' => 'Coinbase Commerce API密钥'
            ],
            'coinbase_webhook_key' => [
                'label' => 'WEBHOOK KEY',
                'type' => 'string',
                'required' => true,
                'description' => 'Webhook签名验证密钥'
            ],
        ];
    }

    public function pay($order): array
    {
        $params = [
            'name' => '订阅套餐',
            'description' => '订单号 ' . $order['trade_no'],
            'pricing_type' => 'fixed_price',
            'local_price' => [
                'amount' => sprintf('%.2f', $order['total_amount'] / 100),
                'currency' => 'CNY'
            ],
            'metadata' => [
                "outTradeNo" => $order['trade_no'],
            ],
        ];

        $params_string = http_build_query($params);
        $ret_raw = $this->curlPost($this->getConfig('coinbase_url'), $params_string);
        $ret = @json_decode($ret_raw, true);

        if (empty($ret['data']['hosted_url'])) {
            throw new ApiException("error!");
        }
        
        return [
            'type' => 1,
            'data' => $ret['data']['hosted_url'],
        ];
    }

    public function notify($params): array|bool|string
    {
        $payload = trim(request()->getContent());
        $secret = $this->getConfig('coinbase_webhook_key');
        if (!is_string($secret) || trim($secret) === '') {
            return false;
        }

        $computedSignature = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($computedSignature, (string) request()->header('X-Cc-Webhook-Signature', ''))) {
            throw new ApiException('HMAC signature does not match', 400);
        }

        $body = json_decode($payload, true);
        $event = is_array($body) ? ($body['event'] ?? null) : null;
        if (!is_array($event)) {
            return false;
        }
        // charge:created, charge:pending and charge:failed are signed too, but are not payments.
        if (($event['type'] ?? null) !== 'charge:confirmed') {
            return 'ignored';
        }

        $tradeNo = $event['data']['metadata']['outTradeNo'] ?? null;
        $eventId = $event['id'] ?? null;
        if (!is_string($tradeNo) || trim($tradeNo) === '' || !is_string($eventId) || trim($eventId) === '') {
            return false;
        }

        return [
            'trade_no' => $tradeNo,
            'callback_no' => $eventId
        ];
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
            array('X-CC-Api-Key:' . $this->getConfig('coinbase_api_key'), 'X-CC-Version: 2018-03-22')
        );
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }
}
