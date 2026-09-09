<?php

namespace App\Http\Requests\V1;

use App\Models\NotificationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'condition' => ['nullable', 'array'],
            'recipient_reference' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', NotificationRule::STATUSES)],
        ];
    }
}
