<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreEventCatalogEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_key' => ['required', 'string', 'max:150', 'regex:/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', 'unique:event_catalog,event_key'],
            'application_id' => ['nullable', 'uuid', 'exists:applications,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'schema_version' => ['nullable', 'string', 'max:20'],
            'payload_schema' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'event_key.regex' => 'event_key must follow the {domain}.{resource}.{event} naming convention, e.g. invoice.issued or subscription.expiring.',
        ];
    }
}
