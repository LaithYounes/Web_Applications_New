<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Notifications\Models\NotificationDevice;
use App\Modules\Notifications\Models\OrderNotification;
use App\Modules\Notifications\Services\DeviceRegistry;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\NotificationFixtures;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use DatabaseMigrations, NotificationFixtures;

    public function test_list_count_read_and_read_all_are_scoped_and_idempotent(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $this->order($owner);
        $this->order($other);
        $notification = OrderNotification::where('user_id', $owner->id)->firstOrFail();
        $foreign = OrderNotification::where('user_id', $other->id)->firstOrFail();
        $this->actingAs($owner, 'api')->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $notification->notification_id)->assertJsonMissingPath('data.0.token')
            ->assertJsonPath('data.0.title', 'Order received')
            ->assertJsonPath('data.0.body', "Your order #{$notification->order_id} has been received and is awaiting confirmation.");
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread_count', 1);
        $path = '/api/v1/notifications/'.$notification->notification_id.'/read';
        $first = $this->patchJson($path)->assertOk()->json('data.read_at');
        $this->travel(1)->hours();
        $this->patchJson($path)->assertOk()->assertJsonPath('data.read_at', $first);
        $this->getJson('/api/v1/notifications?unread=1')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread_count', 0);
        foreach ([$foreign->notification_id, (string) Str::uuid(), 'invalid'] as $id) {
            $this->patchJson("/api/v1/notifications/{$id}/read")->assertNotFound();
        }
        $this->order($owner);
        $this->patchJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated_count', 1);
        $this->patchJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated_count', 0);
        $this->order($owner);
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread_count', 1);
        $this->assertNull($foreign->fresh()->read_at);
        $this->assertSame($first, $notification->fresh()->read_at->toISOString());
    }

    public function test_pagination_stable_ties_validation_and_empty_list(): void
    {
        $owner = $this->customer();
        $this->actingAs($owner, 'api')->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->freezeTime();
        for ($i = 0; $i < 3; $i++) {
            $this->order($owner);
        }
        $ids = OrderNotification::orderByDesc('id')->pluck('notification_id')->all();
        $this->getJson('/api/v1/notifications?per_page=2')->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.id', $ids[0])->assertJsonPath('data.1.id', $ids[1]);
        $this->getJson('/api/v1/notifications?per_page=2&page=2')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ids[2]);
        foreach (['per_page=101', 'per_page=0', 'per_page[]=2', 'page=-1', 'unread=yes', 'user_id=1', 'read_at=now'] as $query) {
            $this->getJson('/api/v1/notifications?'.$query)->assertUnprocessable();
        }
        $this->patchJson('/api/v1/notifications/read-all', ['user_id' => $owner->id])->assertUnprocessable();
        $this->patchJson('/api/v1/notifications/'.$ids[0].'/read', ['read_at' => '2020-01-01'])->assertUnprocessable();
    }

    public function test_routes_reject_missing_malformed_expired_jwt_and_admin(): void
    {
        $id = (string) Str::uuid();
        $routes = [['get', '/notifications'], ['get', '/notifications/unread-count'], ['patch', '/notifications/read-all'],
            ['patch', "/notifications/{$id}/read"], ['put', "/notification-devices/{$id}"], ['delete', "/notification-devices/{$id}"]];
        foreach ($routes as [$verb, $path]) {
            $this->{$verb.'Json'}('/api/v1'.$path)->assertUnauthorized();
        }
        $this->withToken('malformed')->getJson('/api/v1/notifications')->assertUnauthorized();
        $user = $this->customer();
        $this->jwt($user);
        $this->travel(2)->hours();
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->travelBack();
        $this->jwt(User::factory()->create(['role' => 'admin']));
        foreach ($routes as [$verb, $path]) {
            $this->{$verb.'Json'}('/api/v1'.$path)->assertForbidden();
        }
    }

    public function test_device_registration_replay_rotation_stale_put_and_validation_do_not_expose_tokens(): void
    {
        $owner = $this->customer();
        $this->jwt($owner);
        $id = (string) Str::uuid();
        $path = '/api/v1/notification-devices/'.$id;
        $body = ['token' => 'secret:oldToken', 'platform' => 'android', 'expected_revision' => 0];
        $this->putJson($path, $body)->assertOk()->assertJsonPath('data.revision', 1)->assertJsonMissingPath('data.token');
        $this->putJson($path, $body)->assertOk()->assertJsonPath('data.revision', 1);
        $this->putJson($path, ['token' => 'secret:newToken', 'expected_revision' => 1] + $body)->assertOk()->assertJsonPath('data.revision', 2);
        $this->putJson($path, $body)->assertConflict();
        $this->assertSame('secret:newToken', NotificationDevice::firstOrFail()->token);
        $this->assertStringNotContainsString('secret:newToken', DB::table('notification_devices')->first()->token);
        foreach ([['platform' => 'ios'], ['token' => str_repeat('a', 4097)], ['token' => []], ['expected_revision' => -1],
            ['user_id' => $owner->id], ['active' => true], ['token_hash' => 'x'], ['token' => "bad\ntoken"]] as $invalid) {
            $this->putJson($path, $invalid + $body)->assertUnprocessable();
        }
        $this->deleteJson($path, ['expected_revision' => 1])->assertConflict();
        $this->deleteJson($path, ['expected_revision' => 2])->assertOk()->assertJsonPath('data.revision', 3);
        $this->deleteJson($path, ['expected_revision' => 2])->assertOk()->assertJsonPath('data.revision', 3);
        $this->putJson($path, ['token' => 'secret:newToken', 'expected_revision' => 2] + $body)->assertConflict();
        $this->deleteJson('/api/v1/notification-devices/'.Str::uuid(), ['expected_revision' => 1])->assertOk();
        $this->assertFalse(NotificationDevice::firstOrFail()->active);
    }

    public function test_token_conflicts_ownership_account_switch_and_session_logout_leave_other_devices_active(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $token = $this->jwt($owner);
        $session = DeviceRegistry::sessionId();
        $first = $this->device($owner, 'shared:token', $session);
        $second = $this->device($owner, 'second:token');
        $path = '/api/v1/notification-devices/'.$first->installation_id;
        $this->jwt($other);
        $this->putJson($path, ['token' => 'new:token', 'platform' => 'android', 'expected_revision' => 1])->assertNotFound();
        $this->deleteJson($path, ['expected_revision' => 1])->assertNotFound();
        $this->putJson('/api/v1/notification-devices/'.Str::uuid(), [
            'token' => 'shared:token', 'platform' => 'android', 'expected_revision' => 0,
        ])->assertConflict();
        app('auth')->forgetGuards();
        app('tymon.jwt')->unsetToken();
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('data', null);
        $this->assertFalse($first->fresh()->active);
        $this->assertTrue($second->fresh()->active);
        // Even a PUT authenticated just before logout cannot resurrect that session.
        try {
            app(DeviceRegistry::class)->register($owner, $first->installation_id, [
                'token' => 'stale:token', 'platform' => 'android', 'expected_revision' => 1,
            ], $session);
            $this->fail('Revoked session accepted');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
        $this->jwt($other);
        $this->putJson($path, ['token' => 'shared:token', 'platform' => 'android', 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('data.revision', 2);
        $this->assertSame($other->id, $first->fresh()->user_id);
        $this->assertTrue($second->fresh()->active);
    }
}
