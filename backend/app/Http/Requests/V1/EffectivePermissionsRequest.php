<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of POST /authorization/effective-permissions. Three scalars only, so the request itself is bounded; the application is
 * never taken from here but from the authenticated service account (`application_code` is optional and, when sent, must match).
 */
class EffectivePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'uuid'],
            'tenant_id' => ['required', 'uuid'],
            'application_code' => ['nullable', 'string', 'max:100'],
        ];
    }
}
