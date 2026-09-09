<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'subject_template' => ['nullable', 'string', 'max:500'],
            'body_template' => ['sometimes', 'string', 'max:10000'],
            'status' => ['sometimes', 'string', 'in:DRAFT,ACTIVE,INACTIVE'],
        ];
    }
}
