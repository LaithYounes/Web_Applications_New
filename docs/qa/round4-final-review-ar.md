# تقرير الجولة الرابعة والنهائية: اتحاد الاختبارات وregression التكامل

> تحديث لاحق: عولج المانع الوظيفي F01 بتفويض المستخدم بعد صدور هذا التقرير. [تفاصيل الإصلاح وإعادة الاختبار وقائمة التنظيف اليدوي](product-detail-primary-fix-ar.md). النص والنتائج أدناه يوثقان الحالة السابقة للإصلاح، وليسا الحكم على المصدر المحدّث.

التاريخ: 13 نوفمبر 2026، بتوقيت دمشق. النطاق: نتيجة الدمج الموجودة في شجرة العمل، وليس جمع نتائج نسختي main وfeature. **الحكم: غير جاهز.** التفاصيل والأدلة والموانع أدناه. لم يُعدّل كود الإنتاج في هذه الجولة؛ أضيفت اختبارات وأدوات تحقق فقط.

مجلد الأدلة الدائمة:
`C:/Users/laith/.codex/visualizations/2026/11/13/round4-final/`

## أ) حالة الفرع والدمج

- الفرع: `codex/integrate-requirement-3-performance`.
- HEAD: `c657b39b317f5b615b5a7fdbd91cacd2ac9cdb93`.
- MERGE_HEAD: `e622551c88c7937781a55a7736f415907a8b5358`.
- merge-base: `cb0dbb95cd7f85ad033b05704033c79e4b007730`.
- لا unmerged entries. الدمج ما زال مفتوحاً ولم يُنشأ merge commit. لم يُنفّذ commit أو push أو تغيير فرع أو reset/rebase/stash/merge --abort أو fetch.
- فُحصت التغييرات المرحلية وغير المرحلية والملفات الجديدة. بقي index كما استُلم؛ لم يُستخدم git add. وجود MM/AM لا يعني تعارضاً: يعني أن بعض إصلاحات الجولات السابقة موجود في working tree بعد النسخة المرحلية. **المادة المختبرة هي شجرة العمل الحالية، وليست index وحده**؛ يجب مراجعة staging الصريح لاحقاً قبل أي commit.
- أثبت فحص المصادر وجود جميع ملفات وطرق الاختبار الموجودة في المرجعين، دون إسقاط طرق اختبار. اختلاف مصادر الاختبارات السابقة محصور في توسيع ETag وJava، وتبديل DatabaseMigrations في اختباري الكاش من الجولة الثانية. ملفا `union-coverage.json` و`source-manifest.json` يوثقان المقارنة والبصمات. `tested-copy-match.json` يربط نسخة PHPUnit المعزولة بمصادر التطبيق.
- `initial-status.txt` و`final-status.txt` يوثقان الحالة. أدلة الجولات السابقة بقيت محفوظة، ولم تُحوّل نتائج snapshotين إلى نجاح للدمج.

## ب) ملخص الإصلاحات الخمسة

### 1. حل تعارضات الدمج دون فقد وظائف الطرفين — M01

السبب الجذري: ستة تعارضات نصية جمعت controllers وservice provider وملفات الاعتماديات والاختبار والاستبعاد؛ اختيار أحد الطرفين كاملاً كان سيسقط إما تعديلات المنتجات وتاريخ الطلبات والإشعارات والتعافي، أو الكاش وETag.

الملفات: `main-app/laravel/.dockerignore`، `app/Providers/AppServiceProvider.php`، `app/Modules/Products/Controllers/ProductController.php`، `app/Modules/Orders/Controllers/OrderController.php`، `composer.lock`، `phpunit.xml`. ودعم العزل في `tests/bootstrap.php` و`tests/TestCase.php`.

الاختيار: الإبقاء على store/update/destroy وidempotency والصلاحيات، ودمج show مع ProductEtag وIf-None-Match؛ إبقاء تاريخ الحالات وactor والإلغاء، وتوجيه detail المدير إلى الخدمة. يحتفظ provider بـsingleton لكل من OrderNotificationRecorder وCatalogCache. Composer lock يحتفظ بـgoogle/auth ويضيف predis دون تحديث شامل. اختبارات bootstrap تفرض SQLite بالذاكرة وarray وتعطيل push، وتتجنب config cache الحقيقية قبل تشغيل migrations.

يناسب ذلك نمط المشروع: controllers للعقد والخدمة للمنطق، ودورة حياة الطلب الحالية دون بديل موازٍ. رُفض اختيار ours/theirs كاملاً ودمج lock يدوياً. الاختبارات الأصلية محفوظة وتُنفّذ على الناتج. القيد: سلامة index وحده لا تثبت سلامة working tree اللاحقة، لذلك سُجلت بصمات المصدر المختبر. سلوك ETag تحت lag له مانع مستقل موضح لاحقاً.

