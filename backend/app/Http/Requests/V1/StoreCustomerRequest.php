<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_code' => ['exclude'],
            'legal_name' => ['required', 'string', 'max:255'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'industry' => ['nullable', 'in:Transportation,Logistic,Government,Other'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^(?:0[0-9]{0,14}|\+[0-9]{1,15})$/'],
            'billing_address' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
