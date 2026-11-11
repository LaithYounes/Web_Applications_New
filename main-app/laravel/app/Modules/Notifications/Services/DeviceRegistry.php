<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Notifications\Models\NotificationDevice;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class DeviceRegistry
{
    public static function sessionId(): string
    {
        return hash('sha256', (string) auth('api')->payload()->get('jti'));
    }

    public function register(User $user, string $installation, array $data, string $session): NotificationDevice
    {
        $installation = strtolower($installation);
        $data['expected_revision'] = (int) $data['expected_revision'];
        try {
            return DB::transaction(function () use ($user, $installation, $data, $session) {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $this->session($user, $session);
                $binding = DB::table('notification_sessions')->where('id', $session)->first();
                abort_if($binding->revoked_at !== null, 401);
                $device = NotificationDevice::where('installation_id', $installation)->lockForUpdate()->first();
                abort_if($device && $device->active && $device->user_id !== $user->id, 404);
                $hash = hash('sha256', $data['token']);
                if ($device && $device->active && $device->user_id === $user->id
                    && $device->session_id === $session && $device->token_hash === $hash
                    && $device->revision === $data['expected_revision'] + 1) {
                    return $device;
                }
                abort_if(($device?->revision ?? 0) !== $data['expected_revision'], 409, 'Stale device revision.');
                abort_if(NotificationDevice::where('token_hash', $hash)->where('installation_id', '!=', $installation)->exists(), 409, 'Token already registered.');
                $device ??= new NotificationDevice(['installation_id' => $installation]);
                $device->fill([
                    'user_id' => $user->id, 'session_id' => $session,
                    'platform' => $data['platform'], 'token' => $data['token'], 'token_hash' => $hash,
                    'active' => true, 'revision' => $data['expected_revision'] + 1,
                ])->save();

                return $device;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Concurrent device registration.');
        }
    }

    public function detach(User $user, string $installation, int $revision, string $session): ?NotificationDevice
    {
        $installation = strtolower($installation);

        return DB::transaction(function () use ($user, $installation, $revision, $session) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $device = NotificationDevice::where('installation_id', $installation)->lockForUpdate()->first();
            if (! $device) {
                return null;
            }
            abort_if($device->user_id !== $user->id, 404);
            if (! $device->active) {
                return $device;
            }
            abort_if($device->session_id !== $session || $device->revision !== $revision, 409, 'Stale device binding.');
            $device->update(['active' => false, 'token' => null, 'token_hash' => null, 'revision' => $device->revision + 1]);

            return $device;
        }, 3);
    }

    public function logout(User $user, string $session): void
    {
        DB::transaction(function () use ($user, $session): void {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->session($user, $session);
            DB::table('notification_sessions')->where('id', $session)->update(['revoked_at' => now()]);
            NotificationDevice::where('user_id', $user->id)->where('session_id', $session)
                ->update(['active' => false, 'token' => null, 'token_hash' => null, 'updated_at' => now()]);
        }, 3);
    }

    private function session(User $user, string $session): void
    {
        DB::table('notification_sessions')->insertOrIgnore(['id' => $session, 'user_id' => $user->id]);
    }
}
