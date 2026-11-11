<?php

namespace App\Modules\Products\Services;

use App\Modules\Inventory\Exceptions\InventoryServiceException;
use App\Modules\Inventory\Services\InventoryGrpcClient;
use App\Modules\Products\Models\Product;
use App\Support\Performance\CatalogCache;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProductService
{
    public function __construct(
        private InventoryGrpcClient $inventoryClient,
        private CatalogCache $catalogCache,
    ) {}

    public function getAll(?int $categoryId, int $perPage = 12): LengthAwarePaginator
    {
        $page = max(1, (int) request()->input('page', 1));

        $resolve = fn (): LengthAwarePaginator => Product::query()
            ->published()
            ->with('category')
            ->when($categoryId, function ($query) use ($categoryId) {
                $query->where('category_id', $categoryId);
            })
            ->latest()
            ->orderByDesc('id')
            ->paginate($perPage);

        if (! config('perf.catalog_cache_enabled')) {
            return $resolve();
        }

        return $this->catalogCache->productsList($categoryId, $perPage, $page, $resolve);
    }

    public function findById(int $id): Product
    {
        $product = Product::published()->with('category')->findOrFail($id);
        $product->setAttribute(
            'available_quantity',
            $this->inventoryClient->getStock($product->id)
        );

        return $product;
    }

    public function create(array $data, ?string $creationKey = null): Product
    {
        /** @var UploadedFile $image */
        $image = $data['image'];

        $initialQuantity = (int) $data['initial_quantity'];
        unset($data['image'], $data['initial_quantity']);

        $fingerprint = null;
        if ($creationKey !== null) {
            $fingerprint = hash('sha256', json_encode([
                (int) $data['category_id'],
                $data['name'],
                $data['description'] ?? null,
                number_format((float) $data['price'], 2, '.', ''),
                $initialQuantity,
                hash_file('sha256', $image->getPathname()),
            ], JSON_THROW_ON_ERROR));

            $product = Product::where('creation_key', $creationKey)->first();
            if ($product !== null) {
                return $this->completeCreation($product, $fingerprint);
            }
        }

        $imagePath = $image->store('products', 'public');
        if (!is_string($imagePath) || $imagePath === '') {
            throw new RuntimeException('The product image could not be saved.');
        }

        $attributes = [
            ...$data,
            'image_path' => $imagePath,
            'pending_initial_quantity' => $initialQuantity > 0 ? $initialQuantity : null,
            'creation_fingerprint' => $fingerprint,
        ];

        try {
            $product = $creationKey === null
                ? Product::create($attributes)
                : Product::createOrFirst(['creation_key' => $creationKey], $attributes);
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($imagePath);
            throw $exception;
        }

        if ($product->image_path !== $imagePath) {
            Storage::disk('public')->delete($imagePath);
        }

        $created = $this->completeCreation($product, $fingerprint);
        $this->catalogCache->invalidate();

        return $created;
    }

    private function completeCreation(Product $product, ?string $fingerprint): Product
    {
        abort_if($product->creation_fingerprint !== $fingerprint, 409, 'Idempotency key already used for a different product.');

        $this->finishInitialStock($product);

        return $product->load('category');
    }

    public function finishInitialStock(Product $product): void
    {
        if ($product->pending_initial_quantity === null) {
            return;
        }

        // The product ID identifies the same initialization even if a previous RPC committed before timing out.
        retry(
            2,
            fn () => $this->inventoryClient->initializeStock($product->id, $product->pending_initial_quantity),
            100,
            fn (Throwable $exception) => $exception instanceof InventoryServiceException,
        );

        $product->update(['pending_initial_quantity' => null]);
        $this->catalogCache->invalidate();
    }
}
