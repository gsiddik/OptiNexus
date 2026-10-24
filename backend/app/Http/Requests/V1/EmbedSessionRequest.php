<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class EmbedSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'uuid'],
            'target' => ['required', 'string', 'in:optiradar'],
            'ttl_seconds' => ['nullable', 'integer', 'min:60', 'max:900'],
        ];
    }
}
