<?php

namespace App\Modules\Products\Services;

use App\Modules\Products\Models\Category;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CategoryService
{
    public function getAll(): LengthAwarePaginator
    {
        return Category::query()
            ->withCount('products')
            ->orderBy('name')
            ->paginate(15);
    }

    public function create(array $data): Category
    {
        return Category::create($data);
    }
}
