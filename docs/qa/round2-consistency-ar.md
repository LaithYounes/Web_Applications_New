<div dir="rtl">
# تقرير الجولة الثانية: إبطال الكتالوج واتساق الطلبات

التاريخ: 13 نوفمبر 2026، بتوقيت دمشق.

**النتيجة:** تنفيذ الإصلاحين والتحقق منهما. المجموعة الافتراضية: **148 اختباراً، 1104 assertions**؛ تكامل MySQL/Redis: **8 اختبارات، 93 assertions**. جميعها ناجحة، دون skipped أو risky في التشغيل النهائي. المجموع 156 حالة تشغيل مختلفة و1197 assertions. ليست هذه نتيجة E2E جديدة لخدمة Java أو قياس أداء.

## الحالة والصلاحيات

- الفرع بقي `codex/integrate-requirement-3-performance`؛ HEAD بقي `c657b39b317f5b615b5a7fdbd91cacd2ac9cdb93`، وMERGE_HEAD بقي `e622551c88c7937781a55a7736f415907a8b5358`. لم ينفذ fetch أو merge أو تبديل فرع أو commit أو push أو reset أو stash أو git add.
- فُحصت الحالة الفعلية وتعليمات المجلدات السابقة ومجلد المشروع؛ لم يوجد AGENTS.md. لم توجد ملفات unmerged عند الفحص. التغييرات المرحلية الموجودة سابقاً تُركت كما هي.
- كان `OrderService::orderRelations/getOrder` وربط تفاصيل المدير في Controller إصلاحاً جزئياً موجوداً عند الاستلام. احتُفظ به واختُبر؛ لا يُنسب إنشاؤه إلى هذه الجولة. كذلك لم يُعدّل `tests/TestCase.php` أو `tests/bootstrap.php` في هذه الجولة.
- قواعد MySQL الحالية وPostgreSQL وخدمة Java والحاويات الحالية لم تُستخدم لكتابة الاختبارات. لم تنفذ هجرة عليها. **ملف migration الجديد جاهز في المستودع فقط، ويجب تطبيقه ضمن إجراءات النشر قبل تشغيل mutations بالكود الجديد.**

## الإصلاح الثاني: السبب والحل

كان update/delete لا يغيران جيل قوائم المنتجات والتصنيفات. وكان `get(version)` ثم `forever(version + 1)` يسمح بفقد increments، وكان fallback إلى 1 يعيد إمكانية الوصول إلى أسماء قديمة. كذلك لم يكن الإبطال يحمي من تعبئة الجيل الجديد بقراءة replica قديمة، أو من ملء الكاش ببيانات transaction لم تنتهِ بعد.

أصبحت `CatalogCache::mutate()` نقطة موحدة تجمع تعديل الكتالوج وزيادة generation داخل المعاملة نفسها. جدول صغير `catalog_cache_state`، صف id=1، هو المرجع الدائم للجيل. الزيادة هي SQL `generation = generation + 1` ذرية. تنشر قاعدة البيانات الجيل للقرّاء الآخرين **عند نجاح outermost commit فقط**، وتعيده عند rollback. هذا اختيار مقصود بدلاً من callback منفصلة تزيد الجيل بعد commit: تلك callback تترك فجوة إذا مات الكاتب بعد commit أو فشل Redis. تجهيز marker داخل transaction لا ينشر بياناتها قبل commit؛ اختبار MySQL باتصال مراقب مستقل أثبت ذلك فعلياً.

تقرأ مفاتيح الكاش الجيل من primary، ولا تستخدم مفتاح Redis القديم `catalog:version` كمرجع. أسماء القوائم الجديدة تحمل `catalog:s2` لمنع استخدام payloads القديمة. لا يوجد get ثم overwrite للعداد، ولا fallback إلى generation=1. إذا تعذرت قراءة الجيل تتحول القراءة إلى قاعدة البيانات دون قراءة/كتابة shared cache. وإذا تعذرت زيادة الجيل تفشل mutation وترجع معاملتها؛ لا يُبتلع فشل الإبطال.

هذا يفصل تعطل Redis عن صحة التعديلات: قد يحتفظ Redis بالمفاتيح القديمة طوال الانقطاع، لكن الجيل الدائم يتقدم مع البيانات، فلا تعود قابلة للوصول بعد التعافي. TTL يحذف الأجيال القديمة تدريجياً، وليس ضمان freshness.

