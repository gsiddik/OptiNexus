<?php

namespace App\Http\Requests\V1;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;

class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'plan_code' => ['required', 'string', 'max:64', 'unique:plans,plan_code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'billing_interval' => ['required', 'string', 'in:'.implode(',', Plan::INTERVALS)],
            'trial_days' => ['nullable', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