### 2. إبطال الكتالوج بعد التعديل والحذف واتساقه مع commit — M02/M07

السبب الجذري: update/delete لم يبطلا القوائم؛ زيادة Redis بأسلوب get ثم overwrite قد تفقد increments أو تعيد namespace قديمة. فشل Redis أو تعبئة من replica متأخرة أو نشر بيانات outer transaction كان يسمح ببيانات قديمة أو غير ملتزمة.

الملفات والدوال: `app/Support/Performance/CatalogCache.php`، خصوصاً mutate/invalidate/version/readKey/rememberPaginator/restorePaginator؛ `database/migrations/2026_11_13_000000_create_catalog_cache_state_table.php`؛ create/update/delete/finishInitialStock وgetAll في ProductService؛ create/getAll في CategoryService؛ وindex في ProductController.

الحل: جيل دائم في primary، وزيادة SQL ذرية ضمن المعاملة نفسها التي تعدل الكتالوج. لا يصبح الجيل الجديد مرئياً قبل outermost commit؛ rollback يعيد البيانات والجيل معاً. لا fallback إلى الجيل 1 عند فشل القراءة. القراءة تتجاوز الكاش عند غياب الجيل أو داخل transaction؛ زيادة الجيل الفاشلة تُفشل mutation. تعبئة قوائم الكتالوج وعلاقاتها تأتي من primary داخل snapshot واحدة. Redis يحتفظ بأجيال متقاعدة إلى انتهاء TTL. تغيرت namespace إلى s2. تؤجل إزالة الصورة القديمة إلى afterCommit؛ فشل الحفظ أو الصورة لا ينشر تمثيلاً جديداً. HTTP قائمة المنتجات يفرض إعادة تحقق بـmax-age=0. يعاد إنشاء links بعنوان الطلب الحالي وتُمرر page المطبعة صراحة إلى paginate.

البدائل: Redis INCR وحده لا يحل فقد invalidation أثناء outage؛ TTL وحده لا يحقق freshness؛ callback منفصلة بعد commit تترك فجوة انهيار بين commit والإبطال؛ outbox خيار أوسع يتطلب عاملاً إضافياً. اختيرت معاملة SQL المحلية لأنها المرجع الموجود أصلاً.

الكلفة والقيود: lookup إلى primary لكل HIT، وتسلسل كتابات الجيل على صف واحد؛ لا ادعاء تحسن أداء مقاس. يجب تطبيق migration قبل تشغيل الكود. الكتابة المباشرة خارج الخدمات تحتاج إبطالاً مقصوداً. المعاملة لا تشمل filesystem؛ انهيار العملية قد يترك صورة غير مرجعية تحتاج تنظيفاً. ضمان القوائم لا يثبت freshness لتفاصيل المنتج؛ الاختبار الجديد يعزل هذا الفرق.

### 3. قراءة الطلب وعلاقاته والسلة والتعافي من مصدر متسق — M03/M06

السبب الجذري: useWritePdo للأب لا ينتقل تلقائياً إلى eager relationships؛ sticky لا تمتد إلى طلب HTTP تالٍ. كانت CartResource تنفذ exists متأخرة بعد انتهاء transaction؛ وكان lookup intent ومسح عامل التعافي مؤهلين للقراءة القديمة.

الملفات والدوال: ordersQuery/orderRelations/getUserOrders/getAllOrders/getUserOrder/getOrder/finishCheckout في OrderService؛ show في OrderController؛ getCart/loadCart في CartService؛ toArray في CartResource؛ handle في RecoverCheckouts.

الحل: اختيار primary صريح للأب ولكل علاقة مطلوبة، مع احترام perf.orders_use_primary في GET. عناصر الموارد جاهزة قبل serialization دون query متأخرة. checkout_pending تحسب في الخدمة. قرارات pending الأولية والمسح للتعافي تختار primary حتى إن تعطلت سياسة قوائم الطلبات. بقي ترتيب الأقفال والسلة ثم intent، ولم تُضف flag اتصال عامة تتسرب إلى worker لاحق.

البدائل: sticky وحدها لا تغطي الطلبات المنفصلة؛ تغيير اتصال كامل أو حالة اتصال دائمة أوسع من المطلوب؛ انتظار replica لكل قراءة يزيد التعقيد. السياسة الموضعية تناسب بنية الخدمات الحالية. القيود: تعطيل flag يسمح بتأخر GET حسب السياسة؛ لا failover تلقائياً؛ وتفاصيل المنتج ليست ضمن هذه الدوال وتظل مانعاً مستقلاً تحت lag.

### 4. تهيئة الأداء من موارد فارغة ثم تفعيل replica المتحقق منها — M04/M12

السبب الجذري: Compose فرض read host لخدمة replica خلف profile غير مفعّل؛ bootstrap قرأ منها قبل migrations. تشغيل حاوية دون schema وreplication لا يثبت الجاهزية.

