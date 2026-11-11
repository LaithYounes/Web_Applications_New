# 04 — وثيقة تسليم هندسية لمساعد ذكاء اصطناعي (Requirement 3)

**الغرض:** تمكين مساعد هندسي **بدون وصول للمستودع** من فهم المشروع الفعلي والمساعدة في تنفيذ المتطلب 3 دون إعادة اكتشاف المعمارية.  
**قيود:** لا تخترع نتائج اختبار أو قياسات. لا تكسر مسارات الحجز/التفويض. الأسرار غير مدرجة هنا.

---

## 1) غرض المشروع ومتطلبات الجامعة

منصة تجارة إلكترونية لمقرر **Web Applications — Academic Year 2026**.

| المتطلب | الموضوع | حالة التنفيذ وفق تدقيق الشيفرة |
| --- | --- | --- |
| 1 | REST + JWT + أدوار + Docker + توثيق | **جزئي**: أساسيات قوية؛ Notifications فارغة؛ لا status history؛ لا sorting parameter؛ Postman بدل Swagger |
| 2 | خدمة مخزون مستقلة gRPC + حجز/تحرير + تزامن | **جوهري**: منفَّذ مع خلل تعويض معروف عند ضياع رد ReserveStock بعد النجاح |
| 3 | بيانات ضخمة + EXPLAIN + N+1 + Replica + Redis + ETag + k6 | **غير منفَّذ** — مهمة المستلم |

---

## 2) المكدس التقني الموثَّق

- **Laravel app:** PHP 8.3 (Docker), Laravel **12.71.0**, JWT `php-open-source-saver/jwt-auth` **2.10.0**, `google/protobuf`, `grpc/grpc`
- **Inventory:** Java **21**, Spring Boot **4.0.9**, Spring gRPC BOM **1.0.3**, JPA, PostgreSQL driver
- **Data stores:** MySQL **8.4** (`ecommerce_main`), PostgreSQL **16** (`ecommerce_inventory`)
- **Orchestration:** `docker-compose.yml` (`ecommerce2026`)
- **Contract:** `proto/inventory.proto`
- **API docs:** Postman collections في جذر المستودع
- **Adversarial tooling:** `adversarial-qa/` (k6 + fault relay) — للصحة/السباقات وليس لتقارير أداء المتطلب 3 بعد

---

## 3) بنية المستودع

```
Web_Applications_New/
  docker-compose.yml
  README.md                          # دليل Android/التشغيل
  Ecommerce*.json / Auth*.json       # Postman
  proto/inventory.proto
  adversarial-qa/{audit.js,fault-relay.cjs,README.md}
  postman/fixtures/
  docs/audit/                        # تقارير التدقيق الحالية
  inventory-service/                 # Spring gRPC server
  main-app/laravel/                  # REST API
```

وحدات Laravel تحت `main-app/laravel/app/Modules/{Users,Products,Orders,Notifications,Inventory}`.

---

## 4) ما نُفِّذ من المتطلبين 1 و2

### متطلب 1 (ملخص)

- Auth: register/login/logout/me + throttle login 5/min + رفض `role` في التسجيل
- Roles: عمود `users.role` (`customer`/`admin`) + middleware `role:admin`
- Products/Categories: قائمة/إنشاء؛ رفع صورة؛ تصفية فئة؛ ترقيم
- Cart + Orders + تحولات حالة الطلب مع قواعد انتقال
- ملكية الطلبات خادمياً (`where user_id`)
- Security headers + أخطاء JSON موحّدة
- Docker Compose للخدمات

**ناقص:** Notifications API، جدول تاريخ الحالات، معامل sorting، OpenAPI/Swagger.

### متطلب 2 (ملخص)

- خدمة Java منفصلة وDB Postgres منفصلة
- RPCs: GetStock, ReserveStock, ReleaseStock, CommitReservation, AddStock, InitializeStock
- تفاصيل المنتج تستدعي GetStock
- Checkout يحجز عبر ReserveStock ثم يكتب الطلب
- نقص المخزون → HTTP 409 دون طلب
- مهلة gRPC → HTTP 503
- تعويض ReleaseStock إذا فشل الحفظ **بعد** معرفة نجاح الحجز
- منع البيع الزائد عبر `PESSIMISTIC_WRITE` + ترتيب أقفال

**خلل معروف:** إذا نجح الحجز في Postgres وانتهت مهلة العميل قبل الرد، لن يُضبط `reservationCreated` ولن يحدث تعويض → حجز يتيم (Postman `08 gRPC Deadline - Known Bug`).

---

## 5) ملفات خلفية مهمة وأغراضها

