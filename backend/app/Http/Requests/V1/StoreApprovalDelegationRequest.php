<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreApprovalDelegationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delegate_user_id' => ['required', 'uuid', 'exists:users,id'],
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
            'approval_definition_id' => ['nullable', 'uuid', 'exists:approval_definitions,id'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ];
    }
}
