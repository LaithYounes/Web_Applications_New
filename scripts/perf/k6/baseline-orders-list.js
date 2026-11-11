/**
 * Baseline — GET /api/v1/orders (auth:api, customer, 200).
 * Customer path is OrderService::getUserOrders (10 per page), not the admin scan.
 * Login: POST /api/v1/auth/login → data.token / data.user.role.
 */
import http from 'k6/http';
import { check, sleep } from 'k6';
import { baseUrl } from './lib/safety.js';
import { expectHttp200, handleSummary, trendStats } from './lib/summary.js';

expectHttp200();

export { handleSummary };

export const options = {
  summaryTrendStats: trendStats,
  scenarios: {
    orders_list: {
      executor: 'constant-vus',
      vus: Number(__ENV.VUS || 20),
      duration: __ENV.DURATION || '60s',
    },
  },
  tags: { phase: 'baseline', endpoint: 'orders_list' },
  thresholds: {
    http_req_failed: ['rate==0'],
    checks: ['rate==1'],
  },
};

function authHeaders(token) {
  return {
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
    'Content-Type': 'application/json',
  };
}

export function setup() {
  let token = __ENV.PERF_JWT || '';
  if (!token) {
    const email = __ENV.PERF_USER_EMAIL || 'perf.user.00001@example.test';
    const password = __ENV.PERF_USER_PASSWORD || 'PerfLoad2026!';
    const login = http.post(
      `${baseUrl()}/api/v1/auth/login`,
      JSON.stringify({ email, password }),
      { headers: { Accept: 'application/json', 'Content-Type': 'application/json' } },
    );
    if (login.status !== 200 || login.json('success') !== true) {
      throw new Error(`Customer login failed: HTTP ${login.status}`);
    }
    token = login.json('data.token');
    if (login.json('data.user.role') !== 'customer') {
      throw new Error('Orders baseline requires role=customer so it measures getUserOrders, not getAllOrders.');
    }
  }

  // Page 1 is the measured query. Page 10 is empty for tail users (some have only one page).
  const probe = http.get(`${baseUrl()}/api/v1/orders?page=1`, { headers: authHeaders(token) });
  const pageCount = Array.isArray(probe.json('data')) ? probe.json('data').length : 0;
  if (probe.status !== 200 || probe.json('success') !== true || pageCount < 1) {
    throw new Error(`Orders page 1 is not usable: HTTP ${probe.status}, rows ${pageCount}`);
  }

  return { token };
}

export default function (data) {
  const res = http.get(`${baseUrl()}/api/v1/orders?page=1`, {
    tags: { name: 'GET /api/v1/orders?page=1' },
    headers: authHeaders(data.token),
  });

  check(res, {
    'status is 200': (r) => r.status === 200,
    'success true': (r) => r.json('success') === true,
    'page contains orders': (r) => {
      const rows = r.json('data');
      return Array.isArray(rows) && rows.length > 0 && rows.length <= 10;
    },
  });

  sleep(Number(__ENV.THINK_TIME || 0.3));
}
