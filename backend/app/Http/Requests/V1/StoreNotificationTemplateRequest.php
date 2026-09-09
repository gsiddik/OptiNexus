<?php

namespace App\Http\Requests\V1;

use App\Models\NotificationTemplate;
use Illuminate\Foundation\Http\FormRequest;

class StoreNotificationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'template_code' => ['required', 'string', 'max:100', 'unique:notification_templates,template_code'],
            'name' => ['required', 'string', 'max:255'],
            'channel' => ['required', 'string', 'in:'.implode(',', NotificationTemplate::CHANNELS)],
            'subject_template' => ['nullable', 'string', 'max:500'],
            'body_template' => ['required', 'string', 'max:10000'],
            'language' => ['nullable', 'string', 'max:10'],
        ];
    }
}
