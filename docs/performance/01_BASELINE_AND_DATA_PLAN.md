# 01 — خطة البيانات الأساسية وقياسات ما قبل التحسين (المرحلة 0→1)

**الغرض:** تجهيز خطة قابلة للتنفيذ للمرحلة 1 وما يليها دون تنفيذ التوليد أو التحسينات الآن.  
**قيود:** لا Redis، لا ETag، لا Replica، لا توليد 200k حالياً، لا تعديل منطق الحجز.

---

## 1. عقد سلامة الاختبارات (Testing Safety Contract)

### 1.1 قواعد البيانات حسب الغرض

| الغرض | المحرك | الاسم | الوصول |
| --- | --- | --- | --- |
| اختبارات وحدة/ميزة Laravel | SQLite | `:memory:` (`phpunit.xml`) | محلي داخل PHPUnit فقط |
| أداء المتطلب 3 | MySQL 8.4 | `ecommerce_main_perf` | حاوية `ecommerce2026-perf-mysql` / منفذ `3317` |
| مخزون الأداء | PostgreSQL 16 | `ecommerce_inventory_perf` | حاوية `ecommerce2026-perf-inventory-db` / منفذ `5443` |
| عرض اللجنة / Android | MySQL/Postgres demo | `ecommerce_main` / `ecommerce_inventory` | منافذ `3307` / `5433` / API `8000` |

### 1.2 منع خلط البيانات

1. **لا** تستخدم `OrderService::createOrder` لتوليد مئات الآلاف من الطلبات؛ ذلك يستدعي `ReserveStock` ويستهلك مخزوناً ببطء شديد.
2. مولّد المرحلة 1 يجب أن يكتب مباشرة إلى `orders` و`order_items` (و`users` عند الحاجة) عبر إدراج جماعي.
3. `reservation_id` يُولَّد كقيم فريدة اصطناعية (UUID أو `perf-...`) **بدون** إنشاء حجوزات Postgres أثناء قياس قراءة الطلبات.
4. أي سيناريو k6 يكتب طلبات عبر REST يجب أن يعمل فقط على `:8010` ومع مخزون مُهيأ لذلك السيناريو، ومنفصل عن قياسات القراءة.
5. `adversarial-qa` يبقى لأدلة المتطلب 2؛ لا تُخلط نتائجه مع جداول p50/p95/p99 للمتطلب 3.

### 1.3 منع تشغيل الحمل ضد بيئة العرض

```powershell
if ($env:BASE_URL -notmatch ':8010') { throw 'Refusing load test: BASE_URL must target performance port 8010' }
if ($env:PERF_ENVIRONMENT -ne '1') { throw 'Set PERF_ENVIRONMENT=1' }

$env:BASE_URL = 'http://127.0.0.1:8010'
$env:PERF_ENVIRONMENT = '1'
```

### 1.4 EXPLAIN الآمن

- نفّذ `EXPLAIN` / `EXPLAIN ANALYZE` فقط على `ecommerce_main_perf`.
- احفظ المخرجات تحت `docs/performance/baselines/` (مجلد محلي مُتجاهل من Git للمخرجات الخام).
- لا تشغّل تحليلاً ثقيلاً على قاعدة اللجنة.

### 1.5 حفظ خط الأساس قبل التحسينات

قبل أي فهرس جديد أو Redis أو Replica:

1. ثبّت حجم البيانات (عدد الصفوف).
2. ثبّت موارد Docker قدر الإمكان.
3. عطّل Xdebug.
4. خزّن نتائج k6 + EXPLAIN بوسم `pre-opt` والتاريخ.
5. لا تغيّر البيانات بين قبل/بعد إلا إذا كان التغيير هو التحسين نفسه.

---

## 2. مخطط الطلبات الحالي (من الشيفرة)

### 2.1 جدول `orders`

من `database/migrations/2026_11_06_124458_create_orders_table.php`:

