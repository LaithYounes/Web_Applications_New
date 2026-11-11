<?php

namespace App\Modules\Notifications\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Notifications\Models\OrderNotification;
use App\Modules\Notifications\Resources\NotificationResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'page' => 'sometimes|integer|min:1|max:1000000', 'per_page' => 'sometimes|integer|min:1|max:100',
            'unread' => 'sometimes|boolean', 'user_id' => 'prohibited', 'read_at' => 'prohibited',
        ]);
        $query = OrderNotification::where('user_id', $request->user()->id);
        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        return NotificationResource::collection($query->orderByDesc('created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 20))
            ->additional(['success' => true, 'message' => 'Notifications retrieved successfully']);
    }

    public function unreadCount(Request $request)
    {
        return $this->response(['unread_count' => OrderNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count()]);
    }

    public function read(Request $request, string $id)
    {
        $request->validate(['user_id' => 'prohibited', 'read_at' => 'prohibited']);
        $query = OrderNotification::where('user_id', $request->user()->id)->where('notification_id', $id);
        $notification = $query->firstOrFail();
        (clone $query)->whereNull('read_at')->update(['read_at' => now()]);

        return $this->response(new NotificationResource($notification->refresh()));
    }

    public function readAll(Request $request)
    {
        $request->validate(['user_id' => 'prohibited', 'read_at' => 'prohibited']);
        $count = DB::transaction(function () use ($request) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $query = OrderNotification::where('user_id', $request->user()->id);
            $watermark = (clone $query)->max('id');

            return $query->where('id', '<=', $watermark ?? 0)->whereNull('read_at')->update(['read_at' => now()]);
        }, 3);

        return $this->response(['updated_count' => $count]);
    }

    private function response(mixed $data)
    {
        return response()->json(['success' => true, 'message' => 'Notifications updated successfully', 'data' => $data]);
    }
}