تأتي تعبئة القوائم من primary للأب والتصنيف، مع transaction للـ count والصفحة والعلاقات. في MySQL الاختبار استُخدم REPEATABLE READ الافتراضي. إذا كان الطلب داخل transaction قائمة، يُتجاوز shared cache بالكامل؛ يرى الكاتب تعديلاته دون نشرها. القارئ المتزامن الذي اختار جيلاً قديماً يستطيع إكمال طلبه، لكن تعبئته تبقى تحت الجيل القديم ولا تلوث الجيل الملتزم الجديد. ضمان freshness هنا للقراءات التي تبدأ بعد اكتمال mutation؛ ليس إلغاء الطلبات السابقة التي ما زالت قيد التنفيذ.

تستخدم create/update/delete وfinishInitialStock وCategoryService::create هذا المسار. يشمل تغيير الجيل جميع الفلاتر والصفحات والتصنيفات، ولذلك تتحدث counts وpagination بعد النقل أو الحذف أو تغير النشر. بقي معنى `products_count` الحالي كما هو: عدد منتجات العلاقة غير المحذوفة soft delete، بما فيها المنتجات قيد initial stock؛ لم يتحول إلى عداد المنتجات المنشورة فقط.

أُجل حذف الصورة القديمة إلى afterCommit حتى لا تفقد نسخة محفوظة صورتها عند outer rollback. يُرفض update/delete/publish إذا أعاد الحفظ false. فشل الصورة أو الحفظ لا ينشر نسخة جديدة. تنظيف صورة بديلة عند rollback للمعاملة الخارجية المباشرة مسجل بعد اكتمال savepoint الخاصة بالخدمة. نظام الملفات لا يشارك معاملة SQL؛ انهيار العملية أو تعقيد معاملات خارجية متعددة المستويات قد يترك ملفاً غير مرجعي يحتاج تنظيفاً لاحقاً، لكنه لا ينشره في الكتالوج ولا يحذف الصورة القديمة قبل commit.

مفاتيح المنتجات تفصل category/per_page/page، والقوائم عامة وتُشارك بين المستخدمين عمداً. تمرر الخدمات page المحسوبة إلى paginate صراحة. تستخدم روابط HIT عنوان الطلب الحالي. لم تُضف أي قراءة Java إلى قوائم الكتالوج؛ لم تُخزّن سلال أو طلبات أو حجوزات فيه.

### كلفة الاختيار وبدائله

- كل cache HIT يحتاج lookup صغيراً إلى primary للجيل، وMISS يحتاج قراءة الكتالوج من primary. Redis ما زال يوفر count/page/eager-loading في HIT، لكن هذا ليس ادعاء بتحسن latency مقاس.
- تحديث صف الجيل يسلسل عمليات كتابة الكتالوج لفترة المعاملة. لا توجد Redis global lock حول القراءات، ولا حجز يمنع جميع طلبات القراءة. قد تؤخر outer transaction طويلة كتابات كتالوج أخرى؛ إبقاء المعاملات قصيرة مطلوب.
- Redis INCR وحده لا يحل ضياع invalidation أثناء outage ثم العودة إلى payloads قديمة. UUID في Redis يقلل إعادة استخدام generation، لكنه لا يوفر durable invalidation عند فشل الكتابة. TTL وحده لا يحقق العقد. outbox مع worker أو causal replication يوفر خيارات أخرى بكلفة تشغيل أعلى؛ لم يُضف هنا.
- تغييرات SQL المباشرة خارج الخدمات، ومنها مولد بيانات perf، يجب أن تمر بمسار الإبطال أو تنفذ invalidate صريحة. لم تُعد كتابة تلك الأدوات خارج نطاق الجولة.

### HTTP cache وETag

كاش الخادم يبقى 60 ثانية للمنتجات و120 ثانية للتصنيفات لكل generation. قائمة المنتجات أصبحت `public, max-age=0, must-revalidate`؛ بدلاً من السماح للمتصفح بإعادة استخدام نسخة لمدة 30 ثانية بعد mutation، يلزم الرجوع إلى الخادم. القائمة لا تملك ETag خاصة بها؛ إعادة التحقق فيها تعيد 200 والتمثيل الحالي. تكلفة هذا القرار HTTP requests إضافية، وتخفف كلفة SQL فيها كاش الخادم.

