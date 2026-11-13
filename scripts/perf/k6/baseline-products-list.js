/**
 * Baseline — GET /api/v1/products?per_page=20 (public, 200).
 * Matches ProductController::index → ProductService::getAll (no category filter).
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
    products_list: {
      executor: 'constant-vus',
      vus: Number(__ENV.VUS || 20),
      duration: __ENV.DURATION || '60s',
    },
  },
  tags: { phase: 'baseline', endpoint: 'products_list' },
  thresholds: {
    http_req_failed: ['rate==0'],
    checks: ['rate==1'],
  },
};

export default function () {
  const res = http.get(`${baseUrl()}/api/v1/products?per_page=20`, {
    tags: { name: 'GET /api/v1/products' },
    headers: { Accept: 'application/json' },
  });

  check(res, {
    'status is 200': (r) => r.status === 200,
    'success true': (r) => r.json('success') === true,
    'page contains products': (r) => {
      const rows = r.json('data');
      return Array.isArray(rows) && rows.length > 0 && rows.length <= 20;
    },
  });

  sleep(Number(__ENV.THINK_TIME || 0.3));
}