الملفات: `docker-compose.perf.yml`، `.env.perf.example`؛ `scripts/perf/Perf.Common.ps1`؛ Initialize-PerfEnvironment وValidate-PerfEnvironment؛ وreplica/Initialize-MysqlReplica.

الحل: primary افتراضية وDB_READ_HOST قابلة للضبط فعلياً عبر interpolation. ترتيب التشغيل: هوية الموارد وSQL عبر TCP، فحص PDO، migrate العادية دون seed، تشغيل Laravel/Java؛ ثم عند طلب replica إيقاف الكتّاب، dump بإحداثيات النسخ، seed إلى وجهة متحققة، انتظار IO/SQL، مقارنة schema والصفوف، read_only، ثم تحويل PDO وإعادة إنشاء التطبيق. profile lifecycle يشغل عاملي التعافي والإشعارات مع push disabled. يُستأنف channel كامل، وتُرفض schema جزئية دون -ReseedReplica. فشل التفعيل يعيد الاختيار إلى primary ويحاول إعادة إنشاء التطبيق عليها؛ الفشل المتبقي يُعلن ولا يُغطى برسالة نجاح.

البدائل: فرض replica دائماً، أو الاكتفاء بتعديل env، أو تشغيل container فقط، أو نسخ بيانات بلا coordinates، كلها لا تثبت المسار الفعلي. رُفض حذف volumes الموجودة. القيود: لا failover صامت؛ outage بعد التفعيل يفشل بوضوح. Compose اليدوي يتأثر بأولوية متغيرات shell، ولذلك تستخدم الأدوات --env-file ومزامنة متغير العملية.

### 5. النسخ والاستعادة بأسلوب fail-closed — M08

السبب الجذري: أخطاء count كانت تتحول إلى صفر؛ بعض native exit codes مهملة؛ نجاح عدد طلبات MySQL كان يغطي فشل PostgreSQL، مع tmp مشتركة وتحقق غير كافٍ من الأرشيف والوجهة.

الملفات والدوال: Invoke-PerfNative وNew/Remove-PerfTemp والتحقق من الهوية في Perf.Common؛ وظائف manifest/fingerprint/import/إيقاف واستئناف الكتّاب في Transfer.Common؛ Backup-PerfDataset وRestore-PerfDataset.

الحل: process boundary تفحص exit code وtimeout وbytes وUTF-8 على PowerShell 5.1 و7؛ manifest v2 إلزامية، checksums ومنع traversal/links/ADS؛ هوية project/service/volume والقاعدتين قبل الاستبدال؛ staging مستقلة للقاعدتين؛ فحص كل جدول وصف وفهرس وFK وقيود المخزون والحالات وsequences؛ pre-state dumps وjournal؛ فحص cleanup والاستئناف قبل RESTORE OK. فشل metadata/count/parse ليس صفراً. لا يكفي orders>=200000 لإعلان النجاح.

البدائل: الاعتماد على ErrorActionPreference مع native commands غير كافٍ في PS5.1؛ count-only لا يثبت المخزون؛ transaction موزعة بين المحركين غير موجودة. اختير staging ثم تحقق زوج القاعدتين، مع توثيق أن الاستبدال ليس atomic بين MySQL وPostgreSQL.

القيود: يجب منع جميع الكتّاب الخارجيين وDDL طوال النافذة؛ الأدوات تتحكم بخدمات المشروع المحدد فقط. عند فشل جزئي تبقى الخدمات الكاتبة متوقفة؛ تُعاد الاستعادة من الأرشيف نفسه مع AllowOverwrite وRecoveryDir جديدة، أو تستعاد حالتا ما قبل العملية مع تحقق مستقل. لا rollback موزعة آلية. الأرشيف القديم بلا manifest v2 مرفوض. بعد restore ناجحة تختار القراءة primary ويُغيّر CACHE_PREFIX دون FLUSHDB؛ replica تحتاج إعادة تهيئة مقصودة.

## ج) الاختبارات والنتائج والأدلة

### اتحاد الاختبارات على النتيجة المدمجة

