<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid', 'exists:customers,id'],
            'tenant_id' => ['required', 'uuid', 'exists:tenants,id'],
            'plan_id' => ['required', 'uuid', 'exists:plans,id'],
            'price_items' => ['sometimes', 'array', 'min:1', 'max:100'],
            'price_items.*.price_id' => ['required', 'uuid', 'exists:prices,id'],
            'price_items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'addon_ids' => ['nullable', 'array'],
            'addon_ids.*' => ['uuid', 'exists:addons,id'],
            'grace_period_days' => ['sometimes', 'required', 'integer', 'min:0', 'max:3650'],
            'auto_renew' => ['nullable', 'boolean'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
