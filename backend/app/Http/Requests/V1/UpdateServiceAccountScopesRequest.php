<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Laravel\Passport\Passport;

class UpdateServiceAccountScopesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'allowed_scopes' => ['required', 'array', 'min:1'],
            'allowed_scopes.*' => ['string', 'distinct', Rule::in(Passport::scopeIds())],
        ];
    }
}
