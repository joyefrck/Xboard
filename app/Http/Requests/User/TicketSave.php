<?php

namespace App\Http\Requests\User;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TicketSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'subject' => 'required',
            'level' => ['required', 'integer', Rule::in(Ticket::MANUAL_TYPES)],
            'message' => 'required|string',
            'attachments' => 'sometimes|array|max:3',
            'attachments.*' => [
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:1024',
            ],
        ];
    }

    public function messages()
    {
        return [
            'subject.required' => __('Ticket subject cannot be empty'),
            'level.required' => __('Ticket appeal type cannot be empty'),
            'level.integer' => __('Incorrect ticket appeal type format'),
            'level.in' => __('Incorrect ticket appeal type format'),
            'message.required' => __('Message cannot be empty'),
            'attachments.array' => '图片附件格式不正确',
            'attachments.max' => '每轮最多上传3张图片',
            'attachments.*.file' => '无效的图片附件',
            'attachments.*.image' => '附件必须是图片',
            'attachments.*.mimes' => '图片仅支持 JPG、PNG、WEBP 格式',
            'attachments.*.max' => '单张图片不能超过1MB',
        ];
    }
}
