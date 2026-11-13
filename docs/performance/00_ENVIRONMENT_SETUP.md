# 00 — إعداد بيئة الأداء المعزولة (المرحلة 0) — مراجعة وتقوية

**المشروع:** Web Applications 2026 — Requirement 3  
**المرحلة:** 0 (تحضير + تقوية عزل)  
**الفرع:** `main`  
**قيود:** بدون Redis فعّال، بدون Replica فعّال، بدون توليد 200k، بدون commit/push، بدون `down -v` / prune.

---

## 1. المشاكل المكتشفة قبل التقوية (من المراجعة)

| # | المشكلة | الشدة | السبب الجذري (موثَّق) |
| --- | --- | --- | --- |
| 1 | مشاركة `storage/` و`bootstrap/cache/` مع مسار المصدر | عالية | bind mount كامل لـ `./main-app/laravel` مع عزل `vendor` فقط |
| 2 | احتمال تحميل `.env` التجريبي داخل Laravel | عالية | Laravel يحمّل `.env` افتراضياً؛ `APP_ENV=performance` لا يختار `.env.perf` تلقائياً |
| 3 | `.dockerignore` لا يستبعد `.env.*` / الأسرار | متوسطة | كان يستبعد `.env` فقط |
| 4 | فحص gRPC يكتب ملفاً مؤقتاً داخل شجرة التطبيق المشتركة | متوسطة | `Validate-PerfEnvironment.ps1` أنشأ `storage/app/perf_grpc_probe.php` |
| 5 | migrate بلا إثبات اتصال فعّال قبل التنفيذ | عالية | الاعتماد على `printenv` فقط |
| 6 | فشل البناء/السحب السابق | حرج تشغيلي | أخطاء شبكة Docker Hub / PECL (`short read`, `unable to unpack`) — عابرة وليست خطأ Dockerfile demo |

---

## 2. الإصلاحات المنفَّذة

### 2.1 عزل نظام الملفات

في `docker-compose.perf.yml` لخدمة `app`:

- الإبقاء على bind mount للكود المصدري.
- Volumes أداء مخصّصة:
  - `laravel_perf_storage` → `/var/www/storage`
  - `laravel_perf_bootstrap_cache` → `/var/www/bootstrap/cache`
  - `laravel_perf_vendor` → `/var/www/vendor`
- أمر تشغيل يضمن إنشاء شجرة `storage`/`bootstrap/cache` داخل الـ volumes المعزولة.
- رابط `public/storage` نسبي (`../storage/app/public`) ويُحلّ داخل كل بيئة إلى حجم التخزين الخاص بها.

### 2.2 عزل الإعداد

- تركيب: `./main-app/laravel/.env.perf:/var/www/.env:ro`
- داخل الحاوية يرى Laravel ملف `.env` = إعدادات الأداء فقط؛ ملف demo على المضيف **لا يُمس**.
- متغيرات Compose تفرض `DB_DATABASE=ecommerce_main_perf` و`APP_ENV=performance` (تعطيل seeders التجريبية التي تسمح فقط بـ `local|testing`).
- سكربت التهيئة يولّد `APP_KEY`/`JWT_SECRET` عبر `php -r` ويكتبها إلى `.env.perf` فقط، مع التحقق أن `.env` التجريبي لم يتغيّر (hash).

### 2.3 حماية سياق البناء

- تحديث `main-app/laravel/.dockerignore` لاستبعاد `.env` و`.env.*` والمفاتيح والكاش.
- إضافة `.dockerignore` جذري لسياق بناء `inventory-service` يستبعد الأسرار والمجلدات غير اللازمة.
- `Dockerfile.perf` (مسار أداء فقط) مع إعادة محاولة محدودة لـ `pecl install grpc`؛ **Dockerfile التجريبي لم يُعدَّل**.

### 2.4 سلامة التهيئة والتحقق

- `Initialize-PerfEnvironment.ps1`: رفض `COMPOSE_PROFILES`، رفض ports/volumes demo في `compose config`، إعادة محاولة بناء محدودة، إثبات الاسم الفعّال لقاعدة البيانات عبر Laravel قبل `migrate`.
- `Validate-PerfEnvironment.ps1`: لا يكتب ملفات على المصدر؛ يستخدم `php` عبر stdin؛ يميّز فشل النقل عن كمية صفر؛ يفحص mounts volumes وDB الفعّالة وHTTP وgRPC.

---

## 3. معمارية العزل

| البيئة | Compose | منافذ | DB | Volumes |
| --- | --- | --- | --- | --- |
| Demo | `docker-compose.yml` / `ecommerce2026` | 8000,3307,5433,9090 | `ecommerce_main` / `ecommerce_inventory` | `ecommerce2026_*` |
| Perf | `docker-compose.perf.yml` / `ecommerce2026-perf` | 8010,3317,5443,9100 | `*_perf` | `ecommerce2026_perf_*` فقط |

