<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'base_url' => ['nullable', 'string', 'max:500', 'url'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:1', 'max:120'],
            'retry_policy' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
