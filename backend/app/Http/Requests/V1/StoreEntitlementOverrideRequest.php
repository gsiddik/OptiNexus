<?php

namespace App\Http\Requests\V1;

use App\Models\Entitlement;
use Illuminate\Foundation\Http\FormRequest;

class StoreEntitlementOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entitlement_type' => ['required', 'string', 'in:'.implode(',', Entitlement::TYPES)],
            'entitlement_key' => ['required', 'string', 'max:150'],
            'value' => ['required'],
            'application_id' => ['nullable', 'uuid', 'exists:applications,id'],
            'capability_id' => ['nullable', 'uuid', 'exists:capabilities,id'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
