<?php

namespace App\Modules\Products\Requests;

class UpdateProductRequest extends StoreProductRequest
{
    public function rules(): array
    {
        return [
            ...array_map(fn (array $rules) => ['sometimes', ...$rules], $this->catalogRules()),
            'initial_quantity' => ['missing'],
            'available_quantity' => ['missing'],
        ];
    }
}
