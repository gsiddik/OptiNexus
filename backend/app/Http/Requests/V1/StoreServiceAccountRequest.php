<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'application_id' => ['nullable', 'uuid', 'exists:applications,id'],
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
        ];
    }
}
