<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class AuthorizationCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'uuid'],
            'tenant_id' => ['nullable', 'uuid'],
            'application_code' => ['required', 'string'],
            'permission' => ['required', 'string'],
            'resource_context' => ['nullable', 'array'],
        ];
    }
}
