<?php

namespace App\Http\Requests\V1;

use App\Models\NotificationRule;
use App\Models\NotificationTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreNotificationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rule_code' => ['required', 'string', 'max:100', 'unique:notification_rules,rule_code'],
            'name' => ['required', 'string', 'max:255'],
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
            'application_id' => ['nullable', 'uuid', 'exists:applications,id'],
            'trigger_event_key' => ['nullable', 'string', 'exists:event_catalog,event_key'],
            'condition' => ['nullable', 'array'],
            'recipient_type' => ['required', 'string', 'in:'.implode(',', NotificationRule::RECIPIENT_TYPES)],
            'recipient_reference' => ['nullable', 'string', 'max:255'],
            'notification_template_id' => ['required', 'uuid', 'exists:notification_templates,id'],
            'channel' => ['required', 'string', 'in:'.implode(',', NotificationTemplate::CHANNELS)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->input('recipient_type');
            $reference = $this->input('recipient_reference');

            if (in_array($type, [NotificationRule::RECIPIENT_SPECIFIC_USER, NotificationRule::RECIPIENT_ROLE_MEMBERS, NotificationRule::RECIPIENT_EVENT_CONTEXT], true) && ! $reference) {
                $validator->errors()->add('recipient_reference', 'recipient_reference is required for this recipient_type.');
            }

            if ($type === NotificationRule::RECIPIENT_EVENT_CONTEXT && $reference && ! str_starts_with($reference, 'data.')) {
                $validator->errors()->add('recipient_reference', 'recipient_reference for EVENT_CONTEXT must start with "data." (only the trusted event payload may be used).');
            }

            $templateId = $this->input('notification_template_id');
            $channel = $this->input('channel');
            if ($templateId && $channel) {
                $templateChannel = \App\Models\NotificationTemplate::find($templateId)?->channel;
                if ($templateChannel && $templateChannel !== $channel) {
                    $validator->errors()->add('channel', 'channel must match the selected template\'s channel.');
                }
            }
        });
    }
}
