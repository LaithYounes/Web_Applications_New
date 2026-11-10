<?php

namespace App\Modules\Orders\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Requests\UpdateOrderStatusRequest;
use App\Modules\Orders\Resources\OrderResource;
use App\Modules\Orders\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private OrderService $orderService
    ) {}

    public function index(Request $request)
    {
        if ($request->user()->role === 'admin') {
            $orders = $this->orderService->getAllOrders();
        } else {
            $orders = $this->orderService->getUserOrders($request->user());
        }

        return OrderResource::collection($orders)
            ->additional([
                'success' => true,
                'message' => 'Orders retrieved successfully',
            ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($request->user()->role === 'admin') {
            $order = \App\Modules\Orders\Models\Order::with('items')
                ->findOrFail($id);
        } else {
            $order = $this->orderService->getUserOrder(
                $request->user(),
                $id
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Order retrieved successfully',
            'data' => new OrderResource($order),
        ]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $orderId = filter_var(ltrim($id, '0'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        abort_if($orderId === false, 404);

        $order = $this->orderService->cancelUserOrder($request->user(), $orderId);

        return response()->json([
            'success' => true,
            'message' => 'Order cancelled successfully',
            'data' => new OrderResource($order),
        ]);
    }

    public function updateStatus(
        UpdateOrderStatusRequest $request,
        int $id
    ): JsonResponse {
        $order = $this->orderService->updateStatus(
            $id,
            $request->validated()['status']
        );

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully',
            'data' => new OrderResource($order),
        ]);
    }
    
public function store(Request $request): JsonResponse
{
    $order = $this->orderService->createOrder($request->user());

    return response()->json([
        'success' => true,
        'message' => 'Order created successfully',
        'data' => new OrderResource($order),
    ], 201);
}

}
