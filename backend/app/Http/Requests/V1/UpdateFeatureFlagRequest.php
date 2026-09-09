<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use App\Services\FeatureFlag\FeatureFlagValueValidator;

class UpdateFeatureFlagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'default_value' => ['sometimes'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var \App\Models\FeatureFlag $flag */
            $flag = $this->route('featureFlag');
            if ($flag && $this->has('default_value') && ! FeatureFlagValueValidator::matchesType($this->input('default_value'), $flag->flag_type)) {
                $validator->errors()->add('default_value', "default_value does not match flag_type {$flag->flag_type}.");
            }
        });
    }
}
