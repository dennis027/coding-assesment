<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate checked at route level via auth middleware
    }

    public function rules(): array
    {
        return [
            'name'           => ['required', 'string', 'max:255'],
            'price'          => ['required', 'numeric', 'gt:0'],
            'category'       => ['required', 'string', 'max:100'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
        ];
    }
}
