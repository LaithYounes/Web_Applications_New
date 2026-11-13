<?php

namespace App\Support\Performance;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Resources\ProductResource;
use Illuminate\Http\Request;

/**
 * Strong ETag over the complete product JSON representation (including live stock when present).
 */
class ProductEtag
{
    public function forProduct(Product $product, Request $request): string
    {
        // Build explicitly so dynamic available_quantity is never dropped by Resource::when().
        $resource = (new ProductResource($product))->resolve($request);
        if (! array_key_exists('available_quantity', $resource)) {
            $resource['available_quantity'] = $product->getAttribute('available_quantity');
        }
        if (! array_key_exists('available_quantity', $resource) || $resource['available_quantity'] === null) {
            throw new \RuntimeException('Refusing ETag: available_quantity is missing from the representation.');
        }
        $canonical = $this->canonicalize($resource);

        return '"'.hash('sha256', $canonical).'"';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function canonicalize(array $payload): string
    {
        $this->ksortRecursive($payload);

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function ksortRecursive(array &$value): void
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->ksortRecursive($item);
            }
        }
    }
}
