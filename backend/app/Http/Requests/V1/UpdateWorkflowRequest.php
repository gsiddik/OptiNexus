<?php

namespace App\Http\Requests\V1;

use App\Models\WorkflowStep;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkflowRequest extends FormRequest
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

            'steps' => ['sometimes', 'array', 'min:1'],
            'steps.*.step_key' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_]+$/'],
            'steps.*.step_type' => ['required', 'string', 'in:'.implode(',', WorkflowStep::TYPES)],
            'steps.*.name' => ['nullable', 'string', 'max:255'],
            'steps.*.config' => ['nullable', 'array'],
            'steps.*.sort_order' => ['nullable', 'integer'],

            'transitions' => ['sometimes', 'array'],
            'transitions.*.from_step_key' => ['required', 'string'],
            'transitions.*.to_step_key' => ['required', 'string'],
            'transitions.*.condition' => ['nullable', 'array'],
            'transitions.*.sort_order' => ['nullable', 'integer'],
        ];
    }
}
