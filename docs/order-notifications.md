# Customer order notifications

The backend stores the customer's inbox in the application database. Firebase is an additional push channel; local notifications work without credentials. There are no frontend/Android changes and JWT authentication is preserved.

## Events, transactions and uniqueness

The six supported types are `order.created`, `order.confirmed`, `order.processing`, `order.shipped`, `order.delivered`, and `order.cancelled`. English titles and exact body templates are centralized in `OrderNotificationType`. The recipient is the order owner (customer role), regardless of the actor. Stored title/body/status are immutable event snapshots, not generated from the order's later status.

| Type | Title | Body (`42` is the event's order ID) |
| --- | --- | --- |
| `order.created` | Order received | Your order #42 has been received and is awaiting confirmation. |
| `order.confirmed` | Order confirmed | Your order #42 has been confirmed. |
| `order.processing` | Order processing | Your order #42 is being prepared. |
| `order.shipped` | Order shipped | Your order #42 has been shipped. |
| `order.delivered` | Order delivered | Your order #42 has been delivered. |
| `order.cancelled` | Order cancelled | Your order #42 has been cancelled. |

Custom tables provide typed history references and independent delivery state. The existing `Order::save()` transaction now includes history, notification, device deliveries and database queue jobs. The recorder inserts jobs using **the order's exact database connection**, inside the same transaction, including nested service transactions. It explicitly uses a database queue even with `QUEUE_CONNECTION=sync`. No after-commit enqueue callback is used. Failure to insert work rolls back the business event. A producer dying just after commit cannot lose an already committed job. A separate worker cannot see it before the outermost commit. Firebase is called only by the worker, outside transactions.

`UNIQUE(event_id,user_id)` references `order_status_histories.id`; `UNIQUE(notification_id,device_id)` protects device deliveries. UUIDs are public notification IDs; an internal increasing ID breaks timestamp ties and supplies the read-all watermark. Recipient row locks serialize event recording with read-all/device changes. Replaying checkout, cancellation, recorder or job does not create a second inbox record for the event.

PendingCheckout, internal `cancelling`, unrelated field changes, rejected/failed transitions and rolled-back work generate no notifications. Successful recovery creates the initial notification once. Cancellation notifies only after inventory release and persisted `cancelled`. Migrations never backfill. DemoOrderSeeder suppresses notifications while retaining history. Raw SQL, bulk updates and event-suppressed writes bypass the existing history hook and therefore notifications; business status writes must continue using the model/service.

## HTTP contract

All paths below use `/api/v1`, `Accept: application/json`, and a customer bearer JWT. Admins receive `403`; absent/invalid/expired JWTs receive `401`. Another user's resource returns `404`.

| Method/path | Input | Result |
| --- | --- | --- |
| `GET /notifications` | `page` 1..1000000, `per_page` 1..100 (default 20), `unread=0` or `1` | `data` array, standard Laravel `links` and `meta`; `created_at DESC,id DESC` |
| `GET /notifications/unread-count` | None | `data: {"unread_count":2}` |
| `PATCH /notifications/{uuid}/read` | Empty JSON | Notification; repeating preserves first `read_at` |
| `PATCH /notifications/read-all` | Empty JSON | `data: {"updated_count":2}` |
| `PUT /notification-devices/{installation_uuid}` | `token`, `platform: "android"`, `expected_revision` | Public registration metadata; no token |
| `DELETE /notification-devices/{installation_uuid}` | `expected_revision` | Detached metadata with incremented `revision`; missing binding returns `data:null`; repeating is idempotent |

Read-all locks the recipient, captures the maximum existing ID, and updates unread rows up to that watermark atomically. An insertion that obtains the recipient lock afterward remains unread. An insertion committed before read-all takes the lock is included. Earlier read timestamps and other users are preserved. The MySQL lab tests concurrent arrival with an independent process.

Example notification in `data` (list responses wrap these in an array):

```json
{
  "id":"b3214616-a9b0-402d-8c36-f81c2f583302",
  "type":"order.confirmed",
  "title":"Order confirmed",
  "body":"Your order #42 has been confirmed.",
  "order_id":42,
  "order_status":"confirmed",
  "created_at":"2026-11-11T12:00:00.000000Z",
  "read_at":null
}
```

Responses use the existing `success/message/data` envelope. Errors use `{"success":false,"message":"...","data":null,"errors":null}`; validation returns `422` with field arrays. Malformed UUID routes return `404`. Stale/conflicting device bindings return `409` with the project's generic `Request failed` message. `user_id` and listed server-managed fields are prohibited; user ownership always comes from JWT. There is no public sending, token-listing or delivery-administration endpoint. Dates are UTC with `Z`.

## Android registration and logout

1. Generate a random UUID per installation; retain it and the last acknowledged server `revision` locally. The UUID is a binding identifier, **not an authentication secret**. Use a separate login/JWT per installation.
2. After login obtain the current FCM token and PUT `{"token":"<FCM token>","platform":"android","expected_revision":0}`. A successful response has `data: {"installation_id":"...","platform":"android","revision":1,"active":true}`. Only Android is supported. Tokens are nonempty strings up to 4096 characters using letters, digits, `_ : . -`.
3. On FCM token refresh, serialize PUT requests, send the new token with the acknowledged revision, and save the returned incremented revision. An exact retry of the last successful PUT is idempotent. A stale different token/revision gets `409`. Never blindly retry an old token using a newer revision: obtain the current FCM token and reconcile the acknowledged request. Keep the body unchanged on network retries. A reinstall uses a new UUID. A new login for the same account must re-register using the stored revision to bind the device to that JWT session.
4. DELETE with the acknowledged revision opts out and increments the revision, preventing an in-flight older PUT from reversing opt-out. Retain its returned `data: {"installation_id":"...","revision":2,"active":false}` for a later explicit registration. Repeating DELETE returns that same inactive revision; missing binding returns `data:null`. A stale deletion of an active newer revision/session gets `409`; another owner's resource gets `404`.
5. Call existing `POST /auth/logout` before discarding the JWT. Its body and `data:null` response remain compatible with old clients. The backend detaches all devices bound to **that JWT session**, clears token/hash, and writes a session tombstone. Other login sessions remain active. Even a PUT authenticated before logout but waiting for its lock cannot resurrect the revoked session. Sharing a JWT across devices makes them one session, so logout detaches them together.
6. Switching accounts requires completing the old account's logout/DELETE before registering the new JWT, retaining the acknowledged revision. The new registration increments it. An active installation belonging to another account cannot be taken over (PUT/DELETE return `404`). A unique SHA-256 token fingerprint prevents a token being active on two installations/accounts (`409`); there is no implicit token transfer. If the old JWT is unavailable, a client may need to delete/refresh its FCM token and use a new UUID; the backend cannot authenticate an old account from a UUID alone.

Each queued delivery captures a device revision. Immediately before sending, the worker rechecks activity, recipient and revision. Logout, account transfer, rotation or opt-out skips old work as `skipped_device_changed`; it is never transferred to a later token. Devices added after the event get the inbox entry, not historical pushes. JWT expiry alone does not detach a device.

Once a network send starts, the backend cannot revoke it or remove a notification already displayed. Android must clear account-scoped notifications/local state on logout, process pushes against the current session and fetch an order with the current JWT before showing sensitive details. Push includes order number/status; a backend-only change cannot guarantee removal of an in-flight push during account switching.

## Payload and network limits

FCM HTTP v1 uses a **data-only** Android message, so the app must implement foreground/background handling, permissions and local display. All data values are strings:

```json
{
  "message": {
    "token":"<current token>",
    "data": {
      "notification_id":"b3214616-a9b0-402d-8c36-f81c2f583302",
      "type":"order.confirmed",
      "order_id":"42",
      "order_status":"confirmed",
      "title":"Order confirmed",
      "body":"Your order #42 has been confirmed."
    },
    "android":{"priority":"normal","ttl":"86400s"}
  }
}
```

Persist `notification_id` as the deduplication key and local notification tag **before display**. On tap open `order_id`, fetch `GET /orders/{id}` with the current JWT, refresh the inbox and explicitly PATCH read when the user reads it. Arrival alone never marks read. Normal-priority Android background messages may be delayed; validate handling in the Android project.

Delivery states: `pending`, `sending` (leased), `accepted`, `failed`, `skipped_disabled`, `skipped_driver_changed`, `skipped_device_changed`. `accepted_at` means FCM accepted a request (or fake accepted when `driver=fake`), not delivered/seen. `read_at` is independent. There is **no exactly-once network guarantee**: if FCM accepts but its response/worker is lost before the DB receipt, recovery may resend with the same notification UUID. The lab kills a sending process after fake acceptance to verify this duplicate window. A failed device never causes completed devices to be resent.

Timeouts/network/429/5xx receive bounded attempts, exponential backoff and jitter; FCM Retry-After seconds/dates are honored, with at least 60 seconds for 429. A 401 refreshes OAuth once, then becomes terminal. Credential/permission/project failures do not loop. Only explicit `UNREGISTERED` disables that captured token/revision. All `INVALID_ARGUMENT` variants are conservatively terminal payload errors and never deactivate a device. A late old-token error cannot disable a newer registration. No devices is normal `push_mode=no_devices`, not a business failure.

## Configuration and workers

| Variable | Default/purpose |
| --- | --- |
| `ORDER_PUSH_DRIVER` | `disabled`; supported: `disabled`, `fake`, `firebase` |
| `FIREBASE_PROJECT_ID` | Target Firebase project; empty placeholder in Git |
| `FIREBASE_CREDENTIALS` | Absolute service-account JSON path outside repository/public |
| `ORDER_PUSH_TIMEOUT` | 10 seconds, bounded 1..15 per request |
| `ORDER_PUSH_CONNECT_TIMEOUT` | 3 seconds, bounded 1..5 |
| `ORDER_PUSH_MAX_ATTEMPTS` | 5, bounded 1..10 |
| `ORDER_PUSH_BACKOFF` | 60 seconds initially, minimum 10, exponential up to one hour plus jitter |
| `ORDER_PUSH_FAKE_RESULTS` | Local/testing: comma-separated `accepted,retry,invalid_token,failed`, separately per delivery, repeating last outcome |

Disabled mode still saves the inbox and device deliveries as `skipped_disabled`, without queueing sends. Enabling Firebase later never requeues these or old inbox-only events. Disabling pending work skips it when consumed. Changing drivers also skips queued work, so fake historical events cannot become real sends. Unknown drivers produce inspectable failures. Fake is forbidden outside local/testing and writes token-free receipts to `notification_push_attempts`; it never contacts Firebase.

Run migrations before updated app/recovery/worker. `update.cmd` starts/verifies the additional worker after dependencies and migrations and preserves data/.env. Initial setup's normal `up` before migrations can initially produce worker table errors; it retries after migrations exist.

```powershell
docker compose exec -T app php artisan migrate --force
docker compose up -d notification-worker
docker compose logs --tail 50 notification-worker
```

The dedicated `notifications` connection/queue uses `jobs` on the same default application DB. Worker timeout is 90s, send lease 120s, queue retry_after 180s; keep this order. Bounded HTTP timeouts fit OAuth/send/one refresh inside the worker timeout. Compose restarts workers hourly. App, scheduler and worker share image/source/vendor/env/DB settings. After changing configuration:

```powershell
docker compose exec -T app php artisan config:clear
docker compose up -d --force-recreate app checkout-recovery notification-worker
```

Inspect `notification_deliveries.status/attempts/error_code/next_attempt_at/accepted_at/driver` through trusted DB tools. Terminal failures remain in this table rather than being misreported as sent or necessarily placed in `failed_jobs`. Order/history/inventory stay unchanged. Database reservations recover interrupted jobs. Check `jobs` and worker logs for stalled pending work. Logs contain delivery ID, safe error code, driver and attempt count, never raw provider bodies/exceptions/tokens/keys. Device tokens are encrypted with the persistent existing APP_KEY. OAuth access tokens are cached with an expiry margin; restrict cache access like other authentication state.

After correcting a cause, explicitly retry one failed delivery:

```powershell
docker compose exec -T app php artisan notifications:retry-delivery 123
```

It resets that delivery's attempt budget and atomically queues work, rechecking binding at send time. It never revives accepted/skipped rows. Avoid broad historical replay.

## Enabling real Firebase later

For an existing Windows/Docker checkout, use `scripts/setup-firebase.ps1 -CredentialsPath <external JSON path>` after the normal update. It validates OAuth and FCM permission with an unconditional `validate_only: true` request before enabling workers, copies the credential outside the repository, and generates an ignored `docker-compose.override.yml` automatically loaded by normal Compose/update commands. Existing custom overrides are preserved and require the manual integration below. This generated environment overrides the default disabled driver without modifying `.env`. See the [Arabic frontend handoff](frontend-notifications-handoff.md).

The setup retries transient validation failures (exit code 2) at most three times; permission/credential failures (exit code 1) stop immediately. Preflight failure leaves the existing runtime configuration unchanged. If the final post-restart network check fails, the local configuration has already been installed: correct the reported problem and rerun the same idempotent setup command. A generated override has higher precedence than `.env`; change its `ORDER_PUSH_DRIVER` to `disabled` and recreate app/recovery/worker to disable push after using this setup.

`docker compose exec -T app php artisan notifications:check-firebase` repeats the non-delivering check. It needs no device token and prints no credentials or raw provider error bodies. Its fixed topic is only a validation target: no API route or actual topic sending feature was added. See [FCM validate_only](https://firebase.google.com/docs/reference/fcm/rest/v1/projects.messages/send).

The adapter uses FCM HTTP v1 and `google/auth` for service-account OAuth signing/refresh. Android `google-services.json` is **not** a backend credential. Enable the FCM v1 API and grant the sender the FCM permission on the target project.

Keep the JSON outside this checkout, e.g. `C:/Users/<you>/.secrets/firebase-service-account.json`. Create a local untracked Compose override mounting it read-only in all processes using the shared configuration:

```yaml
services:
  app:
    volumes:
      - C:/Users/<you>/.secrets/firebase-service-account.json:/run/secrets/firebase-service-account.json:ro
  checkout-recovery:
    volumes:
      - C:/Users/<you>/.secrets/firebase-service-account.json:/run/secrets/firebase-service-account.json:ro
  notification-worker:
    volumes:
      - C:/Users/<you>/.secrets/firebase-service-account.json:/run/secrets/firebase-service-account.json:ro
```

Set `ORDER_PUSH_DRIVER=firebase`, `FIREBASE_PROJECT_ID=<project-id>`, and `FIREBASE_CREDENTIALS=/run/secrets/firebase-service-account.json` in local .env. Clear config and recreate these services with the override. Do not paste JSON/private keys into .env or Git/public. Missing credentials do not prevent boot/order completion/local storage; the worker records `firebase_credentials_configuration` instead of claiming success.

With credentials and a real Android device, use a **new test account/order** to verify real acceptance, token invalidation, foreground/background display/permissions, tap navigation, logout/account switching and client deduplication. Live device delivery/display checks have **not** been performed. The supplied real service account has passed OAuth and FCM validate_only checks without delivering any message.

Official references reviewed: [HTTP v1 authorization](https://firebase.google.com/docs/cloud-messaging/send/v1-api), [error codes](https://firebase.google.com/docs/cloud-messaging/error-codes), [message schema](https://firebase.google.com/docs/reference/fcm/rest/v1/projects.messages), [service-account OAuth](https://developers.google.com/identity/protocols/oauth2/service-account).

## Repeatable verification

```powershell
docker compose exec -T app php artisan test --compact
docker compose exec -T app php artisan route:list --path=notification
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/tests/update.Tests.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/test-notifications.ps1
```

Feature/adapter tests use SQLite and fake HTTP, including real Google OAuth assertions against fake token responses. The separate integration Compose project has MySQL/PostgreSQL tmpfs databases, isolated inventory, HTTP at `127.0.0.1:18080`, and real independent worker/contender processes inside Docker. It reuses installed vendor read-only, never resets the user DB or sends Firebase, and refuses an incorrect environment/database/driver. It tests incremental migration over old data; unit tests also apply all migrations fresh. The runner tears down only its lab containers/network/ephemeral DBs.

The lab covers HTTP/JWT/gRPC lifecycle, persisted queue consumption, outer rollback visibility, producer kill immediately after commit, concurrent event recording, two workers with duplicate jobs, read-all/concurrent insertion, competing device CAS updates, late invalid-token failure after rotation, logout/registration race, delayed job/account transfer, persisted backoff/exhaustion/explicit retry and worker death after provider acceptance. Backoff is checked in MySQL before due times are advanced explicitly to keep tests fast. These are not Firebase/device delivery tests.

## Verified results (2026-11-11)

| Executed check | Actual result |
| --- | --- |
| `docker compose exec -T app php artisan test --compact` | 100 passed, 830 assertions; includes 38 new notification/configuration tests/data-provider cases and English inbox/push text assertions; final run 46.37s |
| `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/test-notifications.ps1` | 96 checks passed on isolated MySQL, real HTTP/JWT/gRPC and independent worker/contender processes; includes concurrent DELETE/PUT; lab containers/network removed afterward |
| `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/tests/update.Tests.ps1` | 13 scenarios passed, including absence of the notification worker |
| `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/tests/setup-firebase.Tests.ps1` | 8 scenarios passed: initial/repeated setup, transient retry, invalid JSON/type, credential inside repository, preserved custom override and preflight failure |
| `docker compose exec -T app vendor/bin/pint --test` with all 35 changed PHP paths as arguments | Passed; explicit paths are required because the app container does not mount the repository's `.git` directory, so Pint `--dirty` is unavailable there |
| `docker compose exec -T app composer validate --no-check-publish` | Valid; dependency installation completed with no advisory reported |
| `docker compose exec -T app php artisan route:list --path=notification -v` | All six routes have `auth:api` and `role:customer`; fixed routes precede UUID routes |
| `docker compose config --quiet` and the equivalent with `-f docker-compose.notifications-test.yml` | Both passed |
| `git diff --check`, changed-file credential pattern scan, Postman JSON/response-body parsing | Passed; no private keys, real FCM tokens or JWT artifacts added |

The additive migration was also applied to the running local application with `docker compose exec -T app php artisan migrate --force`; only the new notification migration ran. `docker compose up -d --no-deps notification-worker` started the actual Compose worker. Runtime inspection confirmed `disabled`, queue retry_after `180`, and the same application DB; both new notification/delivery tables were empty, confirming no historical backfill. Existing user data was not reset and no real Firebase sends were performed.

The supplied service account has now also passed real OAuth and FCM validate_only checks before and after local Docker configuration. The local services use `firebase` through the ignored override and read the JSON outside the repository at `/run/secrets/firebase-service-account.json`. No actual message was delivered by these checks. Live device receipt/display, permissions and tap navigation remain to be tested with the Android implementation. Automated unit tests explicitly override the push driver to disabled and clear Firebase credential/project settings, even when the developer's Docker services are configured for real Firebase.
