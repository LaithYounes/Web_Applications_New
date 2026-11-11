/**
 * Shared safety checks for Requirement-3 baseline load tests.
 * Refuse anything that is not the isolated performance HTTP port.
 */
export function assertPerfTarget() {
  const baseUrl = __ENV.BASE_URL || '';
  const perfFlag = __ENV.PERF_ENVIRONMENT || '';

  if (perfFlag !== '1') {
    throw new Error('Refusing load test: set PERF_ENVIRONMENT=1');
  }

  if (!/:8010\b/.test(baseUrl)) {
    throw new Error(
      `Refusing load test: BASE_URL must target performance port 8010 (got "${baseUrl}")`
    );
  }

  if (/:(8000|3307|5433|9090)\b/.test(baseUrl)) {
    throw new Error(`Refusing load test: BASE_URL looks like the demo stack (${baseUrl})`);
  }
}

export function baseUrl() {
  assertPerfTarget();
  return String(__ENV.BASE_URL).replace(/\/$/, '');
}