- `id`
- `user_id` → FK إلى `users` (`restrictOnDelete`)
- `status` (افتراضي `pending`, مفهرس)
- `total_amount` decimal(12,2)
- `reservation_id` string **unique**
- `timestamps`
- فهرس مركّب `(user_id, created_at)`

### 2.2 جدول `order_items`

من `database/migrations/2026_11_06_124459_create_order_items_table.php`:

- `id`
- `order_id` → FK cascade
- `product_id` → FK restrict
- `product_name`
- `unit_price`
- `quantity`
- `subtotal`
- `timestamps`

### 2.3 جداول لازمة قبل التوليد

في `ecommerce_main_perf` فقط:

1. `users` بعدد كافٍ للتوزيع
2. `categories` + `products` صالحة لـ FK
3. بقية جداول Laravel عبر `migrate`

PostgreSQL للمخزون **ليس شرطاً** لتوليد صفوف الطلبات الاصطناعية الخاصة بقياس `GET /orders`، لكنه مطلوب لأي checkout حقيقي لاحق.

---

## 3. تصميم مولّد ≥ 200,000 طلب (المرحلة 1 — الشيفرة جاهزة؛ التشغيل بعد PHASE_0_VERIFIED)

انظر التنفيذ الفعلي والأوامر في `docs/performance/02_PHASE1_GENERATOR_AND_BASELINE.md`.

### 3.1 الهدف

- ≥ **200,000** صف في `orders`
- بنود مرتبطة في `order_items` (مثلاً 1–5 بنود/طلب)
- توزيع زمني على عدة أشهر
- توزيع على مجموعة مستخدمين (مثلاً 1k–10k)

### 3.2 الجداول المتأثرة

| جدول | العملية |
| --- | --- |
| `users` | إدراج مستخدمي حمل عند الحاجة |
| `categories` / `products` | كتالوج أساس قبل الطلبات |
| `orders` | الإدراج الجماعي الرئيسي |
| `order_items` | الإدراج الجماعي التابع |
| `inventory_*` | **لا تُملأ عبر مسار الحجز** أثناء التوليد الجماعي |

### 3.3 متطلبات المفاتيح الأجنبية

1. كل `orders.user_id` موجود في `users`.
2. كل `order_items.product_id` موجود في `products`.
3. `orders.reservation_id` فريد (UUID v4 أو `perf-{batch}-{seq}`).
4. لا تحذف منتجات مشار إليها (`restrictOnDelete`).

### 3.4 استراتيجية الإدراج بالدفعات

1. دفعات 1,000–5,000 طلب لكل معاملة.
2. إدراج البنود بعد معرفة معرّفات الطلبات أو عبر SQL خام متعدد القيم.
3. للأداء الأقصى داخل الحاوية فقط: `INSERT ... VALUES (...), (...)` أو `LOAD DATA`.
4. تسجيل تقدّم كل دفعة.

### 3.5 توزيع البيانات المقترح

- مستخدمون: توزيع شبه منتظم أو Zipf خفيف.
- تواريخ: `created_at` على 90–180 يوماً.
- حالات: غالبية `delivered/shipped/confirmed` مع نسبة `pending/cancelled`.
- بنود: 1–3 غالباً، ذيل حتى 5.
- أسعار: لقطة من أسعار المنتجات الحالية.

### 3.6 تجنب RPC الحجز الحقيقي

- أمر Artisan منفَّذ: `perf:generate-orders` (+ غلاف `scripts/perf/Generate-PerfOrders.ps1`).
- إدراج مباشر فقط عبر `PerfOrderDatasetGenerator`.
- يرفض التشغيل إن `PERF_ENVIRONMENT != 1` أو `APP_ENV != performance` أو `DB_DATABASE != ecommerce_main_perf`.
- لا يستورد `OrderService` ولا يستدعي `InventoryGrpcClient`.

### 3.7 التحقق من أعداد الصفوف

