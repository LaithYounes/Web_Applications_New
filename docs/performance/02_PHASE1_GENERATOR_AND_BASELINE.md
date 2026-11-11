# 02 — المرحلة 1: مولّد البيانات وخط أساس القياس (شيفرة جاهزة)

**الغرض:** أول معلم تنفيذي للمتطلب 3 على مستوى الشيفرة: مولّد ≥200k طلب، اختبارات سلامة، سيناريوهات k6 أساس، وحزمة EXPLAIN — **بدون** Redis / Replica / ETag.  
**قيود تشغيل حالية:** لا تشغّل التوليد أو k6 أو EXPLAIN الحي حتى تصبح بيئة `ecommerce2026-perf` تشغيلية (`PHASE_0_VERIFIED`).

---

## 1. ما تم تنفيذه في المستودع

| مكوّن | المسار |
| --- | --- |
| حارس البيئة | `main-app/laravel/app/Support/Performance/PerfEnvironmentGuard.php` |
| مولّد الدفعات | `main-app/laravel/app/Support/Performance/PerfOrderDatasetGenerator.php` |
| أمر Artisan | `php artisan perf:generate-orders` |
| غلاف PowerShell | `scripts/perf/Generate-PerfOrders.ps1` |
| k6 أساس | `scripts/perf/k6/baseline-*.js` + `Run-Baseline.ps1` |
| EXPLAIN | `scripts/perf/explain/queries.sql` + `Run-ExplainBaseline.ps1` |
| تحليل مخاطر ثابت | `docs/performance/query_risks_baseline.md` |
| اختبارات | `tests/Unit/Performance/*`, `tests/Feature/Performance/*` |

---

## 2. ضمانات السلامة

1. يرفض المولّد التشغيل ما لم يكن `PERF_ENVIRONMENT=1` و`APP_ENV=performance` و`DB_DATABASE=ecommerce_main_perf`.
2. يرفض قواعد demo (`ecommerce_main`) والقيم الفارغة/غير المعرّفة و`:memory:`.
3. إدراج لاحق فقط (append-only): لا `truncate` / لا `delete` / لا تعديل صفوف موجودة.
4. لا يستدعي `OrderService::createOrder` ولا `InventoryGrpcClient` — لا حجوزات Postgres أثناء التوليد.
5. `reservation_id` بالشكل `perf-r{orders_before}-b{batch}-i{seq}` لضمان التفرّد عبر إعادة التشغيل.
6. سكربتات k6 ترفض أي `BASE_URL` لا يستهدف المنفذ `8010`.

---

## 3. أوامر ما بعد جاهزية Docker

من جذر المستودع (PowerShell):

### 3.1 إكمال تهيئة الأداء (إن لم تكتمل)

```powershell
cd "C:\Users\asasv\Desktop\Cursor projects\Web_Applications_New"
powershell -ExecutionPolicy Bypass -File .\scripts\perf\Initialize-PerfEnvironment.ps1
powershell -ExecutionPolicy Bypass -File .\scripts\perf\Validate-PerfEnvironment.ps1
```

### 3.2 توليد البيانات (هدف ≈ 200,000 طلب)

تجفيف أولاً:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\perf\Generate-PerfOrders.ps1 -Count 200000 -Chunk 2000 -Users 5000 -DryRun
```

التوليد الفعلي (قابل للاستئناف عبر الهدف الكلي):

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\perf\Generate-PerfOrders.ps1 -TargetTotal 200000 -Chunk 2000 -Users 5000
```

أو مباشرة:

```powershell
docker compose -p ecommerce2026-perf -f docker-compose.perf.yml exec -T app `
  php artisan perf:generate-orders --target-total=200000 --chunk=2000 --users=5000
```

تحقق سريع داخل MySQL الأداء:

```powershell
docker exec ecommerce2026-perf-mysql mysql -uecommerce_perf_user -pecommerce_perf_password ecommerce_main_perf -e "SELECT COUNT(*) AS orders FROM orders; SELECT COUNT(*) AS items FROM order_items; SELECT COUNT(DISTINCT reservation_id)=COUNT(*) AS reservation_unique FROM orders;"
```

### 3.3 خط أساس k6

```powershell
$env:BASE_URL = 'http://127.0.0.1:8010'
$env:PERF_ENVIRONMENT = '1'
# بعد تسجيل دخول عميل perf (مثلاً perf.user.00001@example.test / PerfLoad2026!):
# $env:PERF_JWT = '<token>'
powershell -ExecutionPolicy Bypass -File .\scripts\perf\k6\Run-Baseline.ps1 -Scenario all -Vus 20 -Duration 60s
```

### 3.4 EXPLAIN الأساس

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\perf\explain\Run-ExplainBaseline.ps1
```

المخرجات الخام تُحفظ تحت `docs/performance/baselines/` (متجاهلة من Git).

---

## 4. اختبارات خفيفة (بدون Docker)

```powershell
cd "C:\Users\asasv\Desktop\Cursor projects\Web_Applications_New\main-app\laravel"
composer install --no-interaction
php artisan test --filter=Performance
```

---

## 5. ما تبقّى من المتطلب 3

| المرحلة | المحتوى |
| --- | --- |
| 0 تشغيلي | نجاح build/health/migrate/validate لـ `ecommerce2026-perf` |
| 1 تشغيل | تنفيذ المولّد فعلياً على MySQL perf وعدّ الصفوف |
| 2 | EXPLAIN حي + إصلاحات استعلام/فهارس موثَّقة قبل/بعد |
| 3 | Redis + إبطال (بدون كاش كمية السلطة) |
| 4 | ETag/304 |
| 5 | Read Replica + sticky لقراءات المستخدم |
| 6 | k6 قبل/بعد النهائي للجولة |

**لم تُدرَج هنا أي نتائج معيارية ملفّقة، ولم يُدَّعَ إدراج 200,000 طلب بعد.**
