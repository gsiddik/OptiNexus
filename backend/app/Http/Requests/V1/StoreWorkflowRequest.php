<?php

namespace App\Http\Requests\V1;

use App\Models\Workflow;
use App\Models\WorkflowStep;
use Illuminate\Foundation\Http\FormRequest;

class StoreWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'workflow_code' => ['required', 'string', 'max:64', 'unique:workflows,workflow_code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
            'application_id' => ['nullable', 'uuid', 'exists:applications,id'],
            'trigger_type' => ['required', 'string', 'in:'.implode(',', Workflow::TRIGGERS)],
            'trigger_event_key' => ['required_if:trigger_type,EVENT', 'nullable', 'string', 'exists:event_catalog,event_key'],

            'steps' => ['required', 'array', 'min:1'],
            'steps.*.step_key' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_]+$/'],
            'steps.*.step_type' => ['required', 'string', 'in:'.implode(',', WorkflowStep::TYPES)],
            'steps.*.name' => ['nullable', 'string', 'max:255'],
            'steps.*.config' => ['nullable', 'array'],
            'steps.*.sort_order' => ['nullable', 'integer'],

            'transitions' => ['nullable', 'array'],
            'transitions.*.from_step_key' => ['required', 'string'],
            'transitions.*.to_step_key' => ['required', 'string'],
            'transitions.*.condition' => ['nullable', 'array'],
            'transitions.*.sort_order' => ['nullable', 'integer'],
        ];
    }
}
