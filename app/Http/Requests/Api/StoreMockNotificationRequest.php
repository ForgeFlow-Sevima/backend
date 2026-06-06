<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMockNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(['email', 'sms', 'slack', 'webhook'])],
            'recipient' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:2000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
