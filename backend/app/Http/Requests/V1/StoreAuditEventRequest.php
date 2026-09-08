<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreAuditEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
            'action' => ['required', 'string', 'max:150'],
            'resource_type' => ['nullable', 'string', 'max:100'],
            'resource_id' => ['nullable', 'string', 'max:100'],
            'actor_identity' => ['nullable', 'string', 'max:255'],
            'old_value' => ['nullable', 'array'],
            'new_value' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
