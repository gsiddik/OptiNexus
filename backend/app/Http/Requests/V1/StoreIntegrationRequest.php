<?php

namespace App\Http\Requests\V1;

use App\Models\Integration;
use App\Models\IntegrationEndpoint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'integration_code' => ['required', 'string', 'max:64', 'unique:integrations,integration_code'],
            'name' => ['required', 'string', 'max:255'],
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
            'source_application_id' => ['required', 'uuid', 'exists:applications,id'],
            'target_application_id' => ['nullable', 'uuid', 'exists:applications,id'],
            'integration_type' => ['required', 'string', 'in:'.implode(',', Integration::TYPES)],
            'base_url' => ['nullable', 'string', 'max:500', 'url'],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:120'],
            'retry_policy' => ['nullable', 'array'],
            'retry_policy.max_attempts' => ['nullable', 'integer', 'min:0', 'max:10'],
            'retry_policy.backoff_seconds' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],

            'endpoints' => ['nullable', 'array'],
            'endpoints.*.endpoint_key' => ['required', 'string', 'max:100'],
            'endpoints.*.method' => ['required', 'string', 'in:'.implode(',', IntegrationEndpoint::METHODS)],
            'endpoints.*.path' => ['required', 'string', 'max:255'],
            'endpoints.*.headers_template' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $url = $this->input('base_url');
            $type = $this->input('integration_type');
            if ($url && in_array($type, [Integration::TYPE_REST, Integration::TYPE_WEBHOOK], true)
                && app()->environment('production') && ! str_starts_with($url, 'https://')) {
                $validator->errors()->add('base_url', 'HTTPS is required for production REST/WEBHOOK integration endpoints.');
            }
        });
    }
}
