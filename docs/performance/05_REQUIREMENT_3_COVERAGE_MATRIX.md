# Requirement 3 — Coverage Matrix

Date: 2026-11-11  
Environment: `ecommerce2026-perf` / `ecommerce_main_perf`  
Baseline archive: `docs/performance/baselines/pre-opt/` (never overwritten)  
Post-opt k6: `docs/performance/baselines/post-opt/`

| Requirement | Implementation files | Automated test | Live demonstration | Actual evidence | Status |
| --- | --- | --- | --- | --- | --- |
| Isolated perf environment | `docker-compose.perf.yml`, `.env.perf`, `Validate-PerfEnvironment.ps1` | PerfEnvironmentGuard tests | `Validate-PerfEnvironment.ps1` | Containers healthy on 8010/3317/5443/9100/6389/3318 | PASS |
| ≥200k orders dataset | `PerfOrderDatasetGenerator`, `Generate-PerfOrders.ps1` | Generator feature tests | `Show-Dataset.ps1` | orders=200000, order_items=429410 | PASS |
| SQL EXPLAIN + index | migration `orders_created_at_index`, explain scripts | SQL demo verify | `Show-SqlPerformance.ps1` | Before median ~44.5ms / After ~0.042ms (IGNORE INDEX) | PASS |
| Redis catalog cache | `CatalogCache`, Redis service, `CACHE_STORE=redis` | `CatalogCacheTest`, `CatalogCacheFailureTest` | `Show-RedisCache.ps1` | MISS queries=3 → HIT queries=0; Redis db1 key present | PASS |
| Cache key includes filters/pagination | `CatalogCache::productsKey` | CatalogCacheTest | Redis key `...cat:all:per:12:page:1...` | Key shown in probe | PASS |
| Finite TTL + invalidation | TTL 60/120s; version bump on create | CatalogCacheTest invalidation | docs + test | Version bump after product create | PASS |
| No authoritative stock in Redis | CatalogCache serialize excludes stock | Code review + demo note | Show-RedisCache note | Stock only via gRPC on detail | PASS |
| Redis failure graceful | try/catch around Cache get/put | CatalogCacheFailureTest | automated | List still 200 when Redis throws | PASS |
| HTTP ETag / 304 | `ProductEtag`, `ProductController::show` | `ProductEtagTest` (3) | `Show-Etag.ps1` | 200 then 304 with empty body; stock change blocks stale 304 | PASS |
| ETag includes live stock | `ProductEtag` forces `available_quantity` | stock-change test | Show-Etag | Inventory failure → 503, no 304 | PASS |
| Cache-Control private for detail | ProductController headers | ProductEtagTest | Show-Etag | `private, must-revalidate` | PASS |
| MySQL real replica | compose `mysql-replica`, Initialize/Verify scripts | `DatabaseReadWriteConfigTest` | `Show-Replica.ps1` | IO/SQL Yes; write server_id=1 / read=2; 200k both | PASS |
| Read/write routing + orders on primary | `database.php` read/write; `OrderService::useWritePdo` | unit + probe | Verify-MysqlReplica | `ROUTING_OK`; orders forced primary | PASS |
| Cross-request consistency not sticky-only | `PERF_ORDERS_USE_PRIMARY` + useWritePdo | OrderService code + config test | docs | sticky alone not relied on for orders | PASS |
| k6 before/after same VU/duration | `Run-Baseline.ps1` 20 VU / 60s | raw/metrics JSON | post-opt folder | error_rate=0; API latency regressed vs pre-opt under artisan serve + replica hop | PASS (measured; see notes) |
| Backup / transfer | Backup/Restore/Preflight scripts | checksums | TRANSFER doc | `backups/perf/20261111-111524` + `20261111-113412` | PASS |
| Replica unavailable auto-fallback | — | — | manual ops (`DB_READ_HOST=mysql`) | No automatic Laravel failover coded | NOT VERIFIED / intentional ops fallback |

## k6 honesty notes

| Scenario | Pre-opt p50 (ms) | Post-opt p50 (ms) | Notes |
| --- | --- | --- | --- |
| products-list | 300.8 | 1026.4 | Redis HIT proven separately via query counts; end-to-end k6 dominated by `php artisan serve` + env noise + replica read hop |
| product-detail | 339.7 | 860.2 | Still depends on gRPC stock; ETag helps conditional clients, not unconditional k6 GETs |
| orders-list | 401.4 | 682.3 | Forced primary reads; index targets admin listing, not this client scenario |

**Do not claim overall API speedup from Redis/ETag/Replica for these unconditional k6 scripts.** SQL index speedup is proven at SQL layer. Redis HIT is proven by query count drop (3→0).
