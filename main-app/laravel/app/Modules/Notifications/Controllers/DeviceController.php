<?php

namespace App\Modules\Notifications\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Services\DeviceRegistry;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function store(Request $request, string $installation, DeviceRegistry $devices)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:4096', 'regex:/^[A-Za-z0-9_:.\-]+$/D'],
            'platform' => 'required|in:android',
            'expected_revision' => 'required|integer|min:0|max:2147483646',
            'user_id' => 'prohibited', 'active' => 'prohibited', 'session_id' => 'prohibited',
            'revision' => 'prohibited', 'token_hash' => 'prohibited',
        ]);
        $device = $devices->register($request->user(), $installation, $data, DeviceRegistry::sessionId());

        return response()->json(['success' => true, 'message' => 'Device registered successfully', 'data' => [
            'installation_id' => $device->installation_id, 'platform' => $device->platform,
            'revision' => $device->revision, 'active' => $device->active,
        ]]);
    }

    public function destroy(Request $request, string $installation, DeviceRegistry $devices)
    {
        $data = $request->validate([
            'expected_revision' => 'required|integer|min:1|max:2147483647', 'user_id' => 'prohibited',
        ]);
        $device = $devices->detach($request->user(), $installation, $data['expected_revision'], DeviceRegistry::sessionId());

        return response()->json(['success' => true, 'message' => 'Device detached successfully', 'data' => $device ? [
            'installation_id' => $device->installation_id, 'revision' => $device->revision, 'active' => false,
        ] : null]);
    }
}
