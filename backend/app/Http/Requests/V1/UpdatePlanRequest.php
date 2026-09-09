<?php

namespace App\Http\Requests\V1;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $plan = $this->route('plan');

        return [
            'plan_code' => ['sometimes', 'string', 'max:64', Rule::unique('plans', 'plan_code')->ignore($plan?->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'billing_interval' => ['sometimes', 'string', 'in:'.implode(',', Plan::INTERVALS)],
            'trial_days' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
