<?php

namespace App\Http\Requests\V1;

use App\Models\ApprovalDefinition;
use App\Models\ApprovalLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreApprovalDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'definition_code' => ['required', 'string', 'max:64', 'unique:approval_definitions,definition_code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
            'application_id' => ['nullable', 'uuid', 'exists:applications,id'],
            'rule_type' => ['nullable', 'string', 'in:'.implode(',', ApprovalDefinition::RULE_TYPES)],
            'self_approval_allowed' => ['nullable', 'boolean'],
            'expires_after_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],

            'levels' => ['required', 'array', 'min:1'],
            'levels.*.level_order' => ['required', 'integer', 'min:1'],
            'levels.*.name' => ['required', 'string', 'max:255'],
            'levels.*.approver_type' => ['required', 'string', 'in:'.implode(',', ApprovalLevel::APPROVER_TYPES)],
            'levels.*.approver_reference' => ['nullable', 'string', 'max:150'],
            'levels.*.condition' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $levels = $this->input('levels', []);
            $orders = array_map(fn ($l) => $l['level_order'] ?? null, $levels);
            if (count($orders) !== count(array_unique($orders))) {
                $validator->errors()->add('levels', 'level_order values must be unique.');
            }

            foreach ($levels as $i => $level) {
                $type = $level['approver_type'] ?? null;
                if (in_array($type, [ApprovalLevel::APPROVER_USER, ApprovalLevel::APPROVER_ROLE, ApprovalLevel::APPROVER_TENANT_ROLE, ApprovalLevel::APPROVER_APPLICATION_ROLE], true) && empty($level['approver_reference'])) {
                    $validator->errors()->add("levels.{$i}.approver_reference", 'approver_reference is required for this approver_type.');
                }
            }
        });
    }
}