| مسار | دور |
| --- | --- |
| `routes/api.php` | يحمّل وحدات v1 |
| `bootstrap/app.php` | middleware aliases + رسم الاستثناءات API |
| `app/Http/Middleware/EnsureUserHasRole.php` | تفويض الدور |
| `app/Http/Middleware/SecurityHeaders.php` | رؤوس أمنية |
| `app/Http/ApiErrorResponse.php` | شكل الخطأ |
| `Modules/Users/Services/AuthService.php` | JWT login/register |
| `Modules/Products/Services/ProductService.php` | كتالوج + تهيئة مخزون |
| `Modules/Products/Controllers/ProductController.php` | REST منتجات |
| `Modules/Orders/Services/CartService.php` | سلة |
| `Modules/Orders/Services/OrderService.php` | إنشاء طلب + حالة + تعويض |
| `Modules/Inventory/Services/InventoryGrpcClient.php` | كل استدعاءات gRPC |
| `config/services.php` | `inventory.host` / `timeout` |
| `config/jwt.php` | `ttl` من `JWT_TTL` |
| `config/cache.php` | الافتراضي database؛ redis جاهز كقالب |
| `config/database.php` | mysql افتراضي في Docker عبر env |
| `database/seeders/*` | بيانات عرض |
| `routes/console.php` | `products:retry-initial-stock` |
| `inventory-service/.../InventoryManagementService.java` | منطق المخزون |
| `inventory-service/.../InventoryGrpcService.java` | طبقة gRPC |
| `inventory-service/.../InventoryStockRepository.java` | قفل FOR UPDATE |

---

## 6) الجداول والعلاقات والملكية

### MySQL (Laravel)

- `users` 1—1 `carts`؛ 1—N `orders`
- `categories` 1—N `products`
- `carts` 1—N `cart_items` (unique cart_id+product_id)
- `orders` 1—N `order_items` (لقطة اسم/سعر)
- `orders.reservation_id` unique يربط حجز المخزون
- لا جدول تاريخ حالات

### PostgreSQL (Inventory)

- `inventory_stock(product_id unique, total_quantity, reserved_quantity)`
- `inventory_reservations(reservation_id, product_id, quantity, status)`
- المتاح = total − reserved

**ملكية البيانات:** الكتالوج والطلبات والسلة في MySQL؛ الكميات والحجوزات في Postgres فقط.

---

## 7) تدفق إنشاء الطلب وحجز المخزون (يجب عدم كسره)

ملف: `OrderService::createOrder`

1. `DB::transaction` + `lockForUpdate` على سلة المستخدم وبنودها
2. بناء قائمة `{product_id, quantity}` وحساب الأسعار بالسنتات
3. `inventoryClient->reserveStock($uuid, $items)` **داخل** المعاملة
4. عند النجاح: `reservationCreated=true` ثم `Order::create` + `items` + تفريغ السلة
5. عند استثناء بعد نجاح معروف: `releaseStock` ثم إعادة رمي الخطأ
6. نقص المخزون: `InventoryRejectedException` → 409؛ rollback MySQL

`updateStatus`:

- آلة حالات: pending→confirmed|cancelled؛ confirmed→processing|cancelled؛ processing→shipped|cancelled؛ shipped→delivered
- `shipped` → `commitReservation`
- `cancelled` → `releaseStock`
- تحديث صف الطلب فقط بعد نجاح عملية المخزون عند الحاجة

---

## 8) المصادقة والتفويض

- Guard `api` = JWT
- Middleware المسارات: `auth:api`؛ للإدارة `role:admin`
- عميل يرى طلباته فقط؛ admin يرى الكل ويغيّر الحالة
- محاولة عميل لطلب غيره → 404 من `findOrFail` بعد تصفية user_id
- كلمات المرور hashed عبر cast

---

## 9) جرد endpoints المختصر

الأساس: `http://localhost:8000/api/v1`

**عامة:**  
`GET /health`  
`POST /auth/register` `POST /auth/login`  
`GET /categories` `GET /products` `GET /products/{id}`

**JWT:**  
`POST /auth/logout` `GET /auth/me`  
`GET|POST|PUT|DELETE /cart...`  
`POST /orders` `GET /orders` `GET /orders/{id}`

**Admin JWT:**  
`POST /categories` `POST /products` (multipart + optional `Idempotency-Key`)  
`PATCH /orders/{id}/status`

تفاصيل المنتج فقط تضيف `available_quantity`. القائمة لا تستدعي المخزون.

---

## 10) خدمات Docker وأسماء متغيرات البيئة

خدمات: `mysql`, `inventory-db`, `app`, `inventory-service`  
شبكة: `ecommerce_network`  
منافذ مضيف: `8000` (API), `9090` (gRPC), `3307` (mysql), `5433` (postgres)

