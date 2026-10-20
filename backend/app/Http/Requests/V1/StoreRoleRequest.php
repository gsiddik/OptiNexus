<?php

namespace App\Http\Requests\V1;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['exclude'],
            'application_id' => ['nullable', 'uuid', 'exists:applications,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['exclude'],
            'description' => ['nullable', 'string'],
            'role_type' => ['required', 'string', 'in:'.implode(',', Role::TYPES)],
        ];
    }
}
