<?php

namespace App\Http\Requests\V1;

use App\Models\FeatureFlag;
use App\Services\FeatureFlag\FeatureFlagValueValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreFeatureFlagOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope_type' => ['required', 'string', 'in:'.implode(',', FeatureFlag::SCOPES)],
            'scope_id' => ['nullable', 'uuid'],
            'value' => ['required'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var FeatureFlag $flag */
            $flag = $this->route('featureFlag');
            $scopeType = $this->input('scope_type');

            $scopeId = $this->input('scope_id');

            if ($scopeType !== FeatureFlag::SCOPE_GLOBAL && ! $scopeId) {
                $validator->errors()->add('scope_id', 'scope_id is required for this scope_type.');
            } elseif ($scopeId) {
                $exists = match ($scopeType) {
                    FeatureFlag::SCOPE_TENANT => \App\Models\Tenant::whereKey($scopeId)->exists(),
                    FeatureFlag::SCOPE_APPLICATION => \App\Models\Application::whereKey($scopeId)->exists(),
                    FeatureFlag::SCOPE_USER => \App\Models\User::whereKey($scopeId)->exists(),
                    default => true,
                };
                if (! $exists) {
                    $validator->errors()->add('scope_id', 'scope_id does not reference an existing record for this scope_type.');
                }

                // A tenant-scoped override on a flag pinned to a specific
                // application must not target a tenant unrelated to that
                // application - prevents cross-tenant override leakage.
                if ($exists && $scopeType === FeatureFlag::SCOPE_TENANT && $flag?->application_id) {
                    $assigned = \App\Models\Tenant::whereKey($scopeId)->first()
                        ?->applications()->where('applications.id', $flag->application_id)->exists();
                    if (! $assigned) {
                        $validator->errors()->add('scope_id', 'This tenant is not assigned to the application this flag belongs to.');
                    }
                }
            }

            if ($flag && $this->has('value') && ! FeatureFlagValueValidator::matchesType($this->input('value'), $flag->flag_type)) {
                $validator->errors()->add('value', "value does not match flag_type {$flag->flag_type}.");
            }
        });
    }
}
