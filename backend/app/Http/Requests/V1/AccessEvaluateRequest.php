<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class AccessEvaluateRequest extends FormRequest
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
            'entitlement_key' => ['nullable', 'string'],
            'feature_flag_key' => ['nullable', 'string'],
            'action' => ['nullable', 'string'],
            'resource_context' => ['nullable', 'array'],
        ];
    }
}
