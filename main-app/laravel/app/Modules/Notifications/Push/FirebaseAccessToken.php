<?php

namespace App\Modules\Notifications\Push;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FirebaseAccessToken
{
    public function get(bool $refresh = false): string
    {
        $path = realpath((string) config('order_notifications.credentials'));
        $base = realpath(base_path());
        if (! $path || ! is_file($path) || str_starts_with(strtolower($path), strtolower($base.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('credentials_missing_or_unsafe');
        }
        $json = json_decode(file_get_contents($path), true);
        if (! is_array($json) || ($json['type'] ?? '') !== 'service_account'
            || empty($json['private_key']) || empty($json['client_email'])) {
            throw new RuntimeException('credentials_invalid');
        }
        $key = 'order-push-oauth:'.hash('sha256', $path.($json['private_key_id'] ?? '').hash('sha256', $json['private_key']));
        if ($refresh) {
            Cache::forget($key);
        }
        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        // Google owns the signing and assertion flow; all HTTP still passes
        // through Laravel's transport, so adapter tests exercise OAuth as well.
        $credentials = new ServiceAccountCredentials('https://www.googleapis.com/auth/firebase.messaging', $json);
        $token = $credentials->fetchAuthToken(function ($request) {
            $response = Http::connectTimeout(config('order_notifications.connect_timeout'))
                ->timeout(config('order_notifications.timeout'))
                ->withHeaders($request->getHeaders())
                ->withBody((string) $request->getBody(), 'application/x-www-form-urlencoded')
                ->send($request->getMethod(), (string) $request->getUri());
            if (! $response->successful()) {
                if ($response->status() === 429 || $response->serverError()) {
                    throw new PushRetryException(max($response->status() === 429 ? 60 : 0, PushRetryException::delay($response->header('Retry-After'))));
                }
                throw new RuntimeException('oauth_credentials_rejected');
            }

            return $response->toPsrResponse();
        });
        if (empty($token['access_token']) || ! is_string($token['access_token'])) {
            throw new RuntimeException('oauth_token_missing');
        }
        Cache::put($key, $token['access_token'], max(1, (int) ($token['expires_in'] ?? 3600) - 120));

        return $token['access_token'];
    }
}