---

## 4. الملفات المعدَّلة / الجديدة

| ملف | الحالة |
| --- | --- |
| `docker-compose.perf.yml` | معدَّل (عزل storage/cache + mount `.env`) |
| `main-app/laravel/Dockerfile.perf` | جديد |
| `main-app/laravel/.dockerignore` | معدَّل |
| `.dockerignore` (جذر) | جديد |
| `main-app/laravel/.env.perf.example` | معدَّل (تعليق عزل) |
| `scripts/perf/Initialize-PerfEnvironment.ps1` | معدَّل |
| `scripts/perf/Validate-PerfEnvironment.ps1` | معدَّل |
| `docs/performance/00_ENVIRONMENT_SETUP.md` | محدَّث |
| `docs/performance/01_BASELINE_AND_DATA_PLAN.md` | محدَّث |
| `.gitignore` | يحتفظ باستثناءات baselines/artifacts و`.env.perf.example` |

**لم تُعدَّل:** منطق الأعمال، Java، `proto/`، `docs/audit/`، `main-app/laravel/Dockerfile` التجريبي، `docker-compose.yml` التجريبي.

---

## 5. أوامر التشغيل

```powershell
cd "C:\Users\asasv\Desktop\Cursor projects\Web_Applications_New"
powershell -ExecutionPolicy Bypass -File .\scripts\perf\Initialize-PerfEnvironment.ps1
powershell -ExecutionPolicy Bypass -File .\scripts\perf\Validate-PerfEnvironment.ps1
```

إيقاف آمن:

```powershell
docker compose -p ecommerce2026-perf -f docker-compose.perf.yml stop
# أو down بدون -v
docker compose -p ecommerce2026-perf -f docker-compose.perf.yml down
```

---

## 6. نتائج التحقق (تُحدَّث بالتشغيل الحقيقي)

### فحوصات ثابتة (نجحت)

| فحص | النتيجة |
| --- | --- |
| `compose config` لـ perf | ناجح |
| منافذ 8010/3317/5443/9100 | موجودة في config |
| volumes `ecommerce2026_perf_*` بما فيها storage/bootstrap_cache | موجودة |
| عدم ظهور volumes/ports demo في config | مؤكد |
| وجود `Dockerfile.perf` وmount `.env.perf` | مؤكد |

### فحوصات تشغيلية (آخر محاولة)

| فحص | النتيجة | دليل |
| --- | --- | --- |
| `docker pull hello-world` | ناجح | صورة صغيرة وصلت |
| `docker pull mysql:8.4` (محاولات متعددة) | **فشل** | `short read ... unexpected EOF`؛ سحب معلّق ثم انقطاع |
| وجود صور mysql/postgres/php/maven محلياً | غير موجودة | `docker images` |
| حاويات/volumes `ecommerce2026-perf` | غير منشأة | `compose ps` / `volume ls` فارغان |
| Initialize + Validate كاملان | **لم يُنفَّذا** | معتمدان على نجاح السحب/البناء |

**تحديث تشغيلي لاحق:** بعد توفر صور MySQL/Postgres محلياً واكتمال بناء `ecommerce2026-perf-app` (ترجمة pecl grpc)، نجح:

- `Initialize-PerfEnvironment.ps1 -SkipBuild` → migrate على `ecommerce_main_perf`
- `Validate-PerfEnvironment.ps1` → **كل الفحوصات PASS** (HTTP 8010، gRPC GetStock، volumes/mounts، DB الفعّالة)

**الحكم:** `PHASE_0_VERIFIED`

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\perf\Initialize-PerfEnvironment.ps1
powershell -ExecutionPolicy Bypass -File .\scripts\perf\Validate-PerfEnvironment.ps1
```

---

## 7. ضمانات السلامة

1. لا مشاركة volumes قواعد بيانات مع demo.
2. storage وbootstrap/cache وvendor للأداء منفصلة.
3. `.env` داخل حاوية الأداء = `.env.perf` للقراءة فقط.
4. لا أوامر تدميرية في السكربتات.
5. migrate مرفوض إن لم يكن الاسم الفعّال `ecommerce_main_perf`.
6. GetStock لمنتج غائب يعيد `0` (موثَّق في Java) ولا يُعد فشل شبكة.

---

## 8. جاهزية المرحلة 1

ممنوعة حتى: `PHASE_0_VERIFIED` عبر Validate الكامل.  
خطة البيانات تبقى في `01_BASELINE_AND_DATA_PLAN.md` دون تنفيذ التوليد.
