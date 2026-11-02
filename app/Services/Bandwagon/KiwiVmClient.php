<?php

namespace App\Services\Bandwagon;

use App\Models\BandwagonVps;
use Illuminate\Support\Facades\Http;

class KiwiVmClient
{
    /** No arbitrary endpoints, redirects, automatic retries, or credentials in URLs. */
    public function call(BandwagonVps $vps, string $endpoint): array
    {
        if (!in_array($endpoint, ['getServiceInfo', 'getLiveServiceInfo', 'resetRootPassword'], true)) {
            throw new BandwagonException('不支持此操作');
        }
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(22)
                ->withOptions(['allow_redirects' => false])->post('https://api.64clouds.com/v1/' . $endpoint,
                    ['veid' => $vps->veid, 'api_key' => $vps->api_key]);
        } catch (\Throwable $e) {
            // Never include transport exceptions: they can contain request bodies.
            throw new BandwagonException('搬瓦工接口连接失败或超时');
        }
        if ($response->status() === 429) throw new BandwagonException('搬瓦工接口限流，请稍后刷新');
        if (!$response->successful()) throw new BandwagonException('搬瓦工接口 HTTP ' . $response->status());
        $data = $response->json();
        if (!is_array($data) || !array_key_exists('error', $data) || !is_numeric($data['error'])) {
            throw new BandwagonException('搬瓦工接口响应格式异常');
        }
        if ((float) $data['error'] !== 0.0) {
            // Supplier messages can echo credentials; expose only the numeric code.
            throw new BandwagonException('搬瓦工接口拒绝请求（错误码 ' . (int) $data['error'] . '）');
        }
        return $data;
    }
}
