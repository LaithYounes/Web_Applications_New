<?php

namespace App\Modules\Orders\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('pending_initial_quantity')],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
