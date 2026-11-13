# تدقيق الإصدار النهائي — Web Applications 2026

تاريخ التدقيق: 2026-11-11  
الجهاز: حاسوب التطوير الحالي فقط. **لم يُختبر النقل على حاسوب ثانٍ.**  
لم يُنفَّذ commit أو push.

**الحكم: BLOCKED_WITH_EXACT_REASONS**

أسباب الحجب المباشرة:

1. شيفرة المتطلب 3 والسكربتات والوثائق **غير مُلتزَمة**. `origin/main` عند `cb0dbb95cd7f85ad033b05704033c79e4b007730` لا يحتويها. استنساخ GitHub الحالي لا يُعيد العرض.
2. نسخة `MANIFEST.json` الأخيرة تسجّل نفس الـcommit القديم، لذلك «checkout حسب الـmanifest» لا يطابق الشيفرة المطلوبة.
3. فجوات المتطلب 1 ما زالت في الشيفرة: الإشعارات، تاريخ حالات الطلب، معامل الفرز.
4. فجوة تعويض معروفة في المتطلب 2 إذا نجح `ReserveStock` في PostgreSQL ثم ضاع الرد قبل أن يضبط Laravel `reservationCreated`.
5. بيئة Docker الأصلية `ecommerce2026` **غير موجودة** على هذا الجهاز (لا حاويات ولا volumes)، فلم يُتحقق من تشغيلها في هذه الجولة.

---

## 1) المتطلب 1

**نسبة الإكمال المحسوبة:** 10 من 15 معيارًا PASS = **67%**.  
معيار واحد جزئي (توثيق Postman دون OpenAPI) لم يُحسب ضمن الـ67%. معيار Docker موثَّق بالمصدر فقط وتشغيله هنا NOT VERIFIED.

| المعيار | التنفيذ | اختبار آلي | تحقق تشغيلي هذه الجولة | الحالة |
| --- | --- | --- | --- | --- |
| وحدات Users / Products / Orders | `routes/api.php` والوحدات الثلاث | Feature tests | جزئي على منفذ الأداء 8010 | PASS |
| Notifications | `Modules/Notifications/Routes/api.php` فارغ | لا | لا | FAIL |
| REST منتجات/طلبات/سلة | المتحكمات والخدمات | `ProductApiTest`, `OrderAuthorizationTest` | `GET /products` = 200 | PASS |
| رفع صور | `ProductService::create` | `ProductApiTest` | لم يُرفع ملف حيًا | PASS (اختبار) |
| تصفية `category_id` | `ProductController::index` | `ProductApiTest` | لم يُعاد فلتر حي | PASS (اختبار) |
| ترقيم صفحات | `paginate` | `ProductApiTest` | لم يُفحص شكل الصفحة حيًا | PASS (اختبار) |
| معامل فرز `sort` | ترتيب ثابت `latest` ثم `id` فقط | لا | لا | FAIL |
| تحولات حالة الطلب | `OrderService::updateStatus` | `OrderAuthorizationTest` (انتقال إداري) | لم يُنفَّذ checkout حي | PASS (اختبار/مصدر) |
| تاريخ الحالات | لا جدول `order_status_histories` | لا | لا | FAIL |
| JWT قابل للضبط | `config/jwt.php` + `JWT_TTL` | التسجيل يصدر bearer | موجود في `.env.perf` داخل الحاوية؛ ملف `.env` المحلي كان بلا `JWT_SECRET` | PASS |
| أدوار وملكية | `role:admin` و`where user_id` | `OrderAuthorizationTest` كامل | لم يُعاد على Docker الأصلي | PASS (اختبار) |
| حد تسجيل الدخول | `throttle:5,1` | `UnifiedErrorResponseTest` | لم يُضرب الحد على 8010 | PASS (اختبار) |
| ترويسات أمان وشكل الأخطاء | middleware + `bootstrap/app.php` | `UnifiedErrorResponseTest` | غير مفحوص ترويسةً على 8010 | PASS (اختبار) |
| Docker للتطبيق الأصلي | `docker-compose.yml` | لا | لا حاويات `ecommerce2026` غير perf | NOT VERIFIED |
| توثيق API | مجموعات Postman في الجذر؛ لا OpenAPI | لا | لم تُشغَّل Postman | جزئي |

إضافة هذه الجولة حتى تنجح الاختبارات بعد استنساخ نظيف: `phpunit.xml` يضبط `JWT_SECRET` لقيمة اختبار فقط، و`.env.example` يوثّق `JWT_SECRET` و`JWT_TTL` فارغين.

