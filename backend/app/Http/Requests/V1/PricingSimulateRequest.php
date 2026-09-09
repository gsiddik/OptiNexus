<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class PricingSimulateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
            'plan_id' => ['required', 'uuid', 'exists:plans,id'],
            'addon_ids' => ['nullable', 'array'],
            'addon_ids.*' => ['uuid', 'exists:addons,id'],
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['numeric', 'min:0'],
        ];
    }
}
