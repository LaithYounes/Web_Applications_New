<?php

namespace App\Modules\Products\Services;

use App\Modules\Inventory\Exceptions\InventoryServiceException;
use App\Modules\Inventory\Services\InventoryGrpcClient;
use App\Modules\Products\Models\Product;
use App\Support\Performance\CatalogCache;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            ->useWritePdo()
            ->published()
            ->with(['category' => fn ($category) => $category->useWritePdo()])
            ->when($categoryId, function ($query) use ($categoryId) {
                $query->where('category_id', $categoryId);
            })
            ->latest()
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        if (! config('perf.catalog_cache_enabled')) {
            return $resolve();
        }

        return $this->catalogCache->productsList($categoryId, $perPage, $page, $resolve);
    }

    public function findById(int $id): Product
    {
        // Conditional validators must use committed primary fields and relations.
        $product = Product::query()
            ->useWritePdo()
            ->published()
            ->with(['category' => fn ($category) => $category->useWritePdo()])
            ->findOrFail($id);
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

            $product = Product::withTrashed()->useWritePdo()->where('creation_key', $creationKey)->first();
            if ($product !== null) {
                return $this->completeCreation($product, $fingerprint);
            }
        }

        $imagePath = $this->storeImage($image);

        $attributes = [
            ...$data,
            'image_path' => $imagePath,
            'pending_initial_quantity' => $initialQuantity > 0 ? $initialQuantity : null,
            'creation_fingerprint' => $fingerprint,
        ];

        try {
            $product = $creationKey === null
                ? $this->catalogCache->mutate(fn () => Product::create($attributes))
                : $this->catalogCache->mutate(fn () => Product::withTrashed()->createOrFirst(['creation_key' => $creationKey], $attributes));
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($imagePath);
            throw $exception;
        }

        if ($product->image_path !== $imagePath) {
            Storage::disk('public')->delete($imagePath);
        }

        return $this->completeCreation($product, $fingerprint);
    }

    public function update(int $id, array $data): Product
    {
        $newImagePath = null;

        try {
            $product = $this->catalogCache->mutate(function () use ($id, $data, &$newImagePath) {
                $product = Product::published()->lockForUpdate()->findOrFail($id);
                $oldImagePath = $product->image_path;

                if (isset($data['image'])) {
                    $newImagePath = $this->storeImage($data['image']);
                    $data['image_path'] = $newImagePath;
                    unset($data['image']);
                }

                if (! $product->update($data)) {
                    throw new RuntimeException('The product could not be saved.');
                }

                if ($newImagePath !== null && $newImagePath !== $oldImagePath) {
                    DB::afterCommit(fn () => $this->deleteUnusedImage($oldImagePath));
                }

                return $product->load('category');
            });
        } catch (Throwable $exception) {
            if ($newImagePath !== null) {
                $this->deleteUnusedImage($newImagePath);
            }

            throw $exception;
        }

        if ($newImagePath !== null && DB::transactionLevel() > 0) {
            // Register on the caller's still-open transaction, after our savepoint
            // commits. Rollback callbacks on committed savepoints are discarded.
            DB::afterRollBack(fn () => $this->deleteUnusedImage($newImagePath));
        }

        return $product;
    }

    public function delete(int $id): void
    {
        $this->catalogCache->mutate(function () use ($id) {
            $product = Product::published()->lockForUpdate()->findOrFail($id);
            if (! $product->delete()) {
                throw new RuntimeException('The product could not be deleted.');
            }

            DB::afterCommit(fn () => $this->deleteUnusedImage($product->image_path));
        });
    }

    private function storeImage(UploadedFile $image): string
    {
        $path = $image->store('products', 'public');
        if (!is_string($path) || $path === '') {
            throw new RuntimeException('The product image could not be saved.');
        }

        return $path;
    }

    private function deleteUnusedImage(string $path): void
    {
        try {
            if (!Product::useWritePdo()->where('image_path', $path)->exists() && !Storage::disk('public')->delete($path)) {
                Log::warning('Could not remove product image.', ['path' => $path]);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function completeCreation(Product $product, ?string $fingerprint): Product
    {
        abort_if($product->trashed(), 409, 'Idempotency key already used for a deleted product.');
        abort_if($product->creation_fingerprint !== $fingerprint, 409, 'Idempotency key already used for a different product.');

        $this->finishInitialStock($product);

        return $product->load(['category' => fn ($category) => $category->useWritePdo()]);
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

        $this->catalogCache->mutate(function () use ($product) {
            if (! $product->update(['pending_initial_quantity' => null])) {
                throw new RuntimeException('The product could not be published.');
            }
        });
    }
}
