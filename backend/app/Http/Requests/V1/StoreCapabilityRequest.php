<?php

namespace App\Http\Requests\V1;

use App\Models\Capability;
use Illuminate\Foundation\Http\FormRequest;

class StoreCapabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'uuid', 'exists:capabilities,id'],
            'type' => ['required', 'string', 'in:'.implode(',', Capability::TYPES)],
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