| المجموعة النهائية | ناجح | فاشل | skipped/متعذر داخل المجموعة | الخدمة أو المحاكاة | الدليل |
|---|---:|---:|---:|---|---|
| Laravel Unit/Feature | 150 | 0 | 0 | SQLite :memory:، array؛ gRPC وHTTP/Firebase بالمحاكاة حيث تستخدمها الاختبارات | laravel.log/xml؛ 1136 assertions |
| MySQL/Redis cross-regression | 8 | 1 | 0 | MySQL 8.4 primary/replica حقيقيان وRedis حقيقية؛ gRPC mock لفصل توجيه SQL | consistency/mysql-integration.log/xml؛ 98 assertions |
| Java الحالية | 8 | 0 | 0 | Spring/JPA ومعاملات وأقفال PostgreSQL 16 حقيقية | live/java.log وTEST-*.xml |
| المسار الحي Laravel→gRPC→Java | 6 سيناريوهات مركبة | 0 | 0 | MySQL/PostgreSQL/Redis وgRPC حقيقية؛ fault decorator يفقد الرد بعد نجاح RPC فعلياً | live/live-results.json |
| Bootstrap/replica/backup/restore | 15 | 0 | 0 | Docker وقاعدتان وRedis وJava/Laravel حقيقية | ops/integration-results.json |
| PostgreSQL dump/restore والقيود | 3 | 0 | 0 | PostgreSQL حقيقية | pg-roundtrip/pg-roundtrip-results.json |
| Native على PowerShell 5.1 | 5 | 0 | 0 | Docker وSQL حقيقيان، exit 47، auth فاشل وUnicode ونقل bytes | live/native-ps51.json |
| حقن أعطال orchestration على PS5.1 | 46 | 0 | 0 | نموذج boundaries، ليس قواعد حقيقية | faults-ps51/fault-results.json |
| حقن أعطال orchestration على PS7 | 46 | 0 | 0 | النموذج نفسه على runtime ثانية | faults-ps7/fault-results.json |
| update script من main | 13 | 0 | 0 | git/docker/HTTP mocks في checkout مؤقتة | updater-tests.log |
| Firebase setup من main | 8 | 0 | 0 | Docker mock وcredentials وهمية؛ لا FCM حقيقية | firebase-setup-tests.log |
| إعادة اختبار bootstrap مع env متعارضة | 6 | 0 | 0 | تكرار subset من المجموعة الأولى؛ لا يضاف إلى العدد الفريد | isolation.log/xml؛ 60 assertions |

لا تجمع هذه الأعداد في رقم نجاح واحد: حالات PowerShell متكررة على runtimeين، وsubset العزل مكرر، والسيناريوهات الحية مجموعات تحقق مركبة. لا تُحسب نتائج الجولات السابقة أو محاولة الثمانية القديمة بدلاً من المجموعة النهائية التي تضم regression الجديدة. `executed-tests.csv` يسرد كل testcase، و`test-ledger.csv` يضيف المتطلبات وطريقة التشغيل والبيئة والمتوقع والحدود وحالة المصدر.

العدد الفريد داخل أطر PHPUnit/JUnit فقط: **167 حالة نهائية: 166 ناجحة وواحدة فاشلة، دون skipped**. تطابقت بايتياً **70 class** من أصناف Java runtime، بما فيها أصناف المخزون وgRPC المولدة، مع التجميع الحالي: live/runtime-class-provenance.json.

### السيناريوهات الاثنا عشر المطلوبة

