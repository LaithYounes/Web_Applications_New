# Durable checkout recovery

## The failure this prevents

`ReserveStock` can commit successfully in PostgreSQL while Laravel receives a deadline or connection error. That error does not establish whether inventory changed. Losing the reservation ID, creating a new ID on retry, or blindly compensating can leave stock held without an order or produce an additional stock effect.

The checkout path now persists its identity before making the RPC and completes that same operation after uncertainty. It does not claim atomic transactions across MySQL and PostgreSQL.

## Transaction boundaries

1. Lock the customer's cart. Create one `pending_checkouts` row for that cart, containing a reservation UUID, total, and immutable product, price, and quantity snapshot. Commit this intent before calling inventory. An existing intent is reused.
2. Lock the cart and intent, then call `ReserveStock` with the stored UUID and quantities. PostgreSQL takes a transaction-scoped advisory lock for that UUID, including its first use before any reservation rows exist. Reserve, commit, and release operations use the same lock.
3. An identical replay of an entirely `ACTIVE` reservation succeeds without holding stock again, even for the last available unit. Different items, different quantities, and finalized reservation IDs are rejected.
4. After acknowledgement, save the order, items, and initial status history, delete the cart lines, and remove the intent in one MySQL transaction. A concurrent finisher can return the order already saved for this reservation.

A communication failure or local persistence failure leaves the intent available for recovery. A definitive inventory business rejection removes the intent and preserves the cart so the customer can change it. Unconfirmed reservations are not blindly released.

Cart writes acquire the same cart lock and return `409` while an intent exists. The stored snapshot keeps a retry independent of subsequent catalog price changes or product soft deletion.

## HTTP behavior and the frontend collection

Existing routes and request bodies remain available. `GET /api/v1/cart` adds `data.checkout_pending`.

- After an ambiguous checkout response, read the cart and order list. A `503` may be followed by a completed order when background recovery succeeds.
- Retrying the existing **Place order** request resumes a pending intent with its original UUID. Once that intent has completed, the cart is empty; a later checkout request is a new attempt and may return the existing empty-cart validation error.
- While `checkout_pending` is true, cart mutations return `409`.
- The existing frontend collection can exercise these routes without modification. The backend QA collection's deadline scenario documents fault setup and recovery checks.

The frontend must tolerate additional JSON fields and show the pending state appropriately. Its implementation is maintained outside this repository and was not verified here.

## Automatic recovery and operations

The `checkout-recovery` Compose service runs `php artisan schedule:work` with the application image and shared vendor volume. The schedule runs `orders:recover-checkouts` every minute with overlap protection.

The command defaults to intents whose `updated_at` is at least 60 seconds old and processes up to 100, oldest first. Failed attempts are logged and their timestamps moved forward so one failing operation does not monopolize every batch. An uncertain intent is retained for later recovery rather than discarded after an arbitrary number of attempts. Completion time depends on service availability and batch backlog.

Inspect the service and logs with:

```powershell
docker compose ps
docker compose logs --tail 100 checkout-recovery inventory-service
```

For a deliberate manual retry after restoring inventory connectivity:

```powershell
docker compose exec -T app php artisan orders:recover-checkouts --min-age=0 --limit=100
```

Monitor pending intent count and age together with recovery errors. A continuously growing age or backlog requires investigation. Old orphan reservations created before this feature have no durable intent and need separate investigation; this migration does not identify or release them automatically.

## Upgrading an existing local checkout

Run from the repository root, with Docker Desktop running. Commit or otherwise preserve any local work before pulling. These commands keep application traffic and the old scheduler stopped while the new schema is installed.

```powershell
docker compose stop
git pull --ff-only
docker compose build
docker compose up -d --no-build --wait mysql inventory-db inventory-service
docker compose logs -f --tail 50 inventory-service
```

Wait for `gRPC Server started`, then press `Ctrl+C` to leave the log stream. If `composer.lock` changed, first run `docker compose run --rm --no-deps -T app composer install --no-interaction --prefer-dist`. Continue with:

```powershell
docker compose run --rm --no-deps -T app php artisan migrate --force
docker compose up -d --no-build app checkout-recovery
docker compose exec -T app php artisan schedule:list
(Invoke-RestMethod 'http://localhost:8000/api/v1/health').data.status
```

Run each command only after the previous step succeeds. Inventory's replay support must be deployed before checkout recovery begins. Both new migrations must succeed before serving the updated application or starting its scheduler. `git pull` alone does not rebuild the inventory image or install database changes. Keep existing database volumes; no reset or reseed is required for this upgrade.

## Verification

The Laravel regression suite, including checkout recovery and status history, passed with **62 tests and 527 assertions**. Run it using `docker compose exec -T app php artisan test --compact`; its test configuration uses in-memory SQLite and mocked RPCs.

Inventory validation passed **6 Java tests**, including 5 PostgreSQL integration tests for last-unit replay, changed baskets, finalized IDs, and concurrent same-ID requests. `ReservationIdempotencyTests` requires an explicitly supplied disposable PostgreSQL database through `INVENTORY_QA_DB_URL` (`jdbc:postgresql:...`), `INVENTORY_QA_DB_USER`, and `INVENTORY_QA_DB_PASSWORD`. Without that URL the integration class is skipped. Run `./mvnw test` from `inventory-service` with these variables set in an environment that can reach that disposable database.

A separate live lab verified six HTTP-to-gRPC scenarios against disposable MySQL and PostgreSQL databases: normal checkout, lost reply for the last unit, lost reply with manual background recovery, connection reset before inventory received the call, concurrent checkouts through separate HTTP servers, and scheduled recovery without a customer retry. Each fixture ended with one order, its matching reservation ID, correct available stock, an empty cart, and no pending intent. The scheduled test aged its intent to make it immediately eligible. These checks establish the exercised recovery behavior; they do not establish that every possible project behavior has been tested.
