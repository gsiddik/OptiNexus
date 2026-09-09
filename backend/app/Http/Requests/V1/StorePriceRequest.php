<?php

namespace App\Http\Requests\V1;

use App\Models\Price;
use Illuminate\Foundation\Http\FormRequest;

class StorePriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan_id' => ['required_without:addon_id', 'prohibits:addon_id', 'uuid', 'exists:plans,id'],
            'addon_id' => ['required_without:plan_id', 'uuid', 'exists:addons,id'],
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
            'price_type' => ['required', 'string', 'in:'.implode(',', Price::TYPES)],
            'currency' => ['required', 'string', 'size:3'],
            'unit_amount' => ['nullable', 'numeric', 'min:0', 'required_unless:price_type,TIERED'],
            'billing_interval' => ['required', 'string'],
            'unit_name' => ['nullable', 'string', 'max:50'],
            'meter_key' => ['nullable', 'string', 'max:100'],
            'minimum_quantity' => ['nullable', 'numeric', 'min:0'],
            'included_quantity' => ['nullable', 'numeric', 'min:0'],
            'tax_code_id' => ['nullable', 'uuid', 'exists:tax_codes,id'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'metadata' => ['nullable', 'array'],

            'tiers' => ['required_if:price_type,TIERED', 'array', 'min:1'],
            'tiers.*.from_quantity' => ['required_with:tiers', 'numeric', 'min:0'],
            'tiers.*.to_quantity' => ['nullable', 'numeric', 'gt:tiers.*.from_quantity'],
            'tiers.*.unit_amount' => ['required_with:tiers', 'numeric', 'min:0'],
            'tiers.*.flat_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
