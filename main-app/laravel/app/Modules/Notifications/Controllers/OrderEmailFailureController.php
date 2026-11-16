<?php

namespace App\Modules\Notifications\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Models\OrderEmailDelivery;
use App\Modules\Notifications\Resources\OrderEmailFailureResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class OrderEmailFailureController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'order_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = $this->failedEmails();

        if (isset($data['order_id'])) {
            $query->where('outbox.order_id', $data['order_id']);
        }

        $failures = $query
            ->orderByDesc('order_email_deliveries.failed_at')
            ->orderByDesc('order_email_deliveries.id')
            ->paginate((int) ($data['per_page'] ?? 20))
            ->appends($request->only(['per_page', 'order_id']));

        return OrderEmailFailureResource::collection($failures)
            ->additional([
                'success' => true,
                'message' => 'Failed order emails retrieved successfully',
            ]);
    }

    public function show(string $id)
    {
        $failure = $this->failedEmails()
            ->where('order_email_deliveries.id', $id)
            ->firstOrFail();

        return (new OrderEmailFailureResource($failure))
            ->additional([
                'success' => true,
                'message' => 'Failed order email retrieved successfully',
            ]);
    }

    private function failedEmails(): Builder
    {
        return OrderEmailDelivery::query()
            ->useWritePdo()
            ->join(
                'order_email_outbox as outbox',
                'outbox.id',
                '=',
                'order_email_deliveries.outbox_id'
            )
            ->where('order_email_deliveries.status', 'failed')
            ->select([
                'order_email_deliveries.id',
                'order_email_deliveries.message_id',
                'order_email_deliveries.status',
                'order_email_deliveries.attempts',
                'order_email_deliveries.failed_at',
                'order_email_deliveries.created_at',
                'order_email_deliveries.updated_at',
                'outbox.order_id',
                'outbox.recipient_email',
            ]);
    }
}