## 2) المتطلب 2

**نسبة الإكمال بالمصدر والاختبارات المتاحة:** 9 من 11 معيارًا متحققة في الشيفرة = **82%**.  
لا تُحتسب كإثبات تشغيلي كامل لمسار الشراء أو سباق آخر قطعة؛ هذان لم يُعادا في هذه الجولة حتى لا تُمس البيانات.

| المعيار | الدليل | اختبار | تشغيل هذه الجولة | الحالة |
| --- | --- | --- | --- | --- |
| خدمة Java مستقلة | `inventory-service/` | `contextLoads` لم يُشغَّل هنا | الحاوية `ecommerce2026-perf-inventory-service` تعمل | PASS |
| gRPC | `InventoryGrpcClient` + `InventoryGrpcService` | محاكاة في اختبارات Laravel | `GET /api/v1/products/1` = 200 (يستدعي المخزون) | PASS |
| قاعدة PostgreSQL مستقلة | `inventory-db` | — | `ecommerce2026-perf-inventory-db` healthy على 5443 | PASS |
| عقد الحجز/التحرير/الالتزام | `proto/inventory.proto` | — | لم يُعد فحص proto ثنائيًا | PASS (مصدر) |
| حجز متعدد البنود ذري | `InventoryManagementService.reserveStock` + قفل `PESSIMISTIC_WRITE` | لا اختبار Java لهذه الجولة | لم يُنشأ طلب حي | PASS (مصدر) / NOT VERIFIED تشغيليًا |
| رفض نقص المخزون دون طلب | استثناء Java + 409 في Laravel | `UnifiedErrorResponseTest` بالمحاكاة | لم يُنفَّذ مخزون حقيقي | PASS (اختبار محاكى) |
| مهلة وعدم التوفر → 503 | `InventoryServiceException` | الاختبار الموحّد + `ProductEtagTest` | لم يُوقف المخزون في هذه الجولة | PASS (اختبار) |
| تعويض بعد فشل حفظ الطلب | `OrderService` يستدعي `releaseStock` فقط إذا `reservationCreated=true` | Postman 07 موثَّق سابقًا ولم يُعد | لم يُعاد | جزئي |
| ضياع رد الحجز بعد نجاحه في PostgreSQL | `reservationCreated` يُضبط بعد عودة RPC | Postman `08 gRPC Deadline - Known Bug` | لم يُعاد | FAIL معروف، غير مُصلح |
| التزام عند الشحن وتحرير عند الإلغاء | `updateStatus` | لا اختبار تكامل gRPC حقيقي | لم يُنفَّذ | PASS (مصدر) / NOT VERIFIED تشغيليًا |
| اتساق القراءتين MySQL وPostgreSQL | لا 2PC؛ التعويض هو الآلية | — | لم يُقارَن صف حجز بصف طلب | NOT VERIFIED تشغيليًا |

منطق المتطلب 3 لم يغيّر بروتوكول الحجز. `OrderService::createOrder` ما زال كما هو. القراءات الخاصة بالطلبات تستخدم `useWritePdo` عند `PERF_ORDERS_USE_PRIMARY=1` حتى لا تُقرأ من replica متأخرة.

## 3) المتطلب 3

**نسبة صفوف مصفوفة التغطية التي حالتها PASS:** 15 من 16 في `docs/performance/05_REQUIREMENT_3_COVERAGE_MATRIX.md` = **94%**.  
الصف الناقص: لا يوجد تحويل تلقائي إذا سقطت الـreplica؛ الإجراء اليدوي `DB_READ_HOST=mysql`.

أُعيد في هذه الجولة، دون إعادة توليد البيانات:

- `GET /api/v1/health` و`/products` و`/products/1` على `127.0.0.1:8010` → 200.
- الحاويات الست للأداء قائمة، بما فيها Redis والـreplica.
- الفهرس `orders_created_at_index` موجود، وصف migrations المطابق = 1.
- الطلبات في النسخة الجديدة: **200000** طلب و**429410** بندًا.
- اختبارات PHPUnit الكاملة: **55 passed (299 assertions)**.

لم يُعاد تشغيل k6 في هذه الجولة. الأرقام السابقة ما زالت في `docs/performance/baselines/pre-opt/` و`post-opt/` ولم تُستبدل. زمن HTTP في post-opt أبطأ من pre-opt؛ تحسين SQL وRedis HIT مثبتان في تقاريرهما المنفصلة وليس كتسريع شامل لـk6.

