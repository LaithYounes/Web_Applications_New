/**
 * Baseline — GET /api/v1/products/{id} (public, 200).
 * Matches ProductController::show → findById + one GetStock RPC.
 * Inventory outage is HTTP 503 and counts as an error.
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
    product_detail: {
      executor: 'constant-vus',
      vus: Number(__ENV.VUS || 20),
      duration: __ENV.DURATION || '60s',
    },
  },
  tags: { phase: 'baseline', endpoint: 'product_detail' },
  thresholds: {
    http_req_failed: ['rate==0'],
    checks: ['rate==1'],
  },
};

export function setup() {
  const ids = [];
  let page = 1;
  let lastPage = 1;
  let total = 0;
  do {
    const list = http.get(`${baseUrl()}/api/v1/products?per_page=50&page=${page}`, {
      headers: { Accept: 'application/json' },
    });
    if (list.status !== 200 || list.json('success') !== true) {
      throw new Error(`Unable to load products page ${page}: HTTP ${list.status}`);
    }
    lastPage = Number(list.json('meta.last_page') || 1);
    total = Number(list.json('meta.total') || 0);
    for (const product of list.json('data') || []) {
      if (Number.isInteger(product.id) && product.id > 0) {
        ids.push(product.id);
      }
    }
    page += 1;
  } while (page <= lastPage && page <= 20);

  if (!ids.length || ids.length !== total) {
    throw new Error(`Product catalog mismatch: collected ${ids.length}, meta.total ${total}`);
  }
  return { productIds: ids };
}

export default function (data) {
  const id = data.productIds[Math.floor(Math.random() * data.productIds.length)];
  const res = http.get(`${baseUrl()}/api/v1/products/${id}`, {
    tags: { name: 'GET /api/v1/products/{id}' },
    headers: { Accept: 'application/json' },
  });

  check(res, {
    'status is 200': (r) => r.status === 200,
    'success true': (r) => r.json('success') === true,
    'data.id matches': (r) => r.json('data.id') === id,
    'inventory quantity present': (r) => typeof r.json('data.available_quantity') === 'number',
  });

  sleep(Number(__ENV.THINK_TIME || 0.3));
}
