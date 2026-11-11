# Order status history

## Stored events and atomicity

`order_status_histories` records each new order's initial status and each later status change. Entries contain an order ID, nullable previous status, new status, nullable authenticated user ID (`changed_by`), actor role (`actor_type`), and a UTC timestamp with microsecond precision (`changed_at`). The initial entry has `from_status: null`. Entries are returned oldest first by ID.

Synchronous Eloquent creation/update events append history. `Order::save()` wraps the model write and its history event in a transaction on the same database connection, including direct model saves. If a history insert fails, the order write rolls back. Service transactions also include the rest of checkout or status-update work. Saving other fields or saving an unchanged status does not append a transition.

Controllers pass the authenticated actor to the service. Customer checkout and cancellation use the customer; admin status changes use the administrator. Background recovery and model writes without an explicit actor use `system` with a null user ID. Client-supplied actor fields do not determine attribution. The actor role is stored as a snapshot; deleting a user clears the nullable user reference without deleting the history.

Customer cancellation retains both durable stages, `pending -> cancelling` and `cancelling -> cancelled`. If release acknowledgement is lost, the first stage remains recorded and a retry can finish the second. Replaying an already completed cancellation or checkout recovery does not append a duplicate transition.

## API and Postman

The existing owner/admin order-details route (`GET /api/v1/orders/{id}`) adds `data.status_history`. Creation, status-update, and cancellation responses also load history. Order list responses remain compact. Existing authorization still applies: another customer's order is not exposed.

Example entry:

```json
{
  "id": 42,
  "from_status": "pending",
  "to_status": "confirmed",
  "changed_by": 7,
  "actor_type": "admin",
  "changed_at": "2026-11-11T12:00:00.123456Z"
}
```

Use the frontend collection already in use:

1. Sign in as the customer and open **04 Customer Orders / Get my order details** for an owned order.
2. Read `data.status_history` in its existing JSON response. New orders have an initial entry.
3. Change the status through the existing authenticated admin request, or cancel a pending order through the existing customer request.
4. Send **Get my order details** again and check the previous/new statuses, actor, and timestamp.

No frontend collection edits or new routes are required. The primary order status continues to present an in-progress customer cancellation as `pending` with `cancellation_pending: true`; history exposes the actual persisted `cancelling` stage. A frontend that displays history should account for that internal stage. The frontend implementation is outside this repository, so parser and screen behavior require verification in that project.

## Installation and boundaries

Apply both new migrations before starting the updated application and scheduler; follow the [ordered upgrade steps](checkout-recovery.md#upgrading-an-existing-local-checkout).

History starts when this feature is installed. Existing orders may have an empty history until their next transition; past timestamps and actors are not fabricated. Existing order ownership and items are unchanged.

Application status writes must use the model/service path. Raw SQL, bulk query-builder updates, and event-suppressed writes bypass the Eloquent events and therefore do not produce this audit history. The table is not a tamper-proof external audit log. No public endpoint edits history entries.

## Verification

Seven focused feature tests passed with 65 assertions, covering the lifecycle and authenticated actors, interrupted cancellation and replay, invalid/unauthorized/failed transitions, insert-failure rollback, unchanged saves and outer transaction rollback, system checkout recovery, and initial-history failure. The complete Laravel suite passed with 62 tests and 527 assertions.

A disposable live HTTP/MySQL/PostgreSQL lab verified lifecycle changes, owner/admin access, another customer's `404`, cancellation replay, and 11 ordered history rows across three orders. A temporary MySQL trigger rejected history inserts: a status-update request returned `500` while the previous order status/history remained intact, and a direct model create also rolled back. Removing that trigger allowed the transitions to complete normally. The temporary trigger and lab databases were removed after verification.
