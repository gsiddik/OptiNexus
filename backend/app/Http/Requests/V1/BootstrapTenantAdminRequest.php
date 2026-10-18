<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A new account (`name` + `email`) or an existing one named by its id (`user_id` + `confirm_email`) - never both, and an existing
 * account is never found from an e-mail address. No password is ever accepted: Nexus generates the temporary one.
 */
class BootstrapTenantAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'uuid', 'prohibits:name,email'],
            'confirm_email' => ['required_with:user_id', 'nullable', 'email', 'max:255'],
            'name' => ['required_without:user_id', 'nullable', 'string', 'max:255'],
            'email' => ['required_without:user_id', 'nullable', 'email', 'max:255'],
            'application_ids' => ['required', 'array', 'min:1', 'max:50'],
            'application_ids.*' => ['uuid', 'distinct'],
            'role_ids' => ['sometimes', 'array', 'max:50'],
            'role_ids.*' => ['uuid', 'distinct'],
        ];
    }
}
