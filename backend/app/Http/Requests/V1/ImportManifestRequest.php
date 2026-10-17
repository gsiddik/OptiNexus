<?php

namespace App\Http\Requests\V1;

use App\Models\Capability;
use App\Services\Manifest\ManifestImportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Body of POST /applications/{application}/manifest: a product's versioned catalog (see ManifestImportService). */
class ImportManifestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public static function manifestRules(): array
    {
        return [
            'manifest_version' => ['required', 'string', Rule::in(ManifestImportService::SUPPORTED_VERSIONS)],
            'revision' => ['required', 'string', 'max:100'],
            'capabilities' => ['sometimes', 'array', 'max:500'],
            'capabilities.*.type' => ['required', Rule::in(Capability::TYPES)],
            'capabilities.*.code' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'capabilities.*.name' => ['required', 'string', 'max:255'],
            'capabilities.*.description' => ['nullable', 'string', 'max:1000'],
            'capabilities.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'capabilities.*.parent_code' => ['nullable', 'string', 'max:100'],
            'permissions' => ['sometimes', 'array', 'max:2000'],
            'permissions.*.permission_key' => ['required', 'string', 'max:150', 'regex:/^[a-z][a-z0-9_-]*(\.[A-Za-z0-9_-]+)+$/'],
            'permissions.*.name' => ['required', 'string', 'max:255'],
            'permissions.*.description' => ['nullable', 'string', 'max:1000'],
            'permissions.*.capability_code' => ['nullable', 'string', 'max:100'],
            'permissions.*.delegable' => ['sometimes', 'boolean'],
        ];
    }

    public function rules(): array
    {
        return self::manifestRules() + [
            'dry_run' => ['sometimes', 'boolean'],
            'allow_delegable_widening' => ['sometimes', 'boolean'],
        ];
    }
}
