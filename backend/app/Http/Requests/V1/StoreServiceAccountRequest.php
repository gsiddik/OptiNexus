<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Laravel\Passport\Passport;

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
            'allowed_scopes' => ['nullable', 'array', 'min:1'],
            'allowed_scopes.*' => ['string', 'distinct', Rule::in(Passport::scopeIds())],
        ];
    }
}
