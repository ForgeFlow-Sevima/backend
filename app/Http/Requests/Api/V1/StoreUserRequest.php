<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => [
                'required',
                'email',
                'max:150',
                Rule::unique((new User)->getTable(), 'email')
                    ->where('tenant_id', $this->user()->tenant_id),
            ],
            'role' => ['required', Rule::in(['admin', 'editor', 'viewer'])],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
