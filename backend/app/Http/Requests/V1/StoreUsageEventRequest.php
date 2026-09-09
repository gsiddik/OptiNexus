<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreUsageEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'uuid', 'exists:tenants,id'],
            'application_code' => ['required', 'string'],
            'subscription_id' => ['nullable', 'uuid', 'exists:subscriptions,id'],
            'meter_key' => ['required', 'string', 'max:100'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'usage_timestamp' => ['nullable', 'date'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after:period_start'],
            'external_reference' => ['nullable', 'string', 'max:150'],
            'idempotency_key' => ['nullable', 'string', 'max:150'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
