<?php


namespace App\Http\Requests\Admin;

use App\Models\Server;
use App\Models\NodeAutomationGroup;
use App\Services\VlessConfigService;
use Illuminate\Foundation\Http\FormRequest;

class ServerSave extends FormRequest
{
    private const PROTOCOL_RULES = [
        'shadowsocks' => [
            'cipher' => 'required|string',
            'obfs' => 'nullable|string',
            'obfs_settings.path' => 'nullable|string',
            'obfs_settings.host' => 'nullable|string',
            'plugin' => 'nullable|string',
            'plugin_opts' => 'nullable|string',
        ],
        'vmess' => [
            'tls' => 'required|integer',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'tls_settings.server_name' => 'nullable|string',
            'tls_settings.allow_insecure' => 'nullable|boolean',
        ],
        'trojan' => [
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'server_name' => 'nullable|string',
            'allow_insecure' => 'nullable|boolean',
        ],
        'hysteria' => [
            'version' => 'required|integer',
            'alpn' => 'nullable|string',
            'obfs.open' => 'nullable|boolean',
            'obfs.type' => 'string|nullable',
            'obfs.password' => 'string|nullable',
            'tls.server_name' => 'nullable|string',
            'tls.allow_insecure' => 'nullable|boolean',
            'bandwidth.up' => 'nullable|integer',
            'bandwidth.down' => 'nullable|integer',
            'hop_interval' => 'integer|nullable',
        ],
        'vless' => [
            'utls' => 'nullable|array',
            'encryption' => 'nullable|array',
            'backend' => 'nullable|array',
            'reality_settings' => 'nullable|array',
            'tls_settings' => 'nullable|array',
            'utls.enabled' => 'nullable|boolean',
            'utls.fingerprint' => 'nullable|string',
            'encryption.enabled' => 'nullable|boolean',
            'encryption.encryption' => 'nullable|string',
            'encryption.decryption' => 'nullable|string',
            'backend.core' => 'nullable|in:xray,sing',
            'backend.release' => 'nullable|string',
            'reality_settings.dest' => 'nullable|string',
            'tls' => 'required|integer',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'flow' => 'nullable|string',
            'tls_settings.server_name' => 'nullable|string',
            'tls_settings.allow_insecure' => 'nullable|boolean',
            'reality_settings.allow_insecure' => 'nullable|boolean',
            'reality_settings.server_name' => 'nullable|string',
            'reality_settings.server_port' => 'nullable|integer',
            'reality_settings.public_key' => 'nullable|string',
            'reality_settings.private_key' => 'nullable|string',
            'reality_settings.short_id' => 'nullable|string',
        ],
        'socks' => [
        ],
        'naive' => [
            'tls' => 'required|integer',
            'tls_settings' => 'nullable|array',
        ],
        'http' => [
            'tls' => 'required|integer',
            'tls_settings' => 'nullable|array',
        ],
        'mieru' => [
            'transport' => 'required|string',
            'multiplexing' => 'required|string',
        ],
        'anytls' => [
            'tls' => 'nullable|array',
            'alpn' => 'nullable|string',
            'padding_scheme' => 'nullable|array',
        ],
    ];

    private function getBaseRules(): array
    {
        return [
            'id' => 'nullable|integer',
            'type' => 'required|in:' . implode(',', Server::VALID_TYPES),
            'spectific_key' => 'nullable|string',
            'code' => 'nullable|string',
            'show' => '',
            'name' => 'required|string',
            'group_ids' => 'nullable|array',
            'route_ids' => 'nullable|array',
            'parent_id' => 'nullable|integer',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'tags' => 'nullable|array',
            'excludes' => 'nullable|array',
            'ips' => 'nullable|array',
            'rate' => 'required|numeric',
            'rate_time_enable' => 'nullable|boolean',
            'rate_time_ranges' => 'nullable|array',
            'rate_time_ranges.*.start' => 'required_with:rate_time_ranges|string|date_format:H:i',
            'rate_time_ranges.*.end' => 'required_with:rate_time_ranges|string|date_format:H:i',
            'rate_time_ranges.*.rate' => 'required_with:rate_time_ranges|numeric|min:0',
            'protocol_settings' => 'array',
        ];
    }

    public function rules(): array
    {
        $type = $this->input('type');
        $rules = $this->getBaseRules();

        foreach (self::PROTOCOL_RULES[$type] ?? [] as $field => $rule) {
            $rules['protocol_settings.' . $field] = $rule;
        }

        return $rules;
    }

    private function keepsExistingProtocol(array $settings): bool
    {
        if (!$id = $this->input('id')) return false;
        $existing = Server::find($id);
        if (!$existing || $existing->type !== Server::TYPE_VLESS) return false;
        $candidate = (new Server())->forceFill(['type' => Server::TYPE_VLESS, 'protocol_settings' => $settings]);
        return $candidate->protocol_settings == $existing->protocol_settings;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('type') !== Server::TYPE_VLESS || $validator->errors()->isNotEmpty()) return;
            $settings = $this->input('protocol_settings', []);
            if (!$this->keepsExistingProtocol($settings)) {
                foreach (VlessConfigService::errors($settings) as $field => $message) $validator->errors()->add($field, $message);
            }
            if ((VlessConfigService::isEnhanced($settings) || VlessConfigService::needsHybridReality($settings)) && NodeAutomationGroup::where('domain', strtolower(rtrim((string) $this->input('host'), '.')))->where('enabled', true)->exists()) {
                $validator->errors()->add('protocol_settings.encryption.enabled', '请先停用此域名的自动切换；当前探针尚未验证增强 VLESS 或新版 REALITY');
            }
        });
    }

    public function messages()
    {
        return [
            'name.required' => '节点名称不能为空',
            'group_ids.required' => '权限组不能为空',
            'group_ids.array' => '权限组格式不正确',
            'route_ids.array' => '路由组格式不正确',
            'parent_id.integer' => '父ID格式不正确',
            'host.required' => '节点地址不能为空',
            'port.required' => '连接端口不能为空',
            'server_port.required' => '后端服务端口不能为空',
            'tls.required' => 'TLS不能为空',
            'tags.array' => '标签格式不正确',
            'rate.required' => '倍率不能为空',
            'rate.numeric' => '倍率格式不正确',
            'network.required' => '传输协议不能为空',
            'network.in' => '传输协议格式不正确',
            'networkSettings.array' => '传输协议配置有误',
            'ruleSettings.array' => '规则配置有误',
            'tlsSettings.array' => 'tls配置有误',
            'dnsSettings.array' => 'dns配置有误'
        ];
    }
}