أسماء متغيرات مهمة (بدون قيم أسرار):

| متغير | الغرض |
| --- | --- |
| `APP_KEY` | تشفير Laravel |
| `APP_URL` | بناء روابط الصور |
| `JWT_SECRET` / `JWT_TTL` | توقيع وانتهاء الرمز |
| `DB_CONNECTION/HOST/PORT/DATABASE/USERNAME/PASSWORD` | MySQL (Compose يضبطها لـ app) |
| `INVENTORY_GRPC_HOST` | افتراضي `inventory-service:9090` |
| `INVENTORY_GRPC_TIMEOUT` | ميكروثانية؛ افتراضي 3000000 |
| `CACHE_STORE` | حالياً database في المثال |
| `REDIS_HOST/PORT/...` | قوالب غير موصولة بـ Compose |
| `DB_URL` / `DB_USERNAME` / `DB_PASSWORD` | لخدمة Java |

ملاحظة: `.env.example` الحالي لا يوثّق مفاتيح Inventory/JWT صراحة؛ README الجذري يشرح التوليد.

---

## 11) الاختبارات ونتائج التنفيذ في التدقيق

| مجموعة | ماذا تغطي | نُفِّذت في التدقيق؟ | نتيجة |
| --- | --- | --- | --- |
| `tests/Feature/AuthRegistrationTest.php` | تسجيل/دور | لا | — |
| `tests/Feature/OrderAuthorizationTest.php` | ملكية/أدوار/headers | لا | — |
| `tests/Feature/ProductApiTest.php` | منتجات + mock inventory | لا | — |
| `tests/Feature/UnifiedErrorResponseTest.php` | أخطاء و429 | لا | — |
| Java `InventoryServiceApplicationTests` | contextLoads | لا | — |
| Postman / adversarial-qa | سباقات/أعطال | لا | موثَّقة فقط |

سبب عدم التنفيذ: لا `vendor/` محلياً؛ `docker compose ps` فارغ؛ لا ملف `.env`.

تشغيل لاحق آمن مقترح:

```powershell
docker compose up -d --wait
docker compose exec -T app php artisan test
```

---

## 12) أخطاء مخاطر ومعمارية ونواقص

1. حجز يتيم عند timeout بعد نجاح ReserveStock (Known Bug)
2. gRPC داخل معاملة MySQL مع أقفال سلة
3. فشل `releaseStock` في التعويض يُسجَّل critical فقط بلا تلقائي reconcile
4. السلة لا تتحقق من المخزون قبل الإضافة
5. Notifications غير منفَّذة
6. لا status history
7. لا sorting API
8. لا idempotency على `POST /orders`
9. `php artisan serve` كخادم تطوير قد يحدّ قياسات الحمل
10. اختبارات المخزون الحقيقية شبه غائبة في PHPUnit (mocks)

---

## 13) البنية الحالية المتعلقة بالأداء

- كاش افتراضي: جداول MySQL `cache`
- لا Redis container
- لا read replica
- لا ETag/304
- لا مجموعة بيانات ضخمة
- لا سكربتات EXPLAIN/k6 أداء محفوظة للمتطلب 3
- Eager loading موجود في المسارات الرئيسية (حماية نسبية من N+1)

---

## 14) اعتماديات قبل تنفيذ المتطلب 3

1. بيئة Docker شغّالة وmigrations مطبّقة
2. قرار عزل قاعدة بيانات الأداء عن بيانات العرض
3. عدم كسر عقد proto إلا بإضافة متوافقة
4. الإبقاء على فصل سلطة المخزون خارج كاش القراءة
5. تثبيت أدوات القياس: k6، إمكانية `EXPLAIN` على MySQL
6. إن لزم Redis: إضافة خدمة Compose + امتداد/عميل PHP مناسب في صورة app

---

## 15) استراتيجية تقنية مقترحة للمتطلب 3

1. **عزل بيئة أداء** (compose override أو قواعد منفصلة)
2. **توليد بيانات ضخم** بإدراج جماعي للطلبات **بدون** مسار checkout/gRPC؛ وثّق ذلك
3. **قياس خط أساس** k6 على `GET /products`, `GET /products/{id}`, `GET /orders`
4. **EXPLAIN + فهارس + إزالة أي N+1 جديد**
5. **Redis** لقوائم المنتجات/الفئات (بدون كمية سلطة)
6. **ETag** على تمثيل منتج مستقر؛ 304 عند `If-None-Match`
7. **MySQL replica** + قراءة عامة من replica؛ **قراءات المستخدم بعد الكتابة من primary/sticky**
8. **إعادة قياس** وتوثيق p50/p95/p99 وظهور التحديثات تحت الحمل
9. أعد تشغيل اختبارات التفويض وسيناريو حجز أخير قطعة للتأكد من عدم التراجع

