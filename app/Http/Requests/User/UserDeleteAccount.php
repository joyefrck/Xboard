<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UserDeleteAccount extends FormRequest
{
    public function rules(): array
    {
        return [
            'password' => 'required|string|max:128',
            'confirmation' => 'required|in:注销账号',
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => '请输入当前密码',
            'confirmation.required' => '请确认永久注销账号',
            'confirmation.in' => '请确认永久注销账号',
        ];
    }
}
