# Black-box inventory and checkout audit

## Findings and evidence required

This audit uses the two ecommerce Postman collections and `proto/inventory.proto` as contracts. The Postman collection’s **08 gRPC Deadline - Known Bug** scenario describes a possible orphan reservation after a 503. That is a documented lead. The probes below identify the observable evidence needed to reproduce it and distinguish it from other failures.

| Category | Attack | Evidence that supports a finding |
| --- | --- | --- |
| Data Inconsistency | Hide the response to a real `ReserveStock` call until the gateway times out, then inspect and retry. | Checkout returned 503, no order persisted, and direct `GetStock` shows a missing unit after the relay recovered. A subsequent 409 with the same intact cart shows customer impact. |
| Data Inconsistency | Put one fulfillable SKU and one oversubscribed SKU in a single checkout. Repeat directly with `ReserveStock`. | Checkout or RPC refuses the basket, but one SKU’s availability drops or the cart changes. Compare both SKU baselines. |
| Data Inconsistency | Interrupt `ReleaseStock` or `CommitReservation`, then retry cancellation or attempt cancellation after an ambiguous shipment. | A non-cancelled order coexists with restored inventory, or a cancelled order fails to restore stock. If cancellation is blocked while the order stays `processing`, record a recovery failure and the observed status. `GetStock` alone cannot distinguish held from committed stock. |
| Business Logic Bypass | Send simultaneous checkouts from two carts for the last unit. Separately, send the same cart twice while two units are available. | Two distinct persisted order IDs for the last unit or the same cart snapshot. A duplicate can pass stock reconciliation when there is enough stock. |
| Business Logic Bypass | Ship and cancel the same `processing` order from different gateway workers. | Both mutually exclusive transitions acknowledge success, or the final order status and stock disagree. |
| Business Logic Bypass | Send negative, zero, oversized, fractional, and wrong-type quantities to both cart write paths. Send valid protobuf encodings of negative quantities, duplicate lines and `int32` overflow sums directly to inventory. Replay reservation IDs and release calls. | An out-of-domain value persists, is truncated into a purchase, creates stock, or drives availability below zero. A replay must never create a second stock effect. Interpret success for an unchanged replay alongside the actual stock movement. |
| Unhandled gRPC Error Statuses | Inject `INTERNAL` (13), `UNAVAILABLE` (14), a connection reset, and a reply delayed past the deadline. | Laravel hangs past the configured budget, returns a connection failure or an unexpected status or error envelope, leaks diagnostic internals, or loses gateway health. A sanitized, bounded HTTP 500 for `INTERNAL` is treated as handled because the contract does not specify an exact mapping for it. |

The checkout ledger assumes fresh products and buyers with no other traffic. For each SKU, initial stock must equal direct available stock plus units in persisted non-cancelled orders, including shipped orders. After a request settles, Laravel product availability must equal direct `GetStock`. Compare persisted IDs and quantities even if the HTTP requests returned errors. There is no payment endpoint in the supplied contract, so duplicate checkout tests cover duplicate orders and inventory consumption.

## Run against a disposable lab

Use a lab with test databases, an existing category, and an administrator bearer token. The script registers unique customers and creates uniquely named products through the public API. Test orders, products and reservations can persist after a run. Reset the disposable databases between campaigns. Run one case at a time against a given relay.

Use k6 2.x and Node.js 22+. Run these commands in PowerShell from the workspace root. Set `INVENTORY_ADDR` to the direct plaintext inventory listener, which must bypass any fault relay. Set `BASE_URL2` to a second gateway worker that shares the same databases, inventory and JWT signing configuration. The Postman collection gives a Compose example in **04 Insufficient Stock & Last Piece**. If one gateway serializes requests, a clean race result is inconclusive. Re-run the races on fresh fixtures across multiple iterations of the campaign because the scheduler controls overlap.

```powershell
$env:QA_DISPOSABLE = '1'
$env:BASE_URL = 'http://127.0.0.1:8000'
$env:BASE_URL2 = 'http://127.0.0.1:8001'
$env:INVENTORY_ADDR = '127.0.0.1:9090'
$env:CATEGORY_ID = '1'  # Replace with a category ID from the lab.
$env:ADMIN_TOKEN = '<lab admin bearer token>'

foreach ($caseName in @('last-piece', 'duplicate-checkout', 'atomic-checkout', 'ship-cancel', 'http-fuzz', 'grpc-probes')) {
    k6 run -e "CASE=$caseName" .\adversarial-qa\audit.js
    Write-Host "$caseName exit code $LASTEXITCODE"
}
```

`grpc-probes` stops after the first unexpected availability mutation so subsequent probes cannot mask the evidence. Reservation and stock-write requests go directly to the configured lab inventory listener. Exposure of that listener to an untrusted client is a separate deployment question.

## Inject one gRPC fault, then let traffic recover

Start the relay in a separate terminal. Set the lab gateway’s inventory target to the relay on port `19090` using its runtime configuration. Keep the relay’s upstream pointed at the real lab inventory listener. For a containerized gateway, run the relay on the isolated lab network or bind it to a host address reachable from the gateway. Its second argument selects the data-plane bind address. The control port `18443` always stays on loopback. Run k6 on the same host as the control port.

```powershell
node .\adversarial-qa\fault-relay.cjs 127.0.0.1:9090
```

```powershell
k6 run -e CASE=fault -e RPC=ReserveStock -e FAULT=delay .\adversarial-qa\audit.js
k6 run -e CASE=fault -e RPC=ReserveStock -e FAULT=reset .\adversarial-qa\audit.js
k6 run -e CASE=fault -e RPC=ReserveStock -e FAULT=unavailable .\adversarial-qa\audit.js
k6 run -e CASE=fault -e RPC=ReserveStock -e FAULT=internal .\adversarial-qa\audit.js
k6 run -e CASE=fault -e RPC=GetStock -e FAULT=unavailable .\adversarial-qa\audit.js
k6 run -e CASE=fault -e RPC=ReleaseStock -e FAULT=delay .\adversarial-qa\audit.js
k6 run -e CASE=fault -e RPC=CommitReservation -e FAULT=delay .\adversarial-qa\audit.js
```

The relay faults the first matching call per run. `reset` closes the gateway-side connection before forwarding. `internal` and `unavailable` return a gRPC status before forwarding. `delay` forwards the call and holds the real unary reply after upstream completion. A matching call count above one records gateway retry activity. The default hold is 5.5 seconds, above the documented 3-second deadline. Adjust `DELAY_MS` if the lab uses a different deadline and use `OUTAGE_MAX_MS` for its response budget. The hold tests a lost acknowledgement after upstream completion. The documented database-trigger scenario delays execution before completion and remains a separate reproduction.

Keep the k6 JSON log lines and the matching relay lines for the same run ID. Treat a relay miss, a failed stock read, missing concurrency, or a delay that does not actually exceed the gateway deadline as inconclusive. Upstream gRPC status `0` proves protocol completion. Mutation evidence comes from the order and stock observations because the protobuf response can carry `success: false` with status `0`. The harness exits nonzero for violated checks or incomplete runs. Use the logged response and before-and-after observations to classify the result.

Tool references: [k6 parallel HTTP requests](https://grafana.com/docs/k6/latest/javascript-api/k6-http/batch/), [k6 gRPC client](https://grafana.com/docs/k6/latest/javascript-api/k6-net-grpc/client/), [Node.js HTTP/2](https://nodejs.org/api/http2.html).
