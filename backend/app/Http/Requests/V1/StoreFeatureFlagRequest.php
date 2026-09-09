<?php

namespace App\Http\Requests\V1;

use App\Models\FeatureFlag;
use App\Services\FeatureFlag\FeatureFlagValueValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreFeatureFlagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'flag_key' => ['required', 'string', 'max:150', 'regex:/^[a-z0-9_.]+$/', 'unique:feature_flags,flag_key'],
            'application_id' => ['nullable', 'uuid', 'exists:applications,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'flag_type' => ['required', 'string', 'in:'.implode(',', FeatureFlag::TYPES)],
            'default_value' => ['required'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->input('flag_type');
            if ($type && $this->has('default_value') && ! FeatureFlagValueValidator::matchesType($this->input('default_value'), $type)) {
                $validator->errors()->add('default_value', "default_value does not match flag_type {$type}.");
            }
        });
    }
}
