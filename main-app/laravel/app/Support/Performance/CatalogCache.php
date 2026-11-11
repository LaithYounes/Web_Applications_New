<?php

namespace App\Support\Performance;

use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Catalog read-through cache for MySQL product/category listings only.
 * Never stores inventory quantities, reservations, carts, or orders.
 */
class CatalogCache
{
    public const VERSION_KEY = 'catalog:version';

    public const PRODUCTS_TTL_SECONDS = 60;

    public const CATEGORIES_TTL_SECONDS = 120;

    public int $hits = 0;

    public int $misses = 0;

    public ?string $lastKey = null;

    public function productsList(?int $categoryId, int $perPage, int $page, callable $resolver): LengthAwarePaginator
    {
        $key = $this->productsKey($categoryId, $perPage, $page);

        return $this->rememberPaginator($key, self::PRODUCTS_TTL_SECONDS, $resolver, Product::class, 'category');
    }

    public function categoriesList(int $page, callable $resolver): LengthAwarePaginator
    {
        $key = $this->categoriesKey($page);

        return $this->rememberPaginator($key, self::CATEGORIES_TTL_SECONDS, $resolver, Category::class, null, ['products_count']);
    }

    public function invalidate(): void
    {
        try {
            Cache::forever(self::VERSION_KEY, $this->version() + 1);
        } catch (Throwable $exception) {
            Log::warning('Catalog cache invalidation skipped.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function version(): int
    {
        try {
            return (int) Cache::get(self::VERSION_KEY, 1);
        } catch (Throwable) {
            return 1;
        }
    }

    public function productsKey(?int $categoryId, int $perPage, int $page): string
    {
        $category = $categoryId === null ? 'all' : (string) $categoryId;

        return sprintf(
            'catalog:v%d:products:cat:%s:per:%d:page:%d:sort:latest-id',
            $this->version(),
            $category,
            $perPage,
            $page
        );
    }

    public function categoriesKey(int $page): string
    {
        return sprintf(
            'catalog:v%d:categories:per:15:page:%d:sort:name:withCount:products',
            $this->version(),
            $page
        );
    }

    /**
     * @param  class-string  $modelClass
     * @param  list<string>  $extraAttributes
     */
    private function rememberPaginator(
        string $key,
        int $ttl,
        callable $resolver,
        string $modelClass,
        ?string $relation = null,
        array $extraAttributes = [],
    ): LengthAwarePaginator {
        $this->lastKey = $key;

        $cached = null;
        try {
            $cached = Cache::get($key);
        } catch (Throwable $exception) {
            Log::warning('Catalog cache read failed; using database.', [
                'key' => $key,
                'error' => $exception->getMessage(),
            ]);
        }

        if (is_array($cached)) {
            $this->hits++;

            return $this->restorePaginator($cached, $modelClass, $relation, $extraAttributes);
        }

        $this->misses++;

        /** @var LengthAwarePaginator $paginator */
        $paginator = $resolver();

        try {
            Cache::put($key, $this->serializePaginator($paginator, $relation, $extraAttributes), $ttl);
        } catch (Throwable $exception) {
            Log::warning('Catalog cache write failed; response still served from database.', [
                'key' => $key,
                'error' => $exception->getMessage(),
            ]);
        }

        return $paginator;
    }

    /**
     * @param  list<string>  $extraAttributes
     * @return array<string, mixed>
     */
    private function serializePaginator(LengthAwarePaginator $paginator, ?string $relation, array $extraAttributes): array
    {
        return [
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'path' => $paginator->path(),
            'items' => $paginator->getCollection()->map(function ($model) use ($relation, $extraAttributes) {
                $row = [
                    'attributes' => $model->getAttributes(),
                ];
                foreach ($extraAttributes as $attribute) {
                    $row['attributes'][$attribute] = $model->{$attribute};
                }
                if ($relation !== null && $model->relationLoaded($relation) && $model->{$relation}) {
                    $row['relation'] = [
                        'name' => $relation,
                        'attributes' => $model->{$relation}->getAttributes(),
                    ];
                }

                return $row;
            })->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $cached
     * @param  class-string  $modelClass
     * @param  list<string>  $extraAttributes
     */
    private function restorePaginator(array $cached, string $modelClass, ?string $relation, array $extraAttributes): LengthAwarePaginator
    {
        $items = Collection::make($cached['items'] ?? [])->map(function (array $row) use ($modelClass, $relation, $extraAttributes) {
            /** @var Product|Category $model */
            $model = (new $modelClass)->newFromBuilder($row['attributes'] ?? []);
            foreach ($extraAttributes as $attribute) {
                $model->setAttribute($attribute, $row['attributes'][$attribute] ?? null);
            }
            if ($relation !== null && isset($row['relation']['attributes'])) {
                $related = match ($relation) {
                    'category' => (new Category)->newFromBuilder($row['relation']['attributes']),
                    default => null,
                };
                if ($related !== null) {
                    $model->setRelation($relation, $related);
                }
            }

            return $model;
        });

        return new Paginator(
            $items,
            (int) ($cached['total'] ?? 0),
            (int) ($cached['per_page'] ?? 15),
            (int) ($cached['current_page'] ?? 1),
            [
                'path' => $cached['path'] ?? request()->url(),
                'pageName' => 'page',
            ]
        );
    }
}
