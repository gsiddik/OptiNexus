<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class StorePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'application_id' => ['required', 'uuid', 'exists:applications,id'],
            'capability_id' => ['nullable', 'uuid', 'exists:capabilities,id'],
            'permission_key' => ['required', 'string', 'max:150', 'unique:permissions,permission_key', 'regex:/^[a-z0-9][a-z0-9-]*(\.[a-z0-9_-]+){2,}$/'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'permission_key.regex' => 'The permission key must follow the {application}.{resource}.{action} naming convention.',
        ];
    }
}