## 4) الاختبارات التي نُفذت

| المجموعة | النتيجة |
| --- | --- |
| التشغيل الأول `php artisan test` | 14 failed / 41 passed. سبب الفشل: `JWT_SECRET` غير موجود في `.env` المحلي، والاختبارات التي لا تضبط السر بنفسها رمت `SecretMissingException` |
| بعد ضبط سر اختبار داخل `phpunit.xml` فقط | `OrderAuthorizationTest` + `ProductApiTest` + `UnifiedErrorResponseTest` + `AuthRegistrationTest`: **26 passed** |
| `php artisan test` الكامل بعد الإصلاح | **55 passed (299 assertions)** |
| Java `mvn test` | لم يُشغَّل |
| Postman / adversarial-qa | لم يُشغَّل |
| k6 | لم يُعد؛ الملفات السابقة محفوظة |
| بيئة Docker الأصلية | غير موجودة على الجهاز |

## 5) أعطال حرجة وغير محسومة

| العطل | الملفات | الإصلاح المقترح | أُصلح الآن؟ |
| --- | --- | --- | --- |
| الاستنساخ لا يحتوي المتطلب 3 | كل الملفات غير المتتبعة تحت `scripts/` و`docs/` و`docker-compose.perf.yml` | commit معتمد ثم push ثم manifest جديد | لا — بانتظار الموافقة |
| إشعارات فارغة | `app/Modules/Notifications/Routes/api.php` | تنفيذ API أو توثيق استثناء المقرر | لا |
| لا تاريخ حالات | لا migration | جدول تاريخ عند `updateStatus` | لا |
| لا معامل فرز | `ProductService::getAll` | معامل `sort` موثّق | لا |
| حجز يتيم إذا انتهت مهلة الرد | `OrderService.php` حوالي السطور 138–175 | معرّف حجز معروف قبل RPC ثم تحرير عند الشك، أو استعلام حالة | لا |
| لا failover تلقائي للـreplica | `config/database.php` + `.env.perf` | إجراء تشغيلي موثّق؛ ليس عطل بيانات | موثَّق |
| اختبارات JWT كانت تفشل بلا سر محلي | `phpunit.xml` | سر اختبار غير إنتاجي | نعم |

## 6) أثر المتطلب 3 على بقية الخدمات

- Redis يخزّن قوائم الكتالوج من MySQL فقط. الكمية لا تُخزَّن.
- ETag يُحسب بعد جلب الكمية. فشل gRPC يبقى 503 بلا 304.
- قراءات الطلبات تُجبر على الـprimary. مسار الحجز والالتزام لم يُعدَّل.
- `config/database.php` أصبح read/write. إذا غاب `DB_READ_HOST` يعود الافتراضي إلى `DB_HOST`.
- بيئة العرض الأصلية لم تُشغَّل هنا، لذلك أثر المتطلب 3 عليها تشغيليًا NOT VERIFIED. الملفات المشتركة (المتحكمات والخدمات) هي نفسها التي تغطيها اختبارات SQLite التي نجحت.

## 7) النسخ الاحتياطي

نقطة نهائية جديدة، دون حذف النسخ الأقدم:

`backups/perf/20261111-121106/`

- MySQL: 200000 طلب، 429410 بندًا، `index_orders_created_at=true`.
- PostgreSQL: `ecommerce_inventory_perf.dump`.
- SHA256 للملفين مطابق لـ `SHA256SUMS.txt` (أُعيد الحساب).
- النسخ المحفوظة أيضًا: `111437`, `111501`, `111524`, `113412`, ومجلد `replica-seed`.
- مقاييس `pre-opt` ما زالت في `docs/performance/baselines/pre-opt/`.
- الاستعادة ترفض الكتابة فوق طلبات موجودة إلا مع `-AllowOverwrite`.
- الـreplica لا تُستعاد كملف؛ بعد استعادة الـprimary يُشغَّل `Initialize-MysqlReplica.ps1` من لقطة متسقة بإحداثيات `SOURCE_LOG_FILE/POS`. هذا أُثبت على هذا الجهاز سابقًا، وليس على الحاسوب الثاني.
- كلمات مرور Docker موجودة داخل `docker-compose.perf.yml` كما في بيئة التطوير. `.env.perf` الحقيقي مستبعد عبر `.env.*`. لا تُرفع مجلدات `backups/`.

