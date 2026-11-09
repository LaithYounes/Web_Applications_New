<?php

namespace App\Modules\Products\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Products\Requests\StoreCategoryRequest;
use App\Modules\Products\Resources\CategoryResource;
use App\Modules\Products\Services\CategoryService;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function __construct(
        private CategoryService $categoryService
    ) {}

    public function index()
    {
        $categories = $this->categoryService->getAll();

        return CategoryResource::collection($categories)
            ->additional([
                'success' => true,
                'message' => 'Categories retrieved successfully',
            ]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully',
            'data' => new CategoryResource($category),
        ], 201);
    }
}
