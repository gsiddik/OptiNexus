<?php

namespace App\Http\Requests\V1;

use App\Models\Policy;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'effect' => ['sometimes', 'string', 'in:'.implode(',', Policy::EFFECTS)],
            'condition_definition' => ['sometimes', 'array'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ];
    }
}
