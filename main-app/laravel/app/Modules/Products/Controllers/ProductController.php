<?php

namespace App\Modules\Products\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Exceptions\InventoryServiceException;
use App\Modules\Products\Requests\StoreProductRequest;
use App\Modules\Products\Resources\ProductResource;
use App\Modules\Products\Services\ProductService;
use App\Support\Performance\ProductEtag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService,
        private ProductEtag $productEtag,
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
            ])
            ->response()
            ->header('Cache-Control', 'public, max-age=30, must-revalidate');
    }

    public function show(Request $request, string $id): JsonResponse|Response
    {
        $id = filter_var(ltrim($id, '0'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        abort_if($id === false, 404);

        try {
            $product = $this->productService->findById($id);
        } catch (InventoryServiceException $exception) {
            // Incomplete representation: never emit ETag / 304 when stock cannot be determined.
            throw $exception;
        }

        $payload = [
            'success' => true,
            'message' => 'Product retrieved successfully',
            'data' => new ProductResource($product),
        ];

        $headers = [
            'Cache-Control' => 'private, must-revalidate',
        ];

        if (config('perf.product_etag_enabled')) {
            $etag = $this->productEtag->forProduct($product, $request);
            $headers['ETag'] = $etag;

            if ($this->ifNoneMatchMatches($request->header('If-None-Match'), $etag)) {
                return response('', 304, $headers);
            }
        }

        return response()->json($payload, 200, $headers);
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

    private function ifNoneMatchMatches(?string $header, string $etag): bool
    {
        if ($header === null || trim($header) === '') {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            $value = trim($candidate);
            if ($value === '*' || $value === $etag || $value === 'W/'.$etag) {
                return true;
            }
        }

        return false;
    }
}