ETag تفاصيل المنتج بقي مفعلاً ويشمل المخزون الحي. اختُبر 200 ثم 304، ثم تغيير الصورة مع ETag قديمة يعيد 200 وبصمة جديدة، ثم 304 مع البصمة الجديدة. اختبارات المخزون الموجودة بقيت ناجحة. اختبارات ETag تستخدم gRPC mock؛ ليست قياساً لشبكة Java حقيقية.

## الإصلاح الثالث: المصدر والعلاقات والتعافي

`useWritePdo` على query الأب لا ينتقل تلقائياً إلى queries علاقات eager loading. بقي الحل الموضعي الموجود في `orderRelations()` مستخدماً لكل علاقة لازمة: items في قائمة العميل، user/items في قائمة المدير، وitems/statusHistory في تفاصيل الاثنين. الأب نفسه يستخدم ordersQuery بالسياسة نفسها. تفاصيل المدير تمر عبر getOrder بالخدمة. لم تعدّل الصلاحيات أو ownership أو شكل OrderResource.

كل ما تستخدمه OrderResource محمل عند رجوع الخدمة، بما فيه عناصر نتائج mutation والتعافي المحملة داخل transactions. أثبتت الاختبارات أن بناء resource لاحقاً لا ينفذ query جديدة. لا توجد `useWriteConnectionWhenReading` عامة ولا flag اتصال تحتاج إعادة ضبط. عند `perf.orders_use_primary=false` تكون GET خارج transaction قابلة للتأخر حسب العقد؛ تبقى reads داخل transaction على primary بطبيعة Laravel. eligibility للقراءات الأخرى على replica باقية.

GET السلة عولج مستقلاً: الأب والعلاقات `items/product/category` تختار primary عند تفعيل السياسة. تُحسب checkout_pending في الخدمة وتُنقل كقيمة جاهزة إلى CartResource؛ أُزيل استعلام resource المتأخر الذي كان يمكنه استخدام replica بعد انتهاء transaction. بقيت add/update/remove داخل معاملاتها الأصلية، واستعملت نفس loader عند إرجاع النتيجة.

أصبحت القراءة الأولية لـ PendingCheckout في finishCheckout صريحة على primary، حتى مع تعطيل flag الخاصة بقوائم الطلبات. تبقى قبل المعاملة، للحفاظ على ترتيب قفل السلة أولاً وتجنب فتح snapshot REPEATABLE READ قبل انتظار عامل آخر أكمل الطلب. فحوص intent اللاحقة والطلب المكتمل داخل المعاملة تقرأ primary. اختيار دفعة أوامر recover-checkouts أصبح من primary أيضاً، كي لا تفوت intent جديدة أو يتجاهل backoff الأحدث. اختُبر إنهاء intent وإعادة محاولة نتيجتها المكتملة دون RPC ثانية.

