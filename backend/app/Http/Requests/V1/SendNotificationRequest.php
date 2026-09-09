<?php

namespace App\Http\Requests\V1;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SendNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'template_code' => ['required', 'string', 'exists:notification_templates,template_code'],
            'recipient_user_ids' => ['required', 'array', 'min:1'],
            'recipient_user_ids.*' => ['uuid', 'exists:users,id'],
            'context' => ['nullable', 'array'],
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
            'application_id' => ['nullable', 'uuid', 'exists:applications,id'],
            'correlation_id' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $tenantId = $this->input('tenant_id');
            if (! $tenantId) {
                return;
            }

            foreach ($this->input('recipient_user_ids', []) as $i => $userId) {
                $belongs = User::whereKey($userId)->whereHas('tenantMemberships', fn ($q) => $q->where('tenant_id', $tenantId))->exists();
                if (! $belongs) {
                    $validator->errors()->add("recipient_user_ids.{$i}", 'This recipient does not belong to the given tenant.');
                }
            }
        });
    }
}