تفاصيل أوسع: `docs/audit/03_REQUIREMENT_3_READINESS.md`

---

## 16) مواقع تعديل متوقعة أثناء المتطلب 3

| هدف | مواقع |
| --- | --- |
| كاش قوائم | `ProductService`, `CategoryService`, ربما Controllers |
| ETag | `ProductController`, `ProductResource`, middleware جديد |
| Replica | `docker-compose.yml`, `config/database.php`, توجيه قراءات Orders/Cart |
| Redis | Compose + `.env` + `config/cache.php` |
| بيانات ضخمة | Seeder/Command جديد تحت `database/` أو `app/Console` |
| فهارس | migrations جديدة |
| k6 | مجلد جديد مثل `perf/k6/` |
| **تجنّب** | تغيير خوارزمية الحجز في Java إلا لسبب عقدي؛ كاش `available_quantity` السلطوي |

---

## 17) أوامر تطوير واختبار قابلة لإعادة الإنتاج (PowerShell)

من جذر المستودع:

```powershell
# إعداد أولي (مرة)
$envFile = 'main-app/laravel/.env'
if (-not (Test-Path $envFile)) { Copy-Item 'main-app/laravel/.env.example' $envFile }
docker compose build
docker compose run --rm --no-deps -T app php artisan key:generate
docker compose run --rm --no-deps -T app php artisan jwt:secret --always-no
docker compose up -d --wait
docker compose exec -T app php artisan migrate --force
docker compose exec -T -e INVENTORY_GRPC_TIMEOUT=10000000 app php artisan db:seed

# صحة
Invoke-RestMethod 'http://localhost:8000/api/v1/health'

# اختبارات Laravel داخل الحاوية
docker compose exec -T app php artisan test

# إيقاف دون حذف أحجام
docker compose stop
```

حسابات عرض (كلمة المرور موثَّقة في README العام للمشروع): admin `emily.carter@example.test`؛ عميل checkout `noah.reed@example.test`.

---

## 18) قرارات مُتخذة مسبقاً وقرارات مفتوحة

### مُتخذة في النظام الحالي

- المخزون خدمة Java + Postgres
- الحجز عند إنشاء الطلب؛ الخصم النهائي عند shipped
- JWT وليس Sanctum/Passport
- كاش database افتراضياً
- Postman كتوثيق API أساسي

### مفتوحة قبل/أثناء المتطلب 3

- هل تُضاف خدمة Redis إلى نفس Compose العرض أم ملف override للأداء فقط؟
- هل تُضمَّن الكمية في تمثيل ETag أم تُفصل؟
- كيف يُولَّد مئات آلاف الطلبات دون إفساد حجوزات العرض؟
- هل يُصلح خلل مهلة الحجز ضمن نطاق المتطلب 3 أم يُترك كدين تقني؟
- هل يُستبدل `artisan serve` بخادم أقوى لقياس عادل؟

---

## 19) أسئلة تحتاج تأكيداً قبل التنفيذ

1. هل اللجنة تشترط OpenAPI حرفياً أم Postman كافٍ للمتطلب 1؟
2. هل يجب إكمال Notifications وstatus history قبل أو بالتوازي مع المتطلب 3؟
3. ما الحجم الأدنى المقبول للبيانات («مئات الآلاف») وهل يشمل order_items؟
4. هل يُسمح بـ compose منفصل للأداء؟
5. هل يوجد قيد على تعديل Dockerfile لإضافة امتداد Redis؟
6. هل يُقبل عدم كاش كمية المخزون صراحة في التقرير النهائي؟
7. هل يجب إصلاح Known Bug #08 قبل التسليم؟

---

## 20) ملخص حالة المشروع النهائية

المشروع منصة REST Laravel متكاملة مع خدمة مخزون Java/gRPC حقيقية، وتفويض JWT عملي، ومجموعات Postman/adversarial قوية لإثبات المتطلب 2. المتطلب 1 شبه مكتمل وظيفياً مع فجوات وحدات/تاريخ/فرز. المتطلب 2 قوي مع فجوة تعويض مهلة موثَّقة. المتطلب 3 لم يبدأ؛ أفضل مسار هو عزل بيانات الأداء ثم قياس ثم Redis/ETag/Replica دون المساس بذرية الحجز.

**وثائق مرافقة:**

- `docs/audit/00_PROJECT_ARCHITECTURE.md`
- `docs/audit/01_REQUIREMENT_1_AUDIT.md`
- `docs/audit/02_REQUIREMENT_2_AUDIT.md`
- `docs/audit/03_REQUIREMENT_3_READINESS.md`