## 8) ما يدخل GitHub وما يبقى نقلًا خاصًا

جاهز للإضافة عند الموافقة (غير متتبع أو معدّل، وليس سرًا):

- `docker-compose.perf.yml`, `main-app/laravel/Dockerfile.perf`, `.env.perf.example`
- `config/perf.php`, `config/database.php`, خدمات المنتجات/الطلبات, `CatalogCache`, `ProductEtag`
- migration `2026_11_10_170000_add_orders_created_at_index.php`
- `tests/**/Performance/*`, `phpunit.xml`, `.env.example`
- `scripts/perf/**` بما فيها demo وreplica وtransfer
- `docs/audit`, `docs/performance/*.md`, وهذا الملف
- `composer.json` / `composer.lock` (تشمل `predis/predis`)

يبقى خارج Git (مغطى بـ`.gitignore` أو يجب عدم إضافته):

- `.env` و`.env.perf`
- `backups/perf/**` والنسخة `replica-seed`
- `docs/performance/baselines/` و`docs/performance/artifacts/`
- `baseline-pre-optimization.zip` و`phase0-review.zip` في جذر المستودع — لا تُضَف
- `vendor/`, storage, صور المنتجات

الاستراتيجية المقترحة بعد الموافقة: فرع `release/requirement-3` من `main`، commit واحد أو بضعة commits واضحة، ثم PR إلى `main`. لا إعادة كتابة تاريخ. لا push قبل الموافقة.

## 9) متطلبات الحاسوب الثاني

1. Docker Desktop، PHP 8.3 عند تشغيل الاختبارات على المضيف، `k6`، `curl.exe`.
2. المستودع **بعد** commit المتطلب 3، وليس `cb0dbb9` وحده.
3. نسخ `.env.perf.example` إلى `.env.perf` وتعبئة `APP_KEY` و`JWT_SECRET` محليًا.
4. `Initialize-PerfEnvironment.ps1` ثم `Restore-PerfDataset.ps1 -BackupDir backups/perf/20261111-121106`.
5. `Initialize-MysqlReplica.ps1` ثم التأكد أن `DB_READ_HOST=mysql-replica` ثم إعادة إنشاء خدمة `app`.
6. `Preflight-SecondLaptop.ps1` ثم `Preflight-CommitteeDemo.ps1`.
7. نقل خاص لمجلد النسخة ولمجلدات baselines إن أريد عرض أرقام k6.

## 10) جاهزية العرض الحي

السكربتات موجودة وهي للقراءة أو لعمليات معلنة:

- `scripts/perf/demo/Preflight-CommitteeDemo.ps1`
- `Show-Dataset.ps1`, `Show-SqlPerformance.ps1`, `Show-RedisCache.ps1`, `Show-Etag.ps1`, `Show-Replica.ps1`, `Show-K6Short.ps1`, `Show-GrpcInventory.ps1`
- أدلة: `docs/performance/COMMITTEE_LIVE_DEMO.md` و`TRANSFER_TO_SECOND_LAPTOP.md`

العرض على **هذا** الجهاز ممكن لبيئة الأداء الحالية. العرض «من استنساخ جديد فقط» غير ممكن قبل commit وpush. النقل إلى حاسوب ثانٍ **لم يُنفَّذ**، فلا يُدَّعى نجاحه.

ترتيب مقترح للجنة على الحاسوب الذي استُعيدت عليه النسخة والشيفرة:

1. Preflight.
2. المتطلب 1: تسجيل/دخول، قائمة منتجات مع ترقيم، رفض طلب مستخدم آخر، حد الدخول (من الاختبار أو Postman).
3. المتطلب 2: تفاصيل منتج تظهر `available_quantity`؛ شرح الحجز الذري والتعويض؛ الإقرار بفجوة ضياع الرد.
4. المتطلب 3: Dataset، SQL قبل/بعد بـ`IGNORE INDEX`، Redis، ETag، Replica، k6 قصير.

## 11) الحكم

**BLOCKED_WITH_EXACT_REASONS**

الحجب ليس لأن بيئة الأداء الحالية معطلة. الحجب لأن النشر على GitHub والنقل القابل لإعادة البناء من `git clone` لا يكتملان قبل commit معتمد، ولأن المتطلبين 1 و2 ما زالا يحملان الفجوات المذكورة، ولأن الحاسوب الثاني لم يُختبر.
