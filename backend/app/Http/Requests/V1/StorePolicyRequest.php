<?php

namespace App\Http\Requests\V1;

use App\Models\Policy;
use Illuminate\Foundation\Http\FormRequest;

class StorePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'policy_code' => ['required', 'string', 'max:64', 'unique:policies,policy_code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'policy_type' => ['required', 'string', 'in:'.implode(',', Policy::TYPES)],
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
            'application_id' => ['nullable', 'uuid', 'exists:applications,id'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'effect' => ['required', 'string', 'in:'.implode(',', Policy::EFFECTS)],
            // condition_definition's internal safety (operator whitelist,
            // field-path shape, no unsafe expressions) is checked in
            // PolicyController and reported as POLICY_INVALID, not a
            // generic field-validation error - see PolicyConditionEvaluator::validate().
            'condition_definition' => ['required', 'array'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ];
    }
}
