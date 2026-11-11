<?php

namespace App\Modules\Notifications\Push;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class FirebasePushProvider implements PushProvider
{
    public function __construct(private FirebaseAccessToken $oauth) {}

    public function send(string $token, array $data, int $deliveryId): PushResult
    {
        $project = config('order_notifications.project_id');
        if (! is_string($project) || ! preg_match('/^[a-z][a-z0-9-]{4,62}$/D', $project)) {
            return new PushResult('failed', 'firebase_project_missing_or_invalid');
        }
        try {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $accessToken = $this->oauth->get($attempt === 1);
                $response = Http::withToken($accessToken)->acceptJson()
                    ->connectTimeout(config('order_notifications.connect_timeout'))
                    ->timeout(config('order_notifications.timeout'))
                    ->post('https://fcm.googleapis.com/v1/projects/'.$project.'/messages:send', [
                        'message' => ['token' => $token, 'data' => $data, 'android' => ['priority' => 'normal', 'ttl' => '86400s']],
                    ]);
                if ($response->status() !== 401 || $attempt === 1) {
                    break;
                }
            }
        } catch (PushRetryException $error) {
            return new PushResult('retry', 'oauth_temporarily_unavailable', $error->retryAfter);
        } catch (ConnectionException) {
            return new PushResult('retry', 'network_or_oauth_timeout');
        } catch (Throwable) {
            // Never report transport exceptions or credential parser messages:
            // either can contain the complete token, assertion or private key.
            return new PushResult('failed', 'firebase_credentials_configuration');
        }
        if ($response->successful()) {
            $name = $response->json('name');
            if (! is_string($name) || ! str_starts_with($name, 'projects/'.$project.'/messages/')) {
                return new PushResult('retry', 'firebase_ambiguous_response');
            }

            return new PushResult('accepted', messageId: substr($name, 0, 255));
        }
        $details = $response->json('error.details', []);
        $fcmError = null;
        foreach (is_array($details) ? $details : [] as $detail) {
            if (($detail['@type'] ?? '') === 'type.googleapis.com/google.firebase.fcm.v1.FcmError') {
                $fcmError = $detail['errorCode'] ?? null;
            }
        }
        if ($fcmError === 'UNREGISTERED') {
            return new PushResult('invalid_token', 'unregistered');
        }
        if ($response->status() === 429 || $response->serverError()) {
            $delay = PushRetryException::delay($response->header('Retry-After'));

            return new PushResult('retry', 'firebase_http_'.$response->status(), max($delay, $response->status() === 429 ? 60 : 0));
        }

        return new PushResult('failed', match ($response->status()) {
            401 => 'firebase_unauthenticated', 403 => 'firebase_permission_or_sender_mismatch',
            404 => 'firebase_project_not_found', 400 => 'firebase_invalid_payload',
            default => 'firebase_http_rejected',
        });
    }
}
