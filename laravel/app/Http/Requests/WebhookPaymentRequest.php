<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WebhookPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Webhook endpoints are not user-authenticated; provider auth
        // (HMAC signature, IP allowlist, etc.) is handled in middleware.
        return true;
    }

    public function rules(): array
    {
        return [
            'provider'        => ['required', 'string', 'max:50'],
            'transaction_id'  => ['required', 'string', 'max:100'],
            'order_reference' => ['required', 'string', 'max:100'],
            'amount'          => ['required', 'numeric', 'min:0'],
            'currency'        => ['required', 'string', 'size:3'],
            'msisdn'          => ['nullable', 'string', 'max:20'],
            'status'          => ['required', 'string', 'in:completed,failed,reversed,pending'],
            'occurred_at'     => ['required', 'date'],
        ];
    }
}
