<?php

namespace App\Modules\Products\Services;

use App\Modules\Products\Models\Category;
use App\Support\Performance\CatalogCache;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CategoryService
{
    public function __construct(
        private CatalogCache $catalogCache,
    ) {}

    public function getAll(): LengthAwarePaginator
    {
        $page = max(1, (int) request()->input('page', 1));

        $resolve = fn (): LengthAwarePaginator => Category::query()
            ->useWritePdo()
            ->withCount('products')
            ->orderBy('name')
            ->paginate(15, ['*'], 'page', $page);

        if (! config('perf.catalog_cache_enabled')) {
            return $resolve();
        }

        return $this->catalogCache->categoriesList($page, $resolve);
    }

    public function create(array $data): Category
    {
        return $this->catalogCache->mutate(fn () => Category::create($data));
    }
}
