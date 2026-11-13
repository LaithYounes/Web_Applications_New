<?php

namespace App\Support\Performance;

use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Catalog read-through cache for MySQL product/category listings only.
 * Never stores inventory quantities, reservations, carts, or orders.
 */
class CatalogCache
{
    // Kept for callers that monitor the legacy key; it is no longer authoritative.
    public const VERSION_KEY = 'catalog:version';

    public const PRODUCTS_TTL_SECONDS = 60;

    public const CATEGORIES_TTL_SECONDS = 120;

    public int $hits = 0;

    public int $misses = 0;

    public ?string $lastKey = null;

    public function productsList(?int $categoryId, int $perPage, int $page, callable $resolver): LengthAwarePaginator
    {
        $key = $this->readKey(fn () => $this->productsKey($categoryId, $perPage, $page));

        return $this->rememberPaginator($key, self::PRODUCTS_TTL_SECONDS, $resolver, Product::class, 'category');
    }

    public function categoriesList(int $page, callable $resolver): LengthAwarePaginator
    {
        $key = $this->readKey(fn () => $this->categoriesKey($page));

        return $this->rememberPaginator($key, self::CATEGORIES_TTL_SECONDS, $resolver, Category::class, null, ['products_count']);
    }

    /**
     * Atomically commit catalog data and its durable invalidation. Other readers
     * see the new namespace only AFTER the outermost commit, including when Redis
     * is down or the writer dies immediately after commit. No global reader lock.
     */
    public function mutate(callable $mutation): mixed
    {
        return DB::transaction(function () use ($mutation) {
            $result = $mutation();
            $this->invalidate();

            return $result;
        });
    }

    public function invalidate(): void
    {
        // SQL increment is atomic for every supported cache backend. Failing this
        // write must roll back the catalog mutation, never silently lose freshness.
        if (DB::table('catalog_cache_state')->where('id', 1)->increment('generation') !== 1) {
            throw new RuntimeException('Catalog cache generation is unavailable.');
        }
    }

    public function version(): int
    {
        $version = DB::table('catalog_cache_state')->useWritePdo()->where('id', 1)->value('generation');
        if ($version === null || (int) $version < 1) {
            throw new RuntimeException('Catalog cache generation is unavailable.');
        }

        return (int) $version;
    }

    private function readKey(callable $key): ?string
    {
        // A transaction can observe its own uncommitted catalog data. Neither read
        // nor populate shared cache there, even with an outer transaction.
        if (DB::transactionLevel() > 0) {
            return null;
        }
        try {
            return $key();
        } catch (Throwable) {
            Log::warning('Catalog generation read failed; bypassing cache.');

            return null;
        }
    }

    public function productsKey(?int $categoryId, int $perPage, int $page): string
    {
        $category = $categoryId === null ? 'all' : (string) $categoryId;

        return sprintf(
            'catalog:s2:v%d:products:cat:%s:per:%d:page:%d:sort:latest-id',
            $this->version(),
            $category,
            $perPage,
            $page
        );
    }

    public function categoriesKey(int $page): string
    {
        return sprintf(
            'catalog:s2:v%d:categories:per:15:page:%d:sort:name:withCount:products',
            $this->version(),
            $page
        );
    }

    /**
     * @param  class-string  $modelClass
     * @param  list<string>  $extraAttributes
     */
    private function rememberPaginator(
        ?string $key,
        int $ttl,
        callable $resolver,
        string $modelClass,
        ?string $relation = null,
        array $extraAttributes = [],
    ): LengthAwarePaginator {
        $this->lastKey = $key;

        if ($key === null) {
            $this->misses++;

            return DB::transaction(fn () => $resolver());
        }

        $cached = null;
        try {
            $cached = Cache::get($key);
        } catch (Throwable $exception) {
            Log::warning('Catalog cache read failed; using database.', [
                'key' => $key,
            ]);
        }

        if (is_array($cached) && isset($cached['items'], $cached['total'], $cached['per_page'], $cached['current_page'])
            && is_array($cached['items']) && is_int($cached['total']) && $cached['total'] >= 0
            && is_int($cached['per_page']) && $cached['per_page'] > 0
            && is_int($cached['current_page']) && $cached['current_page'] > 0) {
            try {
                $restored = $this->restorePaginator($cached, $modelClass, $relation, $extraAttributes);
                $this->hits++;

                return $restored;
            } catch (Throwable) {
                Log::warning('Invalid catalog payload; using database.');
            }
        }

        $this->misses++;

        /** @var LengthAwarePaginator $paginator */
        // Count, page rows, and eager relations share the primary transaction's
        // snapshot. A racing mutation can only retire this key, never revive it.
        $paginator = DB::transaction(fn () => $resolver());

        try {
            Cache::put($key, $this->serializePaginator($paginator, $relation, $extraAttributes), $ttl);
        } catch (Throwable $exception) {
            Log::warning('Catalog cache write failed; response still served from database.', [
                'key' => $key,
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
                'path' => request()->url(),
                'pageName' => 'page',
            ]
        );
    }
}
