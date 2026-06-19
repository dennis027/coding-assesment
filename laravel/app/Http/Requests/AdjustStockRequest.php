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
            'delta' => ['required', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'delta.required' => 'Delta (adjustment amount) is required.',
            'delta.integer' => 'Delta must be an integer.',
        ];
    }
}