الوثائق الرسمية تؤكد أن sticky تخص دورة الطلب الحالية؛ ليست بديلاً عن هذا الاختيار الموضعي عبر طلبات مستقلة: [Laravel 12 Database](https://laravel.com/framework/docs/12.x/database). تتبع التنفيذ استند أيضاً إلى نسخة framework المثبتة محلياً.

## الملفات والدوال

المسارات أدناه نسبية إلى جذر المستودع:

| الملف | التغيير في هذه الجولة |
|---|---|
| main-app/laravel/app/Support/Performance/CatalogCache.php | mutate/invalidate/version/readKey؛ bypass داخل المعاملات؛ namespace جديدة؛ transaction للتعبئة؛ fallback؛ path الحالية |
| main-app/laravel/database/migrations/2026_11_13_000000_create_catalog_cache_state_table.php | جدول الجيل الدائم وتهيئة صفه |
| main-app/laravel/app/Modules/Products/Services/ProductService.php | كل mutations داخل mutate؛ primary للتعبئة وidempotency lookup؛ تأجيل تنظيف الصورة وفحص فشل الحفظ |
| main-app/laravel/app/Modules/Products/Services/CategoryService.php | create داخل mutate؛ قوائم primary وpage صريحة |
| main-app/laravel/app/Modules/Products/Controllers/ProductController.php | max-age=0 للقائمة |
| main-app/laravel/app/Modules/Orders/Services/OrderService.php | قراءة pending الأولية primary؛ الإبقاء على سياسة eager loading الجزئية السابقة واختبارها |
| main-app/laravel/app/Modules/Orders/Services/CartService.php | getCart/loadCart؛ علاقات primary وpending جاهزة للـ Resource |
| main-app/laravel/app/Modules/Orders/Resources/CartResource.php | استخدام checkout_pending المحسوبة دون lazy query |
| main-app/laravel/app/Console/Commands/RecoverCheckouts.php | اختيار العمل من primary |
| main-app/laravel/tests/Feature/Performance/CatalogCacheTest.php وCatalogCacheFailureTest.php | DatabaseMigrations بدلاً من transaction اختبار تغلف الحالة كلها، لتبقى اختبارات HIT فعلياً خارج transaction؛ assertions الأصلية باقية |
| main-app/laravel/tests/Feature/Performance/CatalogMutationConsistencyTest.php | حقول/صورة/نشر/نقل/حذف/صفحات/counts/فشل/rollback/تعافٍ/idempotency/HTTP/backend database |
| main-app/laravel/tests/Feature/Performance/ReadSourceConsistencyTest.php | اتصالا SQLite مستقلان لاختبار routing وHTTP والملكية والتعافي وquery count |
| main-app/laravel/tests/Integration/CatalogOrdersMySqlTest.php | replication حقيقية مع SQL_THREAD متوقف، HTTP Kernel بطلبات منفصلة، Redis والتزامن وouter commit |
| main-app/laravel/tests/Support/Round2Environment.php وround2-worker.php | guard للـ endpoints وUUID والـ DB؛ workers منفصلون مع barrier |
| scripts/qa/Run-Round2Consistency.ps1 | إنشاء وتنظيف موارد معزولة كلياً وتشغيل التكامل؛ لا يعتمد على Compose القائمة |

لم يحتج `config/database.php` أو `OrderResource.php` أو `OrderController.php` تعديلاً جديداً في هذه الجولة: جرى التحقق من سلوكها مع السياسة الموضعية الحالية.

## إثبات regression والنتائج الإلزامية

أدلة التشغيل موجودة في:

`C:/Users/laith/.codex/visualizations/2026/11/13/round2-consistency/`

روابط مباشرة: [المجموعة النهائية](C:/Users/laith/.codex/visualizations/2026/11/13/round2-consistency/full-suite.log)، [تكامل MySQL](C:/Users/laith/.codex/visualizations/2026/11/13/round2-consistency/mysql-integration.log)، [إثبات الفشل السابق](C:/Users/laith/.codex/visualizations/2026/11/13/round2-consistency/catalog-before.log)، [دليل التنظيف](C:/Users/laith/.codex/visualizations/2026/11/13/round2-consistency/cleanup.json).

- `catalog-before.log/xml`: قبل تعديل كود الكاش، **7 حالات، 6 failures**. فشلت زيادة الجيل لكل حقل، والنقل/البيانات القديمة، ورؤية الكاتب أثناء outer transaction. اختبار فشل الحفظ والصورة كان ناجحاً بالفعل. حُفظت النتيجة قبل الحل.
- `read-source-before.log/xml`: أُعيد مؤقتاً سلوك CartService/CartResource الأصلي وقراءة pending غير المقيدة، مع snapshots واستعادة بايتية مؤكدة في finally؛ **حالتان: failure واحدة وerror واحدة**. خطأ قديم في pending ظهر كـ rejection، وpending السلة ظهرت false. نتيجة baseline هذه احتوت أيضاً risky notification بسبب انتهاء الاختبار بالاستثناء؛ لا تُحسب نجاحاً. النتيجة النهائية خالية منه.
- أصل مشكلة eager-loaded order relations كان مصححاً جزئياً عند الاستلام؛ لم يُدّع تشغيل baseline غير موجود لها. اختبارات source المضبوطة وreplication الحقيقية تؤكد الحل الحالي.
- `full-suite.log/xml`: **148/148، 1104 assertions، صفر failures/errors/skips**. يشمل جميع اختبارات المنتج والطلبات والإلغاء والتاريخ والإشعارات الموجودة، والاختبارات الجديدة.
- `mysql-integration.log/xml`: **8/8، 93 assertions، صفر failures/errors/skips/risky**.

| المتطلب | الدليل النهائي | النتيجة |
|---|---|---|
| MISS ثم HIT | CatalogCacheTest + MySQL catalog_fill | ناجح على array وRedis؛ واختبار database backend |
| كل حقل: name/price/description/image/category/publication | CatalogMutationConsistencyTest fields/image/move | ناجح؛ النشر ممثل بـ pending_initial_quantity وفق النموذج الفعلي |
| كاش ساخنة ثم نقل أو حذف | move_delete_counts_and_pagination | ناجح للعام والفلترين وcounts |
| counts والصفحات المتأثرة | mutations_shift_hot_pages؛ 17 منتجاً و17 تصنيفاً | ناجح لتغير total/last_page والعناصر عند انتقال الحدود |
| outer commit | MySQL generation_and_data_are_invisible + اختبار SQLite outer | ناجح؛ مراقب مستقل يرى البيانات والجيل القديمين حتى commit |
| rollback/update/delete/category، فشل حفظ أو صورة | outer_commit_and_rollback، outer_rollback_of_delete، failed_save_and_image | ناجح؛ الكاش المحفوظ والصورة القديمة لا يتغيران قبل commit |
| فشل cache get/put ثم التعافي | CatalogCacheFailureTest + MySQL catalog_fill | ناجح؛ faults محقونة في adapter مع بقاء Redis القديم فعلياً |
| فشل invalidation/قراءة generation ثم التعافي | missing_generation_bypasses_cache_and_rejects_mutation | ناجح؛ لا fallback إلى 1؛ فشل mutation يعيد SQL ثم تتعافى القراءة |
| قراءات متزامنة مع mutation | MySQL concurrent_invalidations؛ كاتب + قارئان | ناجح؛ 20 تعديلاً و80 قراءة متوازية، والنهائي Concurrent 19 |
| invalidations متزامنة | ثلاثة PHP workers، كل واحد 20 زيادة | ناجح؛ الفرق 60 بالضبط، ثم 20 mutation إضافية =80 |
| تعبئة قارئ من جيل متقاعد | MySQL reader_filling_old_namespace | ناجح؛ interleaving مضبوط باتصال كاتب مستقل، المفتاح القديم لا يصبح الحالي |
| mutation مع replica lag | MySQL catalog_fill | ناجح؛ replica تحتفظ بالاسم القديم والخادم يعيد الاسم الملتزم الجديد |
| المستخدمون والفلاتر والصفحات والروابط | pages_filters_users_and_current_request_links | ناجح؛ الكتالوج عام؛ لا حقول مستخدم أو available_quantity في القائمة |
| initial stock وidempotency وquantity=0 | hot_cache_initial_stock_failure_retry + ProductApi/Mutation | ناجح؛ غموض المخزون لا ينشر منتجاً pending؛ retry لا ينشئ نسخة ثانية |
| HTTP freshness وإعادة التحقق | hot_image_and_http_revalidation + ProductEtagTest | ناجح؛ list 200 محدثة، detail 200/304 وتغير ETag |
| الطلب على primary فقط | ReadSourceConsistency + MySQL new_parent_absent | ناجح مع replica فعلية لا تحتوي الأب |
| الأب في الطرفين وitems/history متأخرة | MySQL existing_parent_lagged_items_status_history | ناجح؛ flag=false يظهر التأخر المقصود للمقارنة |
| حالة وتاريخ أحدث | الاختبار نفسه + HTTP checkout_status_change | ناجح؛ confirmed وتاريخان على primary |
| قوائم وتفاصيل العميل والمدير | اختبارات source وMySQL HTTP | ناجح؛ العناصر موجودة للقوائم والتفاصيل؛ تاريخ التفاصيل محفوظ |
| HTTP مستقل بعد الإنشاء والتعديل | MySQL http_checkout_status_change_and_separate_gets | ناجح؛ Requests جديدة عبر Http Kernel مع نسيان sticky بين الطلبات؛ ليس خادم TCP منفصلاً |
| الملكية والصلاحيات | source/MySQL ownership + OrderAuthorizationTest | ناجح؛ الآخر يحصل 404، وقيود تغيير الحالة الأصلية باقية |
| missing/empty/multiple pages | source وMySQL HTTP/page3 | ناجح؛ 404 وقوائم فارغة و21/22 طلباً عبر صفحات |
| flag=true/false؛ داخل transaction وخارجها | source/real existing_parent | ناجح؛ transaction تقرأ primary حتى مع تعطيل flag |
| إعادة استخدام التطبيق دون تسرب | استعلام replica خام بعد قراءات الخدمة في التطبيق نفسه | ناجح؛ ما زال يعيد الحالة المتأخرة المقصودة |
| eager loading دون N+1 أو lazy Resource | source وMySQL query log | ناجح؛ admin list أربع queries مع page كاملة، وserialization صفر queries |
| GET cart وpending والتعافي | source وMySQL cart_pending/recovery_command | ناجح؛ pending snapshot جاهزة قبل Resource؛ الأمر يجد intent على primary وينهيها؛ retry لا يكرر RPC |

## عزل MySQL وRedis والتنظيف

`Run-Round2Consistency.ps1` ينشئ شبكة وأسماء حاويات عشوائية مستقلة، ومنافذ localhost عشوائية، وtmpfs لمسارات بيانات MySQL وRedis. لا يربط volumes أو ملفات .env أو storage من الخدمات القائمة. كلا MySQL يعملان بإعداد GTID وserver_id مختلف؛ إعداد replication حقيقي، ثم WAIT_FOR_EXECUTED_GTID_SET، ثم STOP REPLICA SQL_THREAD لصنع lag مضبوطة، وإعادة تشغيله وانتظار اللحاق في tearDown.

قبل migrations يتحقق PHP من اسم DB العشوائي والـ server UUID الفعلي لكل endpoint. اختبارات التكامل منفصلة عن TestCase الافتراضية التي ما زالت تشترط SQLite :memory: وarray. لم تُضعف هذه الحواجز للسماح ببيئة المستخدم.

التنظيف في finally يتحقق من label لكل حاوية/شبكة قبل حذفها، ويزيل فقط موارد run الحالية والملفات المؤقتة بعد تحقق المسار المطلق. `cleanup.json` يثبت run النهائية `round2-42b9c1f6cc3d` وتنظيف حاوياتها الثلاث وشبكتها. الفحص الختامي لم يجد موارد round2 باقية؛ حاويات المستخدم الست بقيت قائمة.

تثبيت الاعتماديات كان ضرورياً لأن vendor المحلي لم يتطابق مع lock: أضيفت أربع حزم مقفلة الإصدارات دون تغيير composer.json/lock أو تشغيل scripts/plugins. فشلت محاولة أولى بسبب قفل ملف Windows/OneDrive، ثم نجحت إعادة install. الفشل الأول للمجموعة الشاملة كان بسبب google/auth الناقصة؛ محفوظ في `full-suite-before-vendor-install.log`، ولا يُعرض كعيب في الإصلاح. التشغيل النهائي تم بعد تصحيح vendor.

نجح composer validate وPHP lint للملفات الجديدة/المعدلة وPowerShell parsing وgit diff --check. لم تُشغّل migrate:fresh أو FLUSHDB على بيئة قائمة. جميع عمليات إنشاء الجداول وقواعد البيانات تخص SQLite المؤقتة أو حاويات MySQL الجديدة فقط.

## إعادة التشغيل وحدود النتيجة

من `main-app/laravel`:

```powershell
php vendor/phpunit/phpunit/phpunit --colors=never
```

قد تحتاج عملية PHP المحلية إلى OPENSSL_CONF المناسب لتوليد مفاتيح اختبارات Firebase؛ استُخدم مسار تثبيت PHP المحلي لعملية الاختبار فقط.

ومن جذر المشروع، مع Docker وصور mysql:8.4 وredis:7-alpine المتاحتين أو قابلية تنزيلهما:

```powershell
pwsh -NoProfile -File scripts/qa/Run-Round2Consistency.ps1 -EvidenceDirectory "$env:TEMP/round2-evidence"
```

لا يوجد benchmark جديد، ولا ادعاء بتجربة PostgreSQL/Java فعلية أو failover عند انقطاع replica. المخزون استُبدل بـ mock في هذه الجولة حتى لا تحدث حجوزات في خدمات المستخدم. لم تُطبق migration الجديدة على قواعد قائمة؛ نشرها وإجراءات التشغيل من جولات لاحقة. الالتزام هنا بإصلاح كاش القوائم ومصدر قراءة الطلبات والعلاقات والمسارات المتأثرة بهما.
</div>
