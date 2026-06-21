<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delta' => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    if ((int) $value === 0) {
                        $fail('Delta must be a non-zero integer.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'delta.not_in' => 'Delta must be a non-zero integer.',
        ];
    }
}