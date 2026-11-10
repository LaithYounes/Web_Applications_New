<?php

namespace App\Modules\Products\Services;

use App\Modules\Inventory\Exceptions\InventoryServiceException;
use App\Modules\Inventory\Services\InventoryGrpcClient;
use App\Modules\Products\Models\Product;
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
        private InventoryGrpcClient $inventoryClient
    ) {}

    public function getAll(?int $categoryId, int $perPage = 12): LengthAwarePaginator
    {
        return Product::query()
            ->published()
            ->with('category')
            ->when($categoryId, function ($query) use ($categoryId) {
                $query->where('category_id', $categoryId);
            })
            ->latest()
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findById(int $id): Product
    {
        $product = Product::published()->with('category')->findOrFail($id);
        $product->available_quantity = $this->inventoryClient->getStock($product->id);

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

            $product = Product::withTrashed()->where('creation_key', $creationKey)->first();
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
                ? Product::create($attributes)
                : Product::withTrashed()->createOrFirst(['creation_key' => $creationKey], $attributes);
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
            [$product, $oldImagePath] = DB::transaction(function () use ($id, $data, &$newImagePath) {
                $product = Product::published()->lockForUpdate()->findOrFail($id);
                $oldImagePath = $product->image_path;

                if (isset($data['image'])) {
                    $newImagePath = $this->storeImage($data['image']);
                    $data['image_path'] = $newImagePath;
                    unset($data['image']);
                }

                $product->update($data);

                return [$product->load('category'), $oldImagePath];
            });
        } catch (Throwable $exception) {
            if ($newImagePath !== null) {
                $this->deleteUnusedImage($newImagePath);
            }

            throw $exception;
        }

        if ($newImagePath !== null && $newImagePath !== $oldImagePath) {
            $this->deleteUnusedImage($oldImagePath);
        }

        return $product;
    }

    public function delete(int $id): void
    {
        $imagePath = DB::transaction(function () use ($id) {
            $product = Product::published()->lockForUpdate()->findOrFail($id);
            $product->delete();

            return $product->image_path;
        });

        $this->deleteUnusedImage($imagePath);
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
            if (!Product::where('image_path', $path)->exists() && !Storage::disk('public')->delete($path)) {
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
    }
}
