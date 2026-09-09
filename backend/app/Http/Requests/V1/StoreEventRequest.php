<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['nullable', 'uuid'],
            'event_key' => ['required', 'string', 'max:150'],
            'event_version' => ['nullable', 'string', 'max:20'],
            'occurred_at' => ['nullable', 'date'],
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
            'correlation_id' => ['nullable', 'string', 'max:150'],
            'causation_id' => ['nullable', 'string', 'max:150'],
            'data' => ['nullable', 'array'],
        ];
    }
}
