import http from 'k6/http';
import grpc from 'k6/net/grpc';
import { check, fail, sleep } from 'k6';
import { Counter } from 'k6/metrics';

const base = (__ENV.BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const secondary = (__ENV.BASE_URL2 || base).replace(/\/$/, '');
const inventory = __ENV.INVENTORY_ADDR || '127.0.0.1:9090';
const control = 'http://127.0.0.1:18443';
const mode = __ENV.CASE || 'last-piece';
const client = new grpc.Client();
client.load(['../proto'], 'inventory.proto');
const image = open('../postman/fixtures/product.png', 'b');
const run = `qa-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`;
const service = 'inventory.InventoryService/';
const completed = new Counter('audit_completed');
let admin;

export const options = {
  scenarios: { audit: { executor: 'shared-iterations', vus: 1, iterations: 1, maxDuration: '4m' } },
  thresholds: { checks: ['rate==1'], audit_completed: ['count==1'] },
  batchPerHost: 8,
};

function evidence(event, details) {
  console.log(JSON.stringify({ run, case: mode, event, ...details }));
}

function assert(category, condition, description, details = {}) {
  check(condition, { [`${category} | ${description}`]: Boolean });
  if (!condition) evidence('violation', { category, description, ...details });
}

function json(response) {
  try { return response.json(); } catch (_) { return null; }
}

function trace(response) {
  return { status: response.status, ms: response.timings.duration, body: json(response) ?? String(response.body).slice(0, 400) };
}

function request(method, path, token, payload, host = base, headers = {}) {
  const multipart = payload && payload.image;
  const params = { headers: { Accept: 'application/json', ...headers }, timeout: '15s', redirects: 0 };
  if (token) params.headers.Authorization = `Bearer ${token}`;
  if (payload !== undefined && !multipart) params.headers['Content-Type'] = 'application/json';
  return { method, url: `${host}/api/v1${path}`, body: multipart ? payload : JSON.stringify(payload), params };
}

function send(method, path, token, payload, host, headers) {
  const req = request(method, path, token, payload, host, headers);
  return http.request(req.method, req.url, req.body, req.params);
}

function required(response, status, step) {
  const body = json(response);
  if (response.status !== status || !body || body.success !== true || !body.data) {
    fail(`${step}: expected HTTP ${status} with success and data, got ${response.status} (${body?.message || 'no JSON message'})`);
  }
  return body.data;
}

function account(label) {
  const password = `Audit-${run}!`;
  const data = required(send('POST', '/auth/register', null, {
    name: `Adversarial QA ${label}`, email: `${run}-${label}@example.test`, password,
    password_confirmation: password,
  }), 201, `register ${label}`);
  if (!data.token || data.user?.role !== 'customer') fail(`Invalid customer session for ${label}`);
  return data.token;
}

function fixture(label, quantity) {
  const result = required(send('POST', '/products', admin, {
    category_id: __ENV.CATEGORY_ID, name: `${run} ${label}`, price: '12.50',
    initial_quantity: String(quantity), image: http.file(image, 'product.png', 'image/png'),
  }, base, { 'Idempotency-Key': `${run}-${label}` }), 201, `create product ${label}`);
  if (!Number.isSafeInteger(result.id) || result.id <= 0) fail('Fixture returned an invalid product ID');
  const baseline = readStock(result.id);
  evidence('fixture', { productId: result.id, label, baseline });
  if (baseline.http !== quantity || baseline.grpc !== quantity) fail('Fixture baseline disagrees with documented initial stock');
  return result.id;
}

function rpc(method, payload) {
  return client.invoke(service + method, payload, { timeout: '5s' });
}

function grpcStock(productId) {
  const result = rpc('GetStock', { productId: String(productId) });
  if (result.status !== grpc.StatusOK || String(result.message?.productId) !== String(productId)) {
    fail(`GetStock observer failed for product ${productId} with gRPC status ${result.status}`);
  }
  // Proto3 JSON may omit a scalar with the default value zero.
  return result.message.availableQuantity ?? 0;
}

function readStock(productId) {
  return {
    http: required(send('GET', `/products/${productId}`), 200, 'read product availability').available_quantity,
    grpc: grpcStock(productId),
  };
}

function cart(token) {
  return required(send('GET', '/cart', token), 200, 'read cart').items;
}

function cartLines(items) {
  return JSON.stringify(items.map((line) => [line.id, line.product.id, line.quantity]).sort((a, b) => a[0] - b[0]));
}

function orders(token) {
  return required(send('GET', '/orders?page=1', token), 200, 'read orders');
}

function add(token, productId, quantity) {
  const { items } = required(send('POST', '/cart/items', token, { product_id: productId, quantity }), 201, 'add fixture to cart');
  const item = items.find((entry) => Number(entry.product?.id) === productId);
  if (!item || item.quantity !== quantity) fail('Fixture cart does not contain the requested quantity');
  return item.id;
}

function observe(productIds, tokens, label) {
  const stock = productIds.map((id) => ({ id, ...readStock(id) }));
  const state = { stock, orders: tokens.map(orders), carts: tokens.map(cart) };
  evidence(label, state);
  stock.forEach((point) => {
    assert('Data Inconsistency', point.http === point.grpc, 'Laravel and gRPC agree on settled availability', point);
    assert('Business Logic Bypass', point.grpc >= 0, 'Available stock is nonnegative', point);
  });
  return state;
}

function ledger(state, initial) {
  const all = state.orders.flat().filter((order) => order.status !== 'cancelled');
  for (const { id, grpc: available } of state.stock) {
    const units = all.flatMap((order) => order.items)
      .filter((item) => Number(item.product_id) === id)
      .reduce((total, item) => total + Number(item.quantity), 0);
    assert('Business Logic Bypass', units <= initial[id], 'Persisted units do not exceed initial stock', { id, units, initial: initial[id] });
    assert('Data Inconsistency', available + units === initial[id], 'Available stock plus persisted non-cancelled units equals initial stock', { id, available, units, initial: initial[id] });
  }
}

function checkoutRace(sameCart) {
  const a = account('a');
  const b = sameCart ? a : account('b');
  const initial = sameCart ? 2 : 1;
  const product = fixture('race', initial);
  add(a, product, 1);
  if (!sameCart) add(b, product, 1);
  const attempts = http.batch([
    request('POST', '/orders', a), request('POST', '/orders', b, undefined, secondary),
  ]);
  evidence('checkout_race', { distinctGateways: base !== secondary, attempts: attempts.map(trace) });
  const after = observe([product], sameCart ? [a] : [a, b], 'after_checkout_race');
  const persisted = after.orders.flat();
  assert('Business Logic Bypass', persisted.length <= 1, 'One cart snapshot or last unit cannot create two orders', { ids: persisted.map((order) => order.id) });
  const statuses = attempts.map((response) => response.status).sort((x, y) => x - y);
  assert('Contract', statuses[0] === 201 && statuses[1] === (sameCart ? 422 : 409), 'Race returns one creation and one documented rejection', { statuses });
  const winners = attempts.filter((response) => response.status === 201).map((response) => json(response)?.data?.id);
  assert('Data Inconsistency', winners.length === persisted.length && winners.every((id) => persisted.some((order) => order.id === id)), 'Acknowledged orders match persisted orders', { winners });
  ledger(after, { [product]: initial });
}

function atomicCheckout() {
  const a = account('a');
  const controlSku = fixture('control', 2);
  const scarce = fixture('scarce', 1);
  add(a, controlSku, 1);
  add(a, scarce, 2);
  const before = cartLines(cart(a));
  const attempt = send('POST', '/orders', a);
  evidence('atomic_checkout', { attempt: trace(attempt) });
  const after = observe([controlSku, scarce], [a], 'after_atomic_checkout');
  assert('Contract', attempt.status === 409, 'Insufficient stock returns HTTP 409');
  assert('Business Logic Bypass', after.orders[0].length === 0, 'Cannot place an order containing an unavailable quantity');
  assert('Data Inconsistency', after.stock[0].grpc === 2 && after.stock[1].grpc === 1 && cartLines(after.carts[0]) === before, 'Rejected basket leaves both SKUs and the cart unchanged');
  ledger(after, { [controlSku]: 2, [scarce]: 1 });
}

function change(id, status, host) {
  return send('PATCH', `/orders/${id}/status`, admin, { status }, host);
}

function pendingOrder(token, product) {
  add(token, product, 1);
  return required(send('POST', '/orders', token), 201, 'place order for status probe').id;
}

function processingOrder(id) {
  required(change(id, 'confirmed'), 200, 'confirm order');
  required(change(id, 'processing'), 200, 'start processing');
}

function statusRace() {
  const a = account('a');
  const product = fixture('status-race', 1);
  const id = pendingOrder(a, product);
  processingOrder(id);
  const path = `/orders/${id}/status`;
  const results = http.batch([
    request('PATCH', path, admin, { status: 'shipped' }),
    request('PATCH', path, admin, { status: 'cancelled' }, secondary),
  ]);
  evidence('ship_cancel_race', { id, attempts: results.map(trace) });
  const after = observe([product], [a], 'after_ship_cancel');
  const final = after.orders[0].find((order) => order.id === id)?.status;
  assert('Business Logic Bypass', results.filter((response) => response.status === 200).length <= 1, 'Shipment and cancellation cannot both succeed');
  assert('Contract', ['shipped', 'cancelled'].includes(final), 'One valid terminal operation wins');
  ledger(after, { [product]: 1 });
}

function httpFuzz() {
  for (const method of ['POST', 'PUT']) {
    const a = account(method.toLowerCase());
    const product = fixture(method.toLowerCase(), 1);
    const itemId = method === 'PUT' ? add(a, product, 1) : null;
    const path = method === 'POST' ? '/cart/items' : `/cart/items/${itemId}`;
    const original = method === 'PUT' ? 1 : undefined;
    for (const quantity of [0, -1, 1001, -2147483648, 2147483648, 4294967297, '4294967297', 1.5, null, [], { value: 1 }]) {
      const response = send(method, path, a, method === 'POST' ? { product_id: product, quantity } : { quantity });
      const line = cart(a).find((entry) => Number(entry.product?.id) === product);
      evidence('http_quantity_probe', { method, product, quantity, stored: line?.quantity, response: trace(response) });
      assert('Contract', response.status === 422, 'Out-of-domain cart quantity is rejected', { method, quantity, status: response.status });
      if (line?.quantity === original) continue;
      assert('Business Logic Bypass', false, 'Out-of-domain input changed the persisted cart quantity', { method, quantity, stored: line?.quantity });
      const attempt = send('POST', '/orders', a);
      evidence('checkout_after_invalid_cart_write', { method, quantity, attempt: trace(attempt) });
      break;
    }
    const after = observe([product], [a], 'after_http_quantity_probe');
    assert('Business Logic Bypass', after.orders[0].length === 0, 'Out-of-domain cart input did not create an order');
    ledger(after, { [product]: 1 });
  }
}

function item(productId, quantity) {
  return { productId: String(productId), quantity };
}

function reservation(label, items) {
  return { reservationId: `${run}-${label}`, items };
}

function accepted(result) {
  return result.status === grpc.StatusOK && result.message?.success === true;
}

function rejected(label, productIds, method, payload, requireRejection = true) {
  const before = productIds.map(grpcStock);
  const result = rpc(method, payload);
  const after = productIds.map(grpcStock);
  evidence('grpc_probe', { label, method, payload, before, after, result });
  if (requireRejection) assert('Business Logic Bypass', !accepted(result), `${label} is rejected`);
  assert('Data Inconsistency', before.every((quantity, i) => quantity === after[i]), `${label} leaves availability unchanged`);
  assert('Business Logic Bypass', after.every((quantity) => quantity >= 0), `${label} leaves nonnegative availability`);
  assert('Contract', [grpc.StatusOK, grpc.StatusInvalidArgument, grpc.StatusNotFound, grpc.StatusFailedPrecondition, grpc.StatusAlreadyExists, grpc.StatusOutOfRange, grpc.StatusResourceExhausted].includes(result.status), `${label} produces a business response or validation status`, { status: result.status });
  return before.every((quantity, i) => quantity === after[i]);
}

async function grpcProbes() {
  const product = fixture('grpc-validation', 1);
  const other = fixture('grpc-atomic', 2);
  const probes = [
    ['negative', [product], 'ReserveStock', reservation('negative', [item(product, -1)])],
    ['zero', [product], 'ReserveStock', reservation('zero', [item(product, 0)])],
    ['int32-min', [product], 'ReserveStock', reservation('min', [item(product, -2147483648)])],
    ['duplicate-line oversubscription', [product], 'ReserveStock', reservation('duplicate-line', [item(product, 1), item(product, 1)])],
    ['partial batch', [other, product], 'ReserveStock', reservation('partial', [item(other, 1), item(product, 2)])],
    ['missing reservation ID', [product], 'ReserveStock', { items: [item(product, 1)] }],
    ['empty batch', [product], 'ReserveStock', reservation('empty', [])],
    ['invalid product ID', [product], 'ReserveStock', reservation('invalid-id', [item(0, 1)])],
    ['AddStock overflow', [product], 'AddStock', item(product, 2147483647)],
    ['negative AddStock', [product], 'AddStock', item(product, -1)],
  ];
  for (const probe of probes) {
    if (!rejected(...probe)) return; // Preserve the first mutation and a clean repro.
  }

  const reserved = reservation('replay', [item(product, 1)]);
  const first = rpc('ReserveStock', reserved);
  if (!accepted(first) || grpcStock(product) !== 0) fail('Could not establish direct gRPC replay fixture');
  rejected('same reservation replay', [product], 'ReserveStock', reserved, false);
  rejected('same ID with changed basket', [product, other], 'ReserveStock', { ...reserved, items: [item(other, 1)] }, false);
  const release = rpc('ReleaseStock', { reservationId: reserved.reservationId });
  evidence('first_release', { release, available: grpcStock(product) });
  if (!accepted(release) || grpcStock(product) !== 1) fail('Initial release did not restore one unit');
  rejected('repeated release', [product], 'ReleaseStock', { reservationId: reserved.reservationId }, false);

  const raceSku = fixture('grpc-race', 1);
  const attempts = await Promise.all(['race-a', 'race-b'].map((label) =>
    client.asyncInvoke(service + 'ReserveStock', reservation(label, [item(raceSku, 1)]), { timeout: '5s' })));
  const left = grpcStock(raceSku);
  const successes = attempts.filter(accepted).length;
  evidence('grpc_last_unit_race', { productId: raceSku, attempts, left, successes });
  assert('Business Logic Bypass', successes <= 1 && left >= 0, 'Concurrent reservations do not oversell');
  assert('Data Inconsistency', successes + left === 1, 'Successful reservations and available stock reconcile');
}

function faultProbe() {
  const method = __ENV.RPC || 'ReserveStock';
  const fault = __ENV.FAULT || 'delay';
  const delayMs = Number(__ENV.DELAY_MS || 5500);
  const budget = Number(__ENV.OUTAGE_MAX_MS || 6000);
  if (!['GetStock', 'ReserveStock', 'ReleaseStock', 'CommitReservation'].includes(method) ||
      !['internal', 'unavailable', 'reset', 'delay'].includes(fault) || !Number.isFinite(budget) || budget <= 0) {
    fail('Invalid RPC, FAULT, or OUTAGE_MAX_MS setting');
  }
  const a = account('a');
  const product = fixture('fault', 1);
  let orderId;
  if (method === 'ReserveStock') add(a, product, 1);
  if (method === 'ReleaseStock' || method === 'CommitReservation') orderId = pendingOrder(a, product);
  if (method === 'CommitReservation') processingOrder(orderId);
  const before = observe([product], [a], 'before_fault');
  ledger(before, { [product]: 1 });
  const arm = http.post(`${control}/arm`, JSON.stringify({ id: run, method, mode: fault, delayMs }), {
    headers: { 'Content-Type': 'application/json' }, timeout: '3s',
  });
  if (arm.status !== 200) fail(`Unable to arm relay: HTTP ${arm.status}`);
  const target = method === 'CommitReservation' ? 'shipped' : 'cancelled';
  let response;
  let elapsedMs;
  let relay;
  try {
    const started = Date.now();
    if (method === 'GetStock') response = send('GET', `/products/${product}`);
    else if (method === 'ReserveStock') response = send('POST', '/orders', a);
    else response = change(orderId, target);
    elapsedMs = Date.now() - started;
    if (fault === 'delay') sleep(delayMs / 1000 + 0.5);
    relay = json(http.get(`${control}/state`, { timeout: '3s' }));
  } finally {
    http.del(`${control}/arm`, null, { timeout: '3s' });
  }
  evidence('fault_response', { method, fault, relay, elapsedMs, response: trace(response) });
  if (relay?.id !== run || !relay.hits) fail('Fault missed the request. Verify the gateway actually uses the relay.');
  if (fault === 'delay' && relay.upstreamStatus !== 0) fail('Delay did not hide a gRPC OK response. Inspect relay logs.');
  const body = json(response);
  const envelope = body?.success === false && body.data === null && Object.hasOwn(body, 'errors') &&
    typeof body.message === 'string' && !/(stack.?trace|\bexception\b|sqlstate|\/var\/www|grpc-status)/i.test(JSON.stringify(body));
  const status = response.status;
  const sanitizedFailure = fault === 'internal' ? [500, 502, 503].includes(status) : status === 503;
  const recovered = status === (method === 'ReserveStock' ? 201 : 200) && body?.success === true;
  if (fault === 'delay' && recovered && relay.hits === 1 && elapsedMs >= delayMs && elapsedMs < budget) {
    fail('The held response arrived within the gateway budget. Increase DELAY_MS to test the deadline.');
  }
  assert('Unhandled gRPC Error Statuses', (sanitizedFailure && envelope || (fault === 'reset' || relay.hits > 1) && recovered) && elapsedMs < budget,
    'Injected gRPC fault is bounded and produces a controlled HTTP response', { status, budget, method, fault });
  const health = send('GET', '/health');
  const catalog = send('GET', '/products');
  assert('Unhandled gRPC Error Statuses', health.status === 200 && catalog.status === 200, 'Gateway health and catalog are still reachable', { health: health.status, catalog: catalog.status });
  const after = observe([product], [a], 'after_fault_before_retry');
  // An ambiguous ReserveStock result may legitimately hold stock against a
  // durable checkout intent. Reconcile the completed order after recovery.
  if (recovered || method !== 'ReserveStock') ledger(after, { [product]: 1 });

  if (!recovered && method === 'ReserveStock') {
    const cartPreserved = cartLines(after.carts[0]) === cartLines(before.carts[0]);
    assert('Data Inconsistency', after.orders[0].length === 0 && cartPreserved, 'Failed checkout preserves the cart and leaves no order');
    if (after.orders[0].length === 0 && cartPreserved) {
      const pending = required(send('GET', '/cart', a), 200, 'read pending checkout');
      assert('Data Inconsistency', pending.checkout_pending === true, 'Ambiguous checkout has a durable pending intent');
      assert('Data Inconsistency', after.stock[0].grpc === (fault === 'delay' ? 0 : 1), 'Stock matches whether the injected call reached inventory');
      const retry = send('POST', '/orders', a);
      evidence('retry', { response: trace(retry) });
      const settled = observe([product], [a], 'after_retry');
      assert('Data Inconsistency', retry.status === 201 && settled.orders[0].length === 1 && settled.carts[0].length === 0, 'Recovered checkout consumes exactly one cart snapshot');
      ledger(settled, { [product]: 1 });
    }
  }
  if (!recovered && orderId && after.orders[0][0]?.status === before.orders[0][0]?.status) {
    const followup = method === 'CommitReservation' && fault === 'delay' ? 'cancelled' : target;
    const retry = change(orderId, followup);
    evidence('status_followup', { followup, response: trace(retry) });
    const settled = observe([product], [a], 'after_status_followup');
    assert('Data Inconsistency', retry.status === 200 && settled.orders[0][0]?.status === followup, 'Order can complete the documented transition after recovery');
    ledger(settled, { [product]: 1 });
  }
}

export default async function () {
  if (__ENV.QA_DISPOSABLE !== '1' || !__ENV.ADMIN_TOKEN || !/^\d+$/.test(__ENV.CATEGORY_ID || '')) {
    fail('Set QA_DISPOSABLE=1, ADMIN_TOKEN, and CATEGORY_ID for the isolated lab.');
  }
  admin = __ENV.ADMIN_TOKEN;
  const cases = {
    'last-piece': () => checkoutRace(false), 'duplicate-checkout': () => checkoutRace(true),
    'atomic-checkout': atomicCheckout, 'ship-cancel': statusRace,
    'http-fuzz': httpFuzz, 'grpc-probes': grpcProbes, fault: faultProbe,
  };
  if (!Object.hasOwn(cases, mode)) fail(`Unknown CASE ${mode}`);
  client.connect(inventory, { plaintext: true, timeout: '5s' });
  try { await cases[mode](); } finally { client.close(); }
  completed.add(1);
}