| # | الخطوات والمتوقع | النوع والمصدر | النتيجة والدليل والحدود |
|---|---|---|---|
| 1 | إنشاء مع PNG وinitial stock، replay بنفس المفتاح، تعديل وحذف وصور وفشل حفظ | ProductApi/ProductMutation/CatalogMutationConsistency بالمحاكاة؛ live scenario 1/2/6 مع Java حقيقية | الإنشاء لا يكرر المخزون، وتعديل/حذف الصور والـrollback مغطاة. lost initial-stock reply مغطى بالمحاكاة؛ لا تُخلط بذلك صورة fake filesystem مع خدمة Java |
| 2 | زائر وعميل ومدير وعميل آخر، cart/order/product وملكية المورد | authorization/mutation/notification suites؛ live 1/3/4؛ HTTP Kernel حقيقي مع هوية اختبار | 401/403/404 وفق العقد. اختبارات Kernel تعيّن مستخدم الاختبار، ولا تدّعي اختبار JWT عبر شبكة خارجية |
| 3 | checkout صحيح وسلة فارغة وكمية تفوق المخزون | CheckoutRecovery وOrderStatusHistory؛ live 3/4/5 | 201 للنجاح و422 للفارغة و409 للرفض؛ الرفض ينظف intent؛ الحجز Java حقيقي في live |
| 4 | نفذ ReserveStock فعلياً ثم ارمِ خطأ lost reply وأعد المحاولة | live 4؛ Java replay/concurrency؛ CheckoutRecovery mock | نفس reservation_id وأثر مخزون واحد، والطلب واحد. الحقن بعد اكتمال RPC؛ ليس قياس deadline أو proxy شبكي |
| 5 | افشل Order::created بعد reserve، تحقق rollback ثم orders:recover-checkouts مرتين | live 5، CheckoutRecovery | الحجز يبقى مع intent دائمة؛ لا order/history/notification من المعاملة الفاشلة؛ تعافٍ دون حدث مكرر |
| 6 | release/commit فعليان ثم فقد الرد، retries وانتقالات ممنوعة | live 4/5؛ OrderCancellation؛ Java repeatedFinalization | إلغاء متكرر دون تحرير مرتين، shipped retry دون خصم مرتين، وتاريخ محلي لا يثبت عند الفشل؛ لا إلغاء بعد الشحن |
| 7 | lifecycle/rollback/replay، وقارن history وorder_notifications | OrderStatusHistory/OrderNotifications/PushDelivery؛ live 4/5 | التسجيل المحلي وعدم التكرار مثبتان. push خارجي معطل؛ Firebase تستعمل HTTP fake ولا يوجد إرسال حقيقي |
| 8 | 200 ثم 304 فارغ، حقول وصورة وتصنيف ومخزون وصفر، Java unavailable، weak/multiple/*، feature off؛ ثم التداخل مع lag | ProductEtagTest؛ live 2؛ اختبار MySQL الجديد | الحالات المنفردة ناجحة؛ حالة lag لها الحكم المبين في F01 أدناه. لا تساوي ETag ضمان freshness لمصدر قديم |
| 9 | get/put faults ثم recovery؛ احتفظ بـRedis قديمة وحوّل منفذ cache إلى loopback مغلق أثناء mutation وGET ثم أعده | CatalogCacheFailure؛ MySQL real Redis مع fault adapter؛ live 6 connection refusal فعلية | استجابة من primary، وزيادة generation دائمة، ولا رجوع لبيانات Redis القديمة بعد التعافي. لا FLUSHDB ولا توقف Redis المستخدم |
| 10 | bootstrap من قواعد بلا جداول؛ ثم upgrade للفهرس وجدول cache state مع fixture موجودة وقارن counts | ops 1/3/5؛ live 6؛ PostgreSQL round-trip | migrate دون seed؛ الحفاظ على بيانات fixture وفهارس وقيود/sequences. down/up الاختباري داخل موارد المهمة فقط |
| 11 | أوقف replica SQL_THREAD؛ POST/PATCH ثم GET مستقل للعميل والمدير والسلة/history؛ workers وتعبئة كاش متزامنة؛ ثم detail+ETag | MySQL suite؛ اتصاﻻ SQLite في ReadSourceConsistency لا يحلان محلها | الطلبات والسلة وقوائم الكتالوج تنجح. detail+ETag لها F01؛ لا إعلان read-your-writes شامل |
| 12 | barrier لخيطين Java بحجزين مختلفين على total=1، 20 تكراراً؛ وكذلك replay متزامن لنفس المعرف | ReservationIdempotencyTests على PostgreSQL 16 حقيقية | فائز واحد لكل تكرار، وحجز واحد وavailable=0؛ لا مولد بيانات ولا حجوزات اصطناعية مستخدمة كدليل Java |

تفاصيل المتطلبات والخطوات والنتيجة لكل مجموعة في `required-scenarios.csv`. بصمة المصدر في source-manifest؛ سجلات JUnit تسجل class/method/assertions والنتيجة الفعلية. عمليات المحاكاة محددة صراحة؛ لا يُعرض skip بوصفه PASS.

### الاختبارات الجديدة بهذه الجولة

- ProductEtagTest: يختبر المخزون صفر وweak/multiple/* والبصمة غير المطابقة وتعطيل الميزة، وتغير name/description/price/image/category/category name. يكشف إسقاط صفر أو تجاهل conditional headers أو حذف حقل من البصمة.
- Java: `differentReservationsCompetingForLastUnitCannotOversell` يختبر **معرفين مختلفين لنفس آخر وحدة**، بخلاف اختبار معرف واحد الموجود؛ و`repeatedFinalizationAndMultiProductRollbackPreserveStock` يثبت idempotent finalization وrollback لسلة متعددة المنتجات.
- `tests/Support/round4-live.php` و`scripts/qa/Run-Round4Live.ps1`: ست مجموعات عبر الخدمات الحقيقية، مع guards لهوية الموارد وتحقق JSON صريح من اكتمال جميع المجموعات، وJDK 21 محددة من java على PATH؛ ملفات runtime خارج قواعد المستخدم.
- الاختبار الجديد في CatalogOrdersMySqlTest يعبر بين تعديل الكتالوج وETag وreplica lag؛ لا يعتمد على نجاح كل ميزة منفردة.

### المحاولات الأولية وحدود التشغيل

محاولة PHPUnit الأولى فشلت لأن استبعاد robocopy لمجلد باسم storage استبعد أيضاً JWT Storage داخل vendor. أُصلح نسخ vendor وأعيدت المجموعة كاملة؛ سجلا المحاولة الأولى محفوظان ولا يحتسبان نجاحاً.

حارس live الأول افترض mysql.host بدلاً من write.host؛ Laravel عرض الخطأ دون exit code مفيد، لذلك أضيف التحقق البنيوي من JSON والعدد والـpass. محاولة لاحقة رفضت نتائج الستة الصحيحة بسبب توقع سبع مجموعات؛ صُحح عددها. ثم كشف تشغيل Java متغير JAVA_HOME موروثاً يشير إلى JDK أقدم؛ أصبحت أداة الاختبار تتحقق من javac 21 قبل إنشاء مواردها. هذه إخفاقات أداة الاختبار/بيئتها، محفوظة في live-*error، وليست نجاحاً مخفياً أو إصلاحات إنتاج.

lint أول بـPHP محمّلة extensions أنهى عملية واحدة بخروج Windows غير طبيعي رغم رسالة syntax سليمة؛ لم يُحتسب PASS. إعادة فحص الملف نجحت، ثم أُجري lint النهائية بـphp -n لفصل parsing عن unload للإضافات. Composer validate --strict وcheck-platform-reqs نجحا في النسخة المعزولة.

لم تنفذ حملة k6/adversarial كاملة عبر بوابتين أو fault relay شبكي، ولا benchmark مقارنة أو قياس stampede/خطط EXPLAIN/كلفة كتابة. ليست هذه حالات skipped ناجحة. أدلة آخر وحدة هي Java/PG المباشرة، وأدلة lost reply حقن بعد RPC حقيقية. لا يُنسب تحسن latency أو throughput إلى الإصلاحات.

### الملاحظات الإضافية من التقرير السابق

- M05: تمرير page المطبعة نفسها إلى paginate يمنع اختلاف المفتاح عن query؛ اختبار عزل الصفحات والروابط موجود. لا يدّعي التقرير حملة fuzz كاملة لكل قيم pagination.
- M06/M07/M10: عولجت ضمن صحة الإصلاحين الثاني والثالث، وغطتها اختبارات lag/التزامن/روابط عنوان الطلب الحالي.
- M11: توجد حماية للبنية العليا وبعض أخطاء restore، لكن ليس schema validation شاملة لكل attributes والعلاقات داخل payload سليمة شكلياً. هذا تقوية غير مكتملة ضد بيانات كاش معدلة خارج التطبيق، موثقة منفصلة؛ ليست دليلاً على فساد مثبت أثناء المسار الطبيعي.
- M09: bootstrap/restore تستخدمان boundaries checked الجديدة، لكن `demo/Preflight-CommitteeDemo.ps1` وبعض أدوات العرض/EXPLAIN القديمة بقيت خارج النطاق ولا تصلح بوصفها بوابة قبول؛ قد تتجاهل native child exit. استخدم Validate وأدوات QA المذكورة هنا. لا يُعرض PREFLIGHT OK القديمة كدليل جاهزية.
- M12: profile lifecycle موجود واختُبر تشغيل العاملين والتحقق من أمر التعافي؛ push الفعلية غير مطلوبة ومُعطلة في المختبر.
- أدوات perf التي تكتب SQL مباشرة، والنسخ القديمة من وثائق النقل التي تطلب حذف replica volume أو تقبل manifest قديمة، لا تتقدم على السياسة الجديدة.

## د) خطوات التشغيل والتحقق والتنظيف

المتطلبات: Docker Linux engine وCompose v2؛ صور mysql:8.4 وpostgres:16 وredis:7-alpine وصورتا ecommerce2026-app وecommerce2026-inventory-service؛ PHP 8.4 بإضافات المشروع وComposer؛ JDK 21 على PATH؛ Maven wrapper؛ PowerShell 5.1 و7. المسار الحي يفحص تطابق أصناف Java runtime مع compiled classes من المصدر الحالي؛ اختلاف الصورة سبب فشل، لا نجاح على نسخة مجهولة.

نفذ من جذر المشروع. لا تستعمل compose الافتراضية أو ملفات env المستخدم للاختبارات. اختر EvidenceDirectory جديدة في كل إعادة تشغيل كي لا تختلط نتائج تشغيلين:

```powershell
Set-Location 'C:\Users\laith\OneDrive\Desktop\ecommerce-platform-2026'
git status --short
git rev-parse HEAD
git rev-parse MERGE_HEAD
git ls-files -u
git diff --check
$evidence = Join-Path $env:TEMP ('merge-r4-evidence-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force $evidence | Out-Null

# Copy reviewed Git-visible source, including unstaged fixes, without ignored secrets/vendor/runtime.
$repo = (Get-Location).Path
$runtime = Join-Path $env:TEMP ('merge-r4-runtime-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force $runtime | Out-Null
$prefix = 'main-app/laravel/'
git ls-files -co --exclude-standard -- main-app/laravel | Sort-Object -Unique | ForEach-Object {
    $destination = Join-Path $runtime $_.Substring($prefix.Length)
    New-Item -ItemType Directory -Force (Split-Path $destination -Parent) | Out-Null
    Copy-Item -LiteralPath (Join-Path $repo $_) -Destination $destination
}
foreach ($directory in @('bootstrap/cache','storage/framework/cache/data','storage/framework/views',
    'storage/framework/sessions','storage/logs','storage/app/public')) {
    New-Item -ItemType Directory -Force (Join-Path $runtime $directory) | Out-Null
}
# tests/bootstrap forces memory SQLite/array and rejects runtime config.
Push-Location $runtime
composer install --no-interaction --no-scripts --no-plugins --prefer-dist
composer validate --strict
composer check-platform-reqs
php vendor/phpunit/phpunit/phpunit --colors=never --log-junit "$evidence\laravel.xml"
Pop-Location

# Real lag/concurrency regression: currently expected to expose F01.
.\scripts\qa\Run-Round2Consistency.ps1 -EvidenceDirectory "$evidence\consistency"

# Live real services + current Java tests; owns and removes its Compose project.
.\scripts\qa\Run-Round4Live.ps1 -EvidenceDirectory "$evidence\live"

# Bootstrap, replication, backup/restore and partial-state retry.
.\scripts\qa\Run-Round3Integration.ps1 -EvidenceDirectory "$evidence\ops"
New-Item -ItemType Directory -Force "$evidence\pg" | Out-Null
.\scripts\qa\Test-Round3PostgresRoundTrip.ps1 -EvidenceDirectory "$evidence\pg"
New-Item -ItemType Directory -Force "$evidence\ps7","$evidence\ps51" | Out-Null
.\scripts\qa\Test-Round3Faults.ps1 -EvidenceDirectory "$evidence\ps7"
powershell.exe -NoProfile -File .\scripts\qa\Test-Round3Faults.ps1 -EvidenceDirectory "$evidence\ps51"
powershell.exe -NoProfile -File .\scripts\tests\update.Tests.ps1
powershell.exe -NoProfile -File .\scripts\tests\setup-firebase.Tests.ps1
```

قد يلزم OPENSSL_CONF الخاص بتثبيت PHP لتوليد مفاتيح اختبار Firebase؛ يضبط لعملية الاختبار فقط، دون تغيير php.ini. لا تضع credentials فعلية في إعداد الاختبارات. لا تشغل scripts/test-notifications.ps1 على الخدمات القائمة لمجرد إعادة الاختبار.

للتحقق اليدوي داخل **مشروع أداء مخصص منفصل ومملوك للتجربة**: ابدأ Initialize مع Project وEnvFile المخصصين ودون WithReplica؛ يجب أن تكون هوية PDO write=1/read=1. ثم WithReplica؛ يجب أن تصبح write=1/read=2 بعد نجاح مقارنة schema/الصفوف وreplication. تعطيل SQL_THREAD يكون على replica هذا المشروع فقط. افحص الطلبات والسلة بطلبات مستقلة، ثم ETag تفاصيل المنتج بعد PATCH؛ اختبار F01 هو بوابة القبول المطلوبة لهذه الحالة.

الكاش: سخّن القائمة، ثم عدّل واحذف، وتحقق من generation داخل SQL ومن مفاتيح s2 الجديدة ومن counts/links. أثناء outage يجب أن تعمل القراءة من primary؛ بعد التعافي لا يُعاد payload الجيل القديم. ETag: GET واحفظ header، ثم GET If-None-Match؛ 304 body فارغ، وبعد تغير أي حقل أو مخزون يجب أن تحصل على 200 وبصمة جديدة، ما دام مصدر تفاصيل المنتج حديثاً. فشل Java يجب أن يسبق حساب ETag ويعيد 503.

الاستعادة: backup جديدة بmanifest v2، ثم Restore إلى مشروع فارغ فقط دون AllowOverwrite. أي schema موجودة تستلزم العلم الصريح في هذا المشروع التجريبي. فشل staging أو مقارنة أي قاعدة أو native command يجب أن يعيد فشلاً دون RESTORE OK. تحقق من journal ومن fingerprint القاعدتين وقيود/sequences قبل إعادة الكتّاب. بعد النجاح تبقى replica بحاجة reseed، وتُستخدم namespace كاش جديدة. لا تختبر Restore على مشروع المستخدم.

تنظف harnesses مشاريعها في finally بعد فحص labels والـvolumes. للإزالة اليدوية بعد انقطاع harness: حدّد اسم المشروع من cleanup.json أو سجل التشغيل، وافحص IDs وlabels وملكية كل volume؛ ثم استخدم نفس -p/-f/--env-file الخاصين **بذلك المشروع فقط** وdown -v --remove-orphans. لا تستخدم docker volume prune أو down -v على المشروع الأصلي. لا يكفي تشابه الاسم لإثبات الملكية.

**حالة التنظيف الفعلية:** ترد في `cleanup-final.json`. أزيلت موارد Docker للمجموعات المكتملة؛ لا تنسب هذه النتيجة إلى حذف مجلدات القرص التي رفضتها المراجعة الآلية. ملفات الأدلة الدائمة مقصودة للاحتفاظ؛ أما نسخ التطبيق وfixture directories وpgdata القديمة المتبقية فهي بقايا مؤقتة يجب إزالتها يدوياً بعد مراجعة القائمة المحددة هناك. مجلدات اختبارات مؤرخة 11 نوفمبر لم تُمس لأنها لا تخص هذه المهمة.

## هـ) الحكم النهائي وشروط الجاهزية

**غير جاهز.** لا يبرر نجاح مجموعات كثيرة تجاوز regression فاشلة أو الادعاء بانتهاء التنظيف.

### الموانع

**F01 — تفاصيل المنتج وETag تحت replica lag:** الفعلي **304** مقابل المتوقع **200**، واختبار المجموعة النهائية فاشل؛ الدليل في consistency/mysql-integration.log/xml، عند CatalogOrdersMySqlTest.php:392. السبب في ProductService::findById: query المنتج وعلاقة category غير مثبتتين على primary؛ تحديث القوائم وجيلها لا يغير هذا المسار. يوقف الاختبار SQL_THREAD بعد مزامنة fixture، ويحفظ ETag، وينفذ update ملتزمة على primary ثم GET مستقلة بالبصمة القديمة. أثبت الاختبار أولاً أن الاسم الجديد موجود على primary والاسم القديم ما زال على replica؛ فلا يُنسب الفشل إلى عدم تنفيذ التعديل. يجب أن يعيد 200 مع الاسم والبصمة الجديدين؛ الرد القديم يخرق read-your-writes ويجعل التحقق الشرطي يخفي التعديل. إصلاح مقترح للمراجعة التالية: سياسة primary موضعية للأب والتصنيف في هذا المسار، أو سياسة causal read موثقة، ثم إعادة الاختبار واتحاد الاختبارات. لم أعدّل هذا السلوك في الجولة النهائية لأن صلاحيتها محصورة باستكمال الاختبارات وتنظيم الحالة.

**F02 — بقايا ملفات مؤقتة:** المراجعة الآلية رفضت حذفها حتى بعد التحقق من المسارات ومن عدم تشغيل executables منها، وأعادت blocked by policy دون تفسير إضافي. لا يمكن تأكيد شرط عدم بقاء بيانات مؤقتة. قواعد الاختبار وحاويات المهمة النشطة حالة منفصلة مثبتة في جرد الموارد؛ توجد pgdata متوقفة من الجولة الأولى على القرص ضمن قائمة البقايا. لا أتجاوز الرفض عبر shell أو أداة أخرى.

| شرط الجاهزية | الحكم |
|---|---|
| 1. التعارضات محلولة دون إسقاط وظائف الطرفين | مستوفى ضمن مقارنة المصادر والاختبارات الأصلية؛ لا تعارضات index |
| 2. الإصلاحات الخمسة مثبتة باختبارات مناسبة | الدمج وbootstrap/restore والطلبات والقوائم مثبتة؛ التقاطع product detail/ETag/lag يحتاج F01 قبل ضمان الاتساق الشامل |
| 3. اختبارات الطرفين ناجحة على الناتج | الاختبارات الأصلية تنجح؛ اتحاد regression النهائي يتضمن F01 فاشلة، لذلك البوابة النهائية غير مستوفاة |
| 4. السيناريوهات الحرجة على خدمات حقيقية | منفذة بأدلة؛ نتيجة الاتساق ليست كلها ناجحة. لا تُعد المحاكاة بديلاً عن PostgreSQL أو replication |
| 5. لا نجاح كاذب في bootstrap أو restore | مستوفى بالأخطاء المحقونة والـround-trip الحقيقي؛ أدوات العرض القديمة مستثناة وممنوعة كبوابة قبول |
| 6. لا تعديل أو فقد لبيانات المستخدم | الموارد المعزولة وملفات env/index وحاويات المستخدم محمية؛ لا migrations أو FLUSHDB على خدماته. لا يدعي التقرير fingerprint شاملة لبيانات خدمات المستخدم المتغيرة بمرور الوقت |
| 7. الاستثناءات غير الحرجة موثقة | مستوفى: push الفعلية والقياسات وحملة adversarial الأوسع وM11 وأدوات العرض القديمة وحدود filesystem/restore؛ التنظيف متبقٍ صريح لا استثناء مخفي |

لم يُنشأ commit أو push. المطلوب قبل إعادة الحكم: معالجة F01 ضمن تفويض تعديل كود الإنتاج، وإعادة تشغيل المجموعة النهائية دون failure، وإزالة البقايا المملوكة للمهمة الممنوعة آلياً والتحقق من الجرد. لا توجد نسبة تحسن أداء جديدة في هذا التقرير.
