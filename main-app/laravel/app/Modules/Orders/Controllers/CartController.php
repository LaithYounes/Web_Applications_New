<?php

namespace App\Modules\Orders\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Requests\AddCartItemRequest;
use App\Modules\Orders\Requests\UpdateCartItemRequest;
use App\Modules\Orders\Resources\CartResource;
use App\Modules\Orders\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        private CartService $cartService
    ) {}

    public function show(Request $request): JsonResponse
    {
        $cart = $this->cartService->getCart($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Cart retrieved successfully',
            'data' => new CartResource($cart),
        ]);
    }

    public function add(AddCartItemRequest $request): JsonResponse
    {
        $cart = $this->cartService->addItem(
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Item added to cart successfully',
            'data' => new CartResource($cart),
        ], 201);
    }

    public function update(
        UpdateCartItemRequest $request,
        int $itemId
    ): JsonResponse {
        $cart = $this->cartService->updateItem(
            $request->user(),
            $itemId,
            $request->validated()['quantity']
        );

        return response()->json([
            'success' => true,
            'message' => 'Cart item updated successfully',
            'data' => new CartResource($cart),
        ]);
    }

    public function remove(Request $request, int $itemId): JsonResponse
    {
        $cart = $this->cartService->removeItem(
            $request->user(),
            $itemId
        );

        return response()->json([
            'success' => true,
            'message' => 'Cart item removed successfully',
            'data' => new CartResource($cart),
        ]);
    }
}
