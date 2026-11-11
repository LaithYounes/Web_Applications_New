<?php

namespace App\Console\Commands;

use App\Modules\Notifications\Push\FirebaseAccessToken;
use App\Modules\Notifications\Push\PushRetryException;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class CheckFirebase extends Command
{
    protected $signature = 'notifications:check-firebase';

    protected $description = 'Verify service-account OAuth and FCM permission using validate_only; never deliver a message';

    public function handle(FirebaseAccessToken $oauth): int
    {
        $project = config('order_notifications.project_id');
        if (! is_string($project) || ! preg_match('/^[a-z][a-z0-9-]{4,62}$/D', $project)) {
            $this->error('FIREBASE_PROJECT_ID is missing or invalid.');

            return self::FAILURE;
        }
        try {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $response = Http::withToken($oauth->get($attempt === 1))->acceptJson()
                    ->connectTimeout(config('order_notifications.connect_timeout'))
                    ->timeout(config('order_notifications.timeout'))
                    ->post('https://fcm.googleapis.com/v1/projects/'.$project.'/messages:send', [
                        // This flag is intentionally unconditional and has no CLI override.
                        'validate_only' => true,
                        'message' => ['topic' => 'backend-configuration-check', 'data' => ['purpose' => 'configuration-check']],
                    ]);
                if ($response->status() !== 401) {
                    break;
                }
            }
        } catch (ConnectionException|PushRetryException) {
            $this->error('Firebase/OAuth network check failed or was rate-limited. Check connectivity and retry later.');

            return 2; // Transient: the setup script may retry a bounded number of times.
        } catch (Throwable) {
            $this->error('Service-account credentials could not be used. Check the external JSON mount, private key and account status.');

            return self::FAILURE;
        }
        if (! $response->successful()) {
            $reason = match ($response->status()) {
                401 => 'OAuth authentication rejected; verify the key and account.',
                403 => 'Enable Firebase Cloud Messaging API (V1) and grant the service account cloudmessaging.messages.create on this project.',
                404 => 'Verify FIREBASE_PROJECT_ID and project access.',
                429 => 'Rate-limited; retry later.',
                default => 'FCM validation rejected; check Firebase configuration or service availability.',
            };
            $this->error('FCM check failed (HTTP '.$response->status().'). '.$reason);

            return $response->status() === 429 || $response->serverError() ? 2 : self::FAILURE;
        }
        $this->info('Firebase OAuth and FCM validate_only check passed for project '.$project.'. No message was delivered.');

        return self::SUCCESS;
    }
}
