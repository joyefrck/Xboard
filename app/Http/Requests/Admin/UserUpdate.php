<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UserUpdate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'id' => 'required|integer',
            'email' => 'email:strict',
            'password' => 'nullable|min:8',
            'transfer_enable' => 'numeric',
            'expired_at' => 'nullable|integer',
            'banned' => 'bool',
            'plan_id' => 'nullable|integer',
            'commission_rate' => 'nullable|integer|min:0|max:100',
            'discount' => 'nullable|integer|min:0|max:100',
            'is_admin' => 'boolean',
            'is_staff' => 'boolean',
            'u' => 'integer',
            'd' => 'integer',
            'balance' => 'numeric',
            'commission_type' => 'integer',
            'commission_balance' => 'numeric',
            'remarks' => 'nullable',
            'speed_limit' => 'nullable|integer',
            'device_limit' => 'nullable|integer',
            'traffic_package_id' => 'nullable|required_with:traffic_package_add_gb|integer|exists:v2_traffic_packages,id',
            'traffic_package_add_gb' => 'nullable|required_with:traffic_package_id|integer|min:1|max:8589934591'
        ];
    }

    public function messages()
    {
        return [
            'email.required' => '邮箱不能为空',
            'email.email' => '邮箱格式不正确',
            'transfer_enable.numeric' => '流量格式不正确',
            'expired_at.integer' => '到期时间格式不正确',
            'banned.in' => '是否封禁格式不正确',
            'is_admin.required' => '是否管理员不能为空',
            'is_admin.in' => '是否管理员格式不正确',
            'is_staff.required' => '是否员工不能为空',
            'is_staff.in' => '是否员工格式不正确',
            'plan_id.integer' => '订阅计划格式不正确',
            'commission_rate.integer' => '推荐返利比例格式不正确',
            'commission_rate.nullable' => '推荐返利比例格式不正确',
            'commission_rate.min' => '推荐返利比例最小为0',
            'commission_rate.max' => '推荐返利比例最大为100',
            'discount.integer' => '专属折扣比例格式不正确',
            'discount.nullable' => '专属折扣比例格式不正确',
            'discount.min' => '专属折扣比例最小为0',
            'discount.max' => '专属折扣比例最大为100',
            'u.integer' => '上行流量格式不正确',
            'd.integer' => '下行流量格式不正确',
            'balance.integer' => '余额格式不正确',
            'commission_balance.integer' => '佣金格式不正确',
            'password.min' => '密码长度最小8位',
            'speed_limit.integer' => '限速格式不正确',
            'device_limit.integer' => '设备数量格式不正确',
            'traffic_package_id.required_with' => '请选择流量包并填写增加流量',
            'traffic_package_id.integer' => '流量包格式不正确',
            'traffic_package_id.exists' => '流量包不存在',
            'traffic_package_add_gb.required_with' => '请选择流量包并填写增加流量',
            'traffic_package_add_gb.integer' => '增加流量必须是大于 0 的整数 GB',
            'traffic_package_add_gb.min' => '增加流量必须是大于 0 的整数 GB',
            'traffic_package_add_gb.max' => '增加流量超出系统支持范围'
        ];
    }
}
