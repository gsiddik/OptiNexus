<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class PolicySimulateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'policy_type' => ['nullable', 'string'],
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
            'application_id' => ['nullable', 'uuid', 'exists:applications,id'],
            'permission' => ['nullable', 'string'],
            'action' => ['nullable', 'string'],
            'actor' => ['nullable', 'array'],
            'resource' => ['nullable', 'array'],
            'context' => ['nullable', 'array'],
        ];
    }
}
