<?php

namespace Tests\Feature;

use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Push\FirebasePushProvider;
use App\Modules\Notifications\Services\DeviceRegistry;
use App\Modules\Notifications\Services\PushDeliveryProcessor;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NotificationFixtures;
use Tests\TestCase;

class FirebasePushProviderTest extends TestCase
{
    use DatabaseMigrations, NotificationFixtures;

    private ?string $credentials = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->credentials = tempnam(sys_get_temp_dir(), 'fcm-test-');
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        file_put_contents($this->credentials, json_encode([
            'type' => 'service_account', 'project_id' => 'test-project', 'private_key_id' => 'test-key',
            'private_key' => $private, 'client_email' => 'test@test-project.iam.gserviceaccount.com',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));
        config(['order_notifications.driver' => 'firebase', 'order_notifications.project_id' => 'test-project',
            'order_notifications.credentials' => $this->credentials]);
        Http::preventStrayRequests();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        if ($this->credentials && is_file($this->credentials)) {
            unlink($this->credentials);
        }
        parent::tearDown();
    }

    public function test_real_adapter_oauth_payload_timeouts_and_cache(): void
    {
        Http::fake(function (Request $request, array $options) {
            $this->assertSame(10, $options['timeout']);
            $this->assertSame(3, $options['connect_timeout']);

            return str_contains($request->url(), 'oauth2')
                ? Http::response(['access_token' => 'oauth-secret', 'expires_in' => 3600])
                : Http::response(['name' => 'projects/test-project/messages/123']);
        });
        $data = ['notification_id' => 'fixed-id', 'type' => 'order.created', 'order_id' => '1', 'order_status' => 'pending'];
        $provider = app(FirebasePushProvider::class);
        $this->assertSame('accepted', $provider->send('device-secret', $data, 1)->status);
        $this->assertSame('accepted', $provider->send('device-secret', $data, 1)->status);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com')
            && $r->hasHeader('Authorization', 'Bearer oauth-secret') && $r['message']['data'] === $data
            && $r['message']['token'] === 'device-secret' && $r['message']['android']['ttl'] === '86400s');
        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'oauth2')) {
                return false;
            }
            parse_str($r->body(), $body);
            $parts = explode('.', $body['assertion']);
            $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            $this->assertSame('https://www.googleapis.com/auth/firebase.messaging', $claims['scope']);

            return $body['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer';
        });
    }

    public static function errors(): array
    {
        $fcm = fn ($code) => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => $code]];

        return [
            'quota' => [429, [], 'retry', 'firebase_http_429'],
            'unavailable' => [503, [], 'retry', 'firebase_http_503'],
            'internal' => [500, [], 'retry', 'firebase_http_500'],
            'permission' => [403, [], 'failed', 'firebase_permission_or_sender_mismatch'],
            'wrong project' => [404, [], 'failed', 'firebase_project_not_found'],
            'unregistered' => [404, $fcm('UNREGISTERED'), 'invalid_token', 'unregistered'],
            'ambiguous invalid argument' => [400, $fcm('INVALID_ARGUMENT'), 'failed', 'firebase_invalid_payload'],
            'invalid payload' => [400, [['@type' => 'type.googleapis.com/google.rpc.BadRequest']], 'failed', 'firebase_invalid_payload'],
            'bare invalid argument' => [400, [], 'failed', 'firebase_invalid_payload'],
        ];
    }

    #[DataProvider('errors')]
    public function test_error_classification_and_retry_after(int $http, array $details, string $status, string $code): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'secret', 'expires_in' => 3600]),
            'https://fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'INVALID_ARGUMENT', 'details' => $details]], $http, ['Retry-After' => '180']),
        ]);
        $result = app(FirebasePushProvider::class)->send('device-secret', ['order_id' => '1'], 1);
        $this->assertSame($status, $result->status);
        $this->assertSame($code, $result->code);
        if ($status === 'retry') {
            $this->assertSame(180, $result->retryAfter);
        }
    }

    public function test_401_refreshes_once_then_stops_without_a_retry_storm(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::sequence()->push(['access_token' => 'first', 'expires_in' => 3600])->push(['access_token' => 'second', 'expires_in' => 3600]),
            'https://fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'UNAUTHENTICATED']], 401),
        ]);
        $result = app(FirebasePushProvider::class)->send('device-secret', ['order_id' => '1'], 1);
        $this->assertSame('firebase_unauthenticated', $result->code);
        $this->assertSame('failed', $result->status);
        Http::assertSentCount(4);
    }

    public function test_timeout_and_retry_after_http_date_are_retryable(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'secret', 'expires_in' => 3600]),
            'https://fcm.googleapis.com/*' => Http::failedConnection(),
        ]);
        $this->assertSame('retry', app(FirebasePushProvider::class)->send('secret', [], 1)->status);
        Http::swap(new Factory);
        Http::fake(['https://fcm.googleapis.com/*' => Http::response([], 503, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 300)])]);
        $this->assertGreaterThanOrEqual(298, app(FirebasePushProvider::class)->send('secret', [], 1)->retryAfter);
    }

    public function test_missing_credentials_does_not_break_order_and_logs_only_safe_codes(): void
    {
        config(['order_notifications.credentials' => '/missing/service-account.json']);
        Log::spy();
        $user = $this->customer();
        $this->device($user, 'never-log-device-secret');
        $order = $this->order($user);
        app(PushDeliveryProcessor::class)->process(NotificationDelivery::firstOrFail()->id);
        $this->assertDatabaseHas('notification_deliveries', ['status' => 'failed', 'error_code' => 'firebase_credentials_configuration']);
        $this->assertSame('pending', $order->fresh()->status);
        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once()->with('Order push failed', \Mockery::on(fn ($context) => ! str_contains(json_encode($context), 'never-log-device-secret')));
    }

    public function test_token_rotated_during_fcm_request_is_not_disabled_by_old_failure(): void
    {
        $user = $this->customer();
        $device = $this->device($user, 'old:token');
        $this->order($user);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'secret', 'expires_in' => 3600]),
            'https://fcm.googleapis.com/*' => function () use ($user, $device) {
                app(DeviceRegistry::class)->register($user, $device->installation_id, [
                    'token' => 'new:token', 'platform' => 'android', 'expected_revision' => 1,
                ], $device->session_id);

                return Http::response(['error' => ['details' => [[
                    '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED',
                ]]]], 404);
            },
        ]);
        app(PushDeliveryProcessor::class)->process(NotificationDelivery::firstOrFail()->id);
        $this->assertTrue($device->fresh()->active);
        $this->assertSame('new:token', $device->fresh()->token);
        $this->assertSame(2, $device->fresh()->revision);
    }

    public function test_oauth_quota_respects_retry_after_and_bad_credentials_are_terminal(): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response([], 429, ['Retry-After' => '240'])]);
        $result = app(FirebasePushProvider::class)->send('secret', [], 1);
        $this->assertSame('retry', $result->status);
        $this->assertSame(240, $result->retryAfter);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $result = app(FirebasePushProvider::class)->send('secret', [], 1);
        $this->assertSame('failed', $result->status);
        $this->assertSame('firebase_credentials_configuration', $result->code);
        Http::assertSentCount(1);
    }

    public function test_configuration_command_validates_permission_without_delivering_or_creating_records(): void
    {
        config(['order_notifications.driver' => 'disabled']);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'check-secret', 'expires_in' => 3600]),
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/test-project/messages/check']),
        ]);
        $this->artisan('notifications:check-firebase')->expectsOutputToContain('No message was delivered')->assertSuccessful();
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'fcm.googleapis.com')
            && $request['validate_only'] === true && $request['message']['topic'] === 'backend-configuration-check'
            && ! isset($request['message']['token']));
        $this->assertDatabaseCount('order_notifications', 0);
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_configuration_command_reports_permission_failure_without_raw_response_secrets(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'check-secret', 'expires_in' => 3600]),
            'https://fcm.googleapis.com/*' => Http::response(['error' => ['message' => 'must-not-print-provider-body']], 403),
        ]);
        $this->artisan('notifications:check-firebase')->expectsOutputToContain('cloudmessaging.messages.create')->assertFailed();
        $this->assertStringNotContainsString('must-not-print-provider-body', Artisan::output());
    }

    public function test_configuration_command_marks_network_errors_transient_and_missing_credentials_terminal(): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::failedConnection()]);
        $this->artisan('notifications:check-firebase')->assertExitCode(2);
        config(['order_notifications.credentials' => '/missing/account.json']);
        $this->artisan('notifications:check-firebase')->assertExitCode(1);
    }

    public function test_partial_fcm_device_success_retries_only_failed_device_and_never_mutates_order(): void
    {
        $user = $this->customer();
        $good = $this->device($user, 'good:token');
        $flaky = $this->device($user, 'flaky:token');
        $gone = $this->device($user, 'gone:token');
        $order = $this->order($user);
        $recover = false;
        Http::fake(function (Request $request) use (&$recover) {
            if (str_contains($request->url(), 'oauth2')) {
                return Http::response(['access_token' => 'safe-test-oauth', 'expires_in' => 3600]);
            }
            if ($request['message']['token'] === 'gone:token') {
                return Http::response(['error' => ['details' => [[
                    '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED',
                ]]]], 404);
            }
            if ($request['message']['token'] === 'flaky:token' && ! $recover) {
                return Http::response([], 503);
            }

            return Http::response(['name' => 'projects/test-project/messages/test']);
        });
        $processor = app(PushDeliveryProcessor::class);
        foreach (NotificationDelivery::orderBy('id')->get() as $delivery) {
            $processor->process($delivery->id);
        }
        $this->assertDatabaseHas('notification_deliveries', ['device_id' => $good->id, 'status' => 'accepted', 'attempts' => 1]);
        $this->assertDatabaseHas('notification_deliveries', ['device_id' => $flaky->id, 'status' => 'pending', 'attempts' => 1]);
        $this->assertFalse($gone->fresh()->active);
        $this->assertTrue($good->fresh()->active);
        $this->assertTrue($flaky->fresh()->active);
        $recover = true;
        $this->travel(2)->hours();
        foreach (NotificationDelivery::orderBy('id')->get() as $delivery) {
            $processor->process($delivery->id);
        }
        $this->assertDatabaseHas('notification_deliveries', ['device_id' => $flaky->id, 'status' => 'accepted', 'attempts' => 2]);
        $this->assertDatabaseHas('notification_deliveries', ['device_id' => $good->id, 'attempts' => 1]);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(1, $order->statusHistory()->count());
        $this->assertDatabaseCount('order_notifications', 1);
    }
}
