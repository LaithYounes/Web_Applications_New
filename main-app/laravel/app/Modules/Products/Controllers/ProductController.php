<?php

namespace App\Modules\Products\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Products\Requests\StoreProductRequest;
use App\Modules\Products\Resources\ProductResource;
use App\Modules\Products\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $products = $this->productService->getAll(
            isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            (int) ($validated['per_page'] ?? 12)
        )->appends($validated);

        return ProductResource::collection($products)
            ->additional([
                'success' => true,
                'message' => 'Products retrieved successfully',
            ]);
    }

    public function show(string $id): JsonResponse
    {
        $id = filter_var(ltrim($id, '0'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        abort_if($id === false, 404);

        $product = $this->productService->findById($id);

        return response()->json([
            'success' => true,
            'message' => 'Product retrieved successfully',
            'data' => new ProductResource($product),
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $headers = Validator::make(
            ['idempotency_key' => $request->header('Idempotency-Key')],
            ['idempotency_key' => ['nullable', 'string', 'max:255']],
        )->validate();
        $key = $headers['idempotency_key'];
        $creationKey = $key === null ? null : hash('sha256', $request->user()->id . ':' . $key);
        $product = $this->productService->create($request->validated(), $creationKey);

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully',
            'data' => new ProductResource($product),
        ], 201);
    }
}