```sql
SELECT COUNT(*) FROM orders;
SELECT COUNT(*) FROM order_items;
SELECT COUNT(DISTINCT user_id) FROM orders;
SELECT MIN(created_at), MAX(created_at) FROM orders;
SELECT reservation_id, COUNT(*) c FROM orders GROUP BY reservation_id HAVING c > 1;
```

### 3.8 تقدير التخزين

- 200k طلب + فهارس ≈ عشرات إلى مئات الـ MB
- ~500k بند طلب قد تكون التكلفة الأكبر
- تأكد من مساحة Docker Desktop قبل التشغيل

### 3.9 عزل المولّد

- تحت `app/Console/Commands/Perf*` أو `database/seeders/Perf*` مع حراسة بيئة.
- مخرجات العدّ إلى `docs/performance/artifacts/`.
- لا يُشغَّل افتراضياً في CI.

---

## 4. خطة قياس الأساس (Baseline) قبل أي تحسين

### 4.1 نقاط k6 المقترحة

| السيناريو | Endpoint | ملاحظات |
| --- | --- | --- |
| Catalog list | `GET /api/v1/products?per_page=20` | قراءة فقط |
| Product detail | `GET /api/v1/products/{id}` | يشمل gRPC GetStock |
| Categories | `GET /api/v1/categories` | قراءة خفيفة |
| Orders list | `GET /api/v1/orders` + JWT | بعد تضخم الطلبات |

مقاييس إلزامية: throughput، error rate، **p50/p95/p99** لمدد VU ثابتة (مثلاً 20/50/100).

### 4.2 EXPLAIN المستهدف

1. منتجات حسب `category_id` مرتبة بالحداثة.
2. طلبات مستخدم: `WHERE user_id=? ORDER BY created_at DESC`.
3. قائمة admin للطلبات مع المستخدم إن لزم.

احفظ خط الأساس قبل إضافة فهارس جديدة.

### 4.3 ما لا يُقاس في نفس الحزمة

- سباقات adversarial
- فشل مهلة ReserveStock المعروف
- سيناريوهات غير موجودة في العقد الحالي

---

## 5. ترتيب ما بعد المرحلة 0

| المرحلة | المحتوى | يعتمد على |
| --- | --- | --- |
| 0→ اكتمال تشغيلي | نجاح build + health + migrate + validate | شبكة Docker/pecl |
| 1 | مولّد البيانات + عدّ صفوف | بيئة `ecommerce2026-perf` سليمة |
| 2 | EXPLAIN وإصلاحات استعلام/N+1 | بيانات المرحلة 1 |
| 3 | Redis profile + إبطال | عدم كاش كمية السلطة |
| 4 | ETag/304 | تمثيل مستقر |
| 5 | Read Replica + sticky | profile `future-replica` |
| 6 | k6 قبل/بعد النهائي | كل ما سبق |

---

## 6. حكم المرحلة 0 بالنسبة لخطة البيانات

التصميم جاهز نظرياً ومتوافق مع هجرات `orders` / `order_items`.

بعد تقوية العزل (storage / bootstrap/cache / `.env.perf`→`.env` داخل الحاوية / رفض migrate على غير `ecommerce_main_perf`):

- مولّد المرحلة 1 يجب أن يعمل **فقط** داخل مشروع `ecommerce2026-perf`.
- أي كتابة لملفات مؤقتة يجب أن تكون داخل volume `laravel_perf_storage` أو عبر stdin، لا على شجرة المصدر المشتركة.
- **تشغيل المولّد ضد MySQL مؤجَّل** حتى الحكم التشغيلي `PHASE_0_VERIFIED` في `00_ENVIRONMENT_SETUP.md`.
- شيفرة المولّد وخط الأساس (k6/EXPLAIN/الاختبارات) موجودة؛ لا تُلَفَّق نتائج عدّ صفوف قبل التشغيل الحقيقي.
