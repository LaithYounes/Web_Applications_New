<?php

namespace App\Modules\Orders\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in([
                    'confirmed',
                    'processing',
                    'shipped',
                    'delivered',
                    'cancelled',
                ]),
            ],
        ];
    }
}
