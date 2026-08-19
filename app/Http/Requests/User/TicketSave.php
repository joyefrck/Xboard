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
            'message' => 'required'
        ];
    }

    public function messages()
    {
        return [
            'subject.required' => __('Ticket subject cannot be empty'),
            'level.required' => __('Ticket appeal type cannot be empty'),
            'level.integer' => __('Incorrect ticket appeal type format'),
            'level.in' => __('Incorrect ticket appeal type format'),
            'message.required' => __('Message cannot be empty')
        ];
    }
}
