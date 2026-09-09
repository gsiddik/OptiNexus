<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class BillingRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subscription_id' => ['nullable', 'uuid', 'exists:subscriptions,id'],
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after:period_start'],
        ];
    }
}
