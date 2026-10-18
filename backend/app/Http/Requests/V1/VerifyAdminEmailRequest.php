<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

/** The address the operator checked and why it can be trusted (method of the out-of-band check). No documents are collected. */
class VerifyAdminEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'reason' => ['required', 'string', 'min:'.config('activation.reason_min_length'), 'max:'.config('activation.reason_max_length')],
        ];
    }
}
