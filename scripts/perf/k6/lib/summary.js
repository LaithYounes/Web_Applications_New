/**
 * Baseline summary: p50/p95/p99, throughput, error rate.
 * Non-2xx responses count as http_req_failed.
 */
import http from 'k6/http';

export const trendStats = ['avg', 'min', 'med', 'max', 'p(50)', 'p(90)', 'p(95)', 'p(99)', 'count'];

export function expectHttp200() {
  http.setResponseCallback(http.expectedStatuses(200));
}

export function handleSummary(data) {
  const duration = data.metrics.http_req_duration?.values || {};
  const failed = data.metrics.http_req_failed?.values || {};
  const reqs = data.metrics.http_reqs?.values || {};
  const checks = data.metrics.checks?.values || {};
  const report = {
    phase: 'baseline',
    p50_ms: duration['p(50)'] ?? null,
    p95_ms: duration['p(95)'] ?? null,
    p99_ms: duration['p(99)'] ?? null,
    throughput_rps: reqs.rate ?? null,
    error_rate: failed.rate ?? null,
    checks_rate: checks.rate ?? null,
    http_reqs: reqs.count ?? null,
  };

  const files = {
    stdout: `${JSON.stringify(report)}\n`,
  };
  if (__ENV.K6_METRICS_PATH) {
    files[__ENV.K6_METRICS_PATH] = JSON.stringify(report, null, 2);
  }
  if (__ENV.K6_SUMMARY_PATH) {
    files[__ENV.K6_SUMMARY_PATH] = JSON.stringify(data);
  }

  return files;
}
