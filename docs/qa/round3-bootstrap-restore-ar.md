# تقرير الجولة الثالثة: تهيئة بيئة الأداء وتقوية النسخ والاستعادة

التاريخ: 13 نوفمبر 2026، بتوقيت دمشق. الفرع: `codex/integrate-requirement-3-performance`.
النطاق: الإصلاحان الرابع والخامس فقط. لم أنشئ commit أو push، ولم أغيّر الفرع أو أكرر merge/fetch، ولم أغيّر index أو تعديلات Laravel السابقة.

**النتيجة النهائية: نجح الإصلاحان ضمن نطاق الجولة. نجحت 15 حالة تكامل حقيقية، و46 حالة حقن أخطاء على كل من PowerShell 5.1 و7، و5 فحوص native فعلية على 5.1، و3 فحوص PostgreSQL حقيقية. نجح parsing لـ24 سكربتاً على PowerShell 5.1.**

## الملفات والتغيير الوظيفي

- `docker-compose.perf.yml`: primary هو read host الافتراضي، ويمكن اختيار replica فعلياً عبر interpolation؛ أسماء الموارد والمنافذ وصور الاختبار وملف البيئة قابلة للعزل. أضيف profile اسمه lifecycle يشغل checkout-recovery وnotification-worker، مع ORDER_PUSH_DRIVER=disabled صراحةً.
- `main-app/laravel/.env.perf.example`: تصحيح read host الافتراضي إلى mysql. ملف .env العادي وملف .env.perf الموجود لم يُعدّلا أثناء الاختبارات.
- `scripts/perf/Perf.Common.ps1`: تنفيذ native checked صالح لـPowerShell 5.1، نقل bytes عبر streams، UTF-8 صريح، timeout، هوية الحاويات والـvolumes والقاعدتين، readiness حقيقي، اكتشاف وإيقاف الكتّاب، وتحديث ملف perf فقط.
- `Initialize-PerfEnvironment.ps1` و`Validate-PerfEnvironment.ps1`: ترتيب bootstrap والتحقق من PDO القراءة والكتابة وschema وSQL وRedis وgRPC وHTTP والعامل المناسب.
- `replica/Initialize-MysqlReplica.ps1`: snapshot مع binlog coordinates، استكمال قناة موجودة، رفض schema جزئية دون طلب reseed صريح، مطابقة المخطط والصفوف قبل تفعيل القراءة، وعودة آمنة إلى primary عند فشل التفعيل.
- `transfer/Transfer.Common.ps1` و`Backup-PerfDataset.ps1` و`Restore-PerfDataset.ps1`: manifest v2 إلزامي، checksums، staging، بصمات كاملة للقاعدتين، pre-state backups وjournal، ومنع رسالة النجاح عند فشل أي مرحلة أو cleanup.
- أضيفت أدوات QA للجولة الثالثة تحت scripts/qa وأدلة منزوعة الأسرار تحت docs/qa/round3-evidence.

## الإصلاح الرابع: السبب الجذري والسياسة الجديدة

كان Compose يفرض mysql-replica، بينما profile الخاص بها غير مشغّل افتراضياً. تعديل .env.perf وحده لا يتغلب على environment الصريح في Compose. ثم كان bootstrap ينفذ SELECT عبر read PDO قبل migrations، وكانت تهيئة replica تتطلب 200 ألف طلب حتى على قاعدة جديدة.

الترتيب الجديد:

1. اختيار mysql وحفظه في ملف perf، وتوليد مفاتيح التطبيق في هذا الملف دون طباعتها.
2. بدء MySQL وPostgreSQL وRedis في المشروع المعزول؛ انتظار SELECT ناجح عبر TCP. الاتصال عبر socket وحده قد ينجح أثناء خادم التهيئة المؤقت داخل صور قواعد البيانات، ولذلك لم يعد دليلاً كافياً.
3. فحص project/service labels وملكية volumes، واسم القاعدة وserver_id وread_only وهوية cluster. التطبيق نفسه يثبت أن PDO القراءة والكتابة يشيران إلى primary.
4. مسح config cache في volume الأداء وتشغيل migrate --force فقط، دون seed أو migrate:fresh أو مسح بيانات.
5. تشغيل Laravel وJava. عند طلب replica: إيقاف الكتّاب والقراء، dump متسق مع coordinates، seed إلى replica المثبتة الهوية فقط، تشغيل replication، انتظار IO/SQL وlag=0، وتفعيل read_only وsuper_read_only.
6. مقارنة بصمة schema وكل صف وقيود FK بين primary وreplica؛ ثم تغيير read host وإعادة إنشاء التطبيق والتحقق بهوية PDO الفعلية.
7. تشغيل profile lifecycle عند طلبه؛ فحص أمر schedule:work وتشغيل orders:recover-checkouts فعلياً داخل عامل التعافي. لا يُرسل Firebase حقيقي.

Compose يستخدم DB_READ_HOST عبر interpolation مع default=mysql، والسكربتات تمرر --env-file صراحةً وتضبط متغير العملية أثناء التحويل. لذلك لا يقتصر الإصلاح على نص داخل .env.perf. تبقى أولوية Compose المعتادة نافذة عند الاستدعاء اليدوي: متغير shell يمكن أن يتقدم على ملف interpolation؛ يجب ألا يترك المشغّل قيمة shell مخالفة للوضع المقصود.

**دون replica:** تشغيل مقصود ومدعوم، والقراءة والكتابة server_id=1.
**مع replica:** التحويل لا يحدث قبل اكتمال التحقق، والقراءة server_id=2 والكتابة server_id=1.
**إذا تعطلت replica بعد التفعيل:** لا يوجد fallback صامت. القراءة التي تحتاج اتصال replica تفشل، وValidate يفشل. القراءات الموجهة أصلاً إلى primary والكاش قد تتصرف بحسب مسارها. الرجوع المقصود يكون بإعادة Initialize دون -WithReplica، مع الحفاظ على البيانات.
**إذا فشل recreate أثناء التفعيل:** يُحفظ اختيار primary وتُحاول إعادة إنشاء التطبيق عليه. وإذا فشل ذلك أيضاً، يُوقف التطبيق ويُعاد الخطأ، مع cleanup واستعادة متغير العملية في finally.
**إذا انقطعت التهيئة:** migrate العادية قابلة لإعادة التشغيل. قناة replication المكتملة تُستأنف دون dump جديد؛ schema جزئية تستلزم -ReseedReplica صراحةً. لا يُحذف أي volume. الخيار القديم -ResetReplicaVolume أصبح رفضاً واضحاً.

## الإصلاح الخامس: السبب الجذري والتحقق

النسخة السابقة حوّلت خطأ count أو parsing إلى صفر، ولم تفحص عدة exit codes، وقبلت checksums اختيارية، وقرأت SQL كاملة إلى الذاكرة، واستخدمت ملفات tmp مشتركة، ثم طبعت RESTORE OK اعتماداً على orders رغم إمكان فشل PostgreSQL.

أصبح القرار fail-closed:

- غياب الجداول حالة SQL ناجحة مستقلة: COUNT من metadata = 0. فشل الاتصال أو الصلاحيات أو SQL أو parsing ليس صفراً.
- وجود أي schema مستخدم في إحدى القاعدتين يستلزم -AllowOverwrite، حتى إذا كانت orders فارغة أو غير موجودة. العلم لا يتجاوز فشل التحقق.
- هوية الوجهة تشمل labels وملكية volumes واسم القاعدة وserver_id/read_only وcurrent_user وPostgreSQL recovery state وUUID/system identifier. يعاد فحصها قبل التدمير، ويُرفض تبدل cluster أثناء العملية.
- manifest v2 يتطلب ملفي SQL.gz وPostgreSQL custom dump وبصمتي القاعدتين وSHA-256 لكلا الملفين. تُرفض المسارات المطلقة والتجاوز .. وADS والفواصل وأسماء الملفات المكررة والروابط، ولا تُقبل ملفات خارج الأسماء المحددة فيه.
- التحقق من الملفات والـchecksums وفك الضغط يسبق الاستيراد. المسارات ذات المسافات وUnicode تستخدم argument quoting وbytes/UTF-8، دون shell interpolation لاسم ملف manifest.
- كل docker exec/cp وdump/import/pg_restore وcleanup native يمر عبر process boundary يفحص exit code. لا يعتمد ذلك على ErrorActionPreference وحده. ضغط وفك ضغط .NET يستعمل streams واستثناءات checked.
- tmp فريد مع ownership marker؛ cleanup لا يقبل مجلدًا غير مملوك، ولا يستخرج إلى TEMP العام أو يقرأ dump كاملة عبر Get-Content -Raw.
- تُستعاد القاعدتان أولاً إلى قواعد staging فريدة وتُفحصان قبل استبدال القاعدتين الحيتين.
- البصمة تشمل كل جدول وصفوفه وأعدادها وتعريفاته وفهارسه وقيوده. يُفحص orders_created_at_index وFK orphans في MySQL، وقيود المخزون والحالات وunique keys وحالة sequences في PostgreSQL. إضافات schema غير المدعومة تُرفض بدلاً من تجاهلها.
- hashes الصفوف SHA-256 مرتبة وتُكتب كـstream، ثم تُحسب بصمة الملف؛ لا تُطبع بيانات الصفوف أو الأسرار.
- طبّعت المقارنة فرقين مثبتين بالتنفيذ: charset صريح مقابل ضمني في MySQL مع بقاء COLLATE؛ وصيغتين محددتين فقط لتحويل varchar/text داخل قيد حالات الحجز في PostgreSQL 16. تغيير قائمة الحالات أو القيد ليس مطبعاً ويُرفض.
- لا يوجد حد 200k بديلاً عن التحقق. العدد الكبير القديم لا يثبت نجاح استيراد أي قاعدة.

**توافق الأرشيفات:** نسخ السكربت القديم التي لا تحتوي manifest v2/both fingerprints/checksums تُرفض عمداً، حتى مع -AllowOverwrite. أنشئ backup جديداً بالأداة الجديدة؛ لا تضف metadata تخمينية إلى dump قديمة. git_commit في manifest يصف HEAD فقط، وworking_tree_dirty يوضح أن نسخة العمل الحالية قد تكون مطلوبة؛ لم يُنشأ commit بهذه الجولة.

## سياسة الكتّاب وsnapshot والاستعادة الجزئية

الأداتان تكتشفان جميع خدمات مشروع Compose المقصود، وتوقفان الخدمات الأخرى غير MySQL/PostgreSQL/Redis قبل snapshot أو الاستيراد؛ يشمل ذلك التطبيق وJava والعمال وreplica. تتحققان من الملكية قبل stop ومن الحالة بعده. المخزون غير القابل للتحليل، والحالات غير المعروفة/paused/removing، تُرفض. تستوقف الخدمة restarting كذلك.

يجب أن تبقى **كل البرامج الكاتبة الخارجية وDDL منفصلة طوال النافذة**. السكربت يدير خدمات Compose، ولا يمكنه منع مشغّل خارجي من الاتصال لاحقاً. اتساق snapshot معتمد على هذه السياسة، وليس على ادعاء snapshot موزع بين المحركين.

بعد backup ناجح تُستأنف الخدمات التي كانت تعمل. وإذا كانت replica بينها، تبدأ قناة replication وتلحق بالمصدر قبل إعادة القراء.

سياسة restore:

1. توقف الكتّاب، ثم تحقق staging لكلتا القاعدتين.
2. احفظ dumps لحالة القاعدتين قبل الاستبدال في RecoveryDir جديد لا يُحذف تلقائياً.
3. اكتب RESTORE-STATE.json محدداً المرحلة والمشروع والأرشيف والخدمات وخطة إعادة المحاولة.
4. أعد فحص هوية الوجهة، ثم استبدل MySQL وتحقق منها، ثم PostgreSQL وتحقق منها. pg_restore يستخدم --exit-on-error و--single-transaction.
5. الاستعادة ليست atomic transaction موزعة: DDL في MySQL لا يُتراجع عنه، ومسح schema PostgreSQL يقع قبل معاملة pg_restore المحلية. قد تنجح MySQL وتبقى PostgreSQL قديمة أو فارغة عند فشل لاحق.
6. عند الفشل تبقى الخدمات الكاتبة متوقفة، ولا تظهر RESTORE OK. لا تشغّل التطبيق على حالة جزئية. أعد المحاولة من **نفس الأرشيف المتحقق منه** مع -AllowOverwrite وRecoveryDir جديد؛ يعاد بناء القاعدتين والتحقق منهما. للاحتفاظ بالحالة السابقة بدلاً من التقدم، احتفظ بزوج pre-state dumps واستعد الاثنين مع إعادة فحص الهوية وإبقاء الكتّاب متوقفين؛ ليست هذه إعادة rollback آلية مضمونة.
7. بعد نجاح القاعدتين وcleanup تُستأنف الخدمات الأصلية مع primary read host. replica تبقى متوقفة إلى أن تعاد تهيئتها. يُنشأ CACHE_PREFIX جديد وتُمسح config cache عند وجود كتّاب لتجنب كاش كتالوج قديم، دون FLUSHDB أو حذف مفاتيح Redis قائمة.
8. فشل cleanup أو استئناف خدمة يعني فشل الأمر، حتى إن كانت البيانات قد استُعيدت؛ سجل المرحلة يوضح ذلك. النجاح النهائي يأتي بعد كل هذه الخطوات.

pre-state dumps وjournal مخرجات تعافٍ مقصودة، وليست tmp قابلة للحذف العشوائي. في اختبارات هذه الجولة وُضعت جميعها داخل جذر الاختبار المملوك ثم أزيلت بعد التحقق.

## الأدلة وحدود ما اختُبر

- `round3-evidence/ps51/fault-results.json` و`ps7/fault-results.json`: حقن أخطاء في orchestration الأصلي، مع نموذج صريح لحدود الأدوات/البيانات. ليست هذه وحدها اختبارات قواعد حقيقية، وتتحقق أيضاً من غياب رسالة النجاح عند الفشل.
- `round3-evidence/integration-results.json`: تنفيذ فعلي على Docker/MySQL 8.4/PostgreSQL 16/Redis/Java/Laravel بموارد فارغة وأسماء perf-r3 عشوائية ومنافذ ديناميكية وvolumes وشبكات مستقلة.
- `round3-evidence/native-ps51.json`: PowerShell 5.1 فعلي، exit 47، فشل authentication، Unicode/مسافات/quotes، ونقل bytes متطابق.
- `round3-evidence/pg-roundtrip-results.json` وpg-fingerprint-before/after: PostgreSQL حقيقية؛ مطابقة المخطط والصفوف والـsequences، ورفض قيد حالات متغير وقيد كميات ناقص.
- `round3-evidence/fixture-manifest.json`: بصمات وأعداد fixture فقط، دون dumps أو كلمات مرور.
- `round3-evidence/integration-cleanup.json` و`scratch-cleanup.json` و`final-resource-inventory.json`: ملكية وتنظيف موارد الاختبار.
- `round3-evidence/parser-ps51.json`: parsing فعلي لـ24 سكربتاً؛ `final-summary.json`: ملخص الأعداد النهائية.
- `round3-evidence/fixture-identity.json`: هوية المصدر ومخرجات fixture المعروفة، دون أسرار.

fixture الحقيقية: مستخدم وتصنيف ومنتج باسم عربي وسعر 12.50، وطلب بقيمة 25 وعنصر بكمية 2، ومخزون total=10/reserved=2 وحجز ACTIVE بكمية 2. assertions صريحة تتحقق من النص العربي والسعر والمخزون والحجز، بجانب بصمات جميع الجداول.

لم أنشئ dataset فعلية من 200 ألف طلب لهذه الجولة؛ حالة العدد القديم 200000 ممثلة في fault injection بمخرجات بصمة مختلفة وتفشل المقارنة. الـround-trip الحقيقي يستعمل fixture صغيرة معلومة، وليس benchmark. جرى تشغيل عامل التعافي وفحصه فعلياً، دون ادعاء اختبار كل lifecycle checkout/Firebase من جديد.

## مصفوفة الاختبارات الإلزامية للإصلاح الرابع

I = حالة حقيقية في integration-results. F = حالة حقن أخطاء، منفذة على PowerShell 5.1 و7.

| المطلوب | الدليل وطريقة التحقق |
|---|---|
| موارد فارغة تماماً | I: fresh-empty-owned-resources؛ metadata صفر في القاعدتين قبل التهيئة |
| دون replica profile | I: fresh-bootstrap-without-replica-and-workers؛ لا حاوية replica وهوية 1/1 |
| تهيئة replica والتحويل | I: initialize-replica-then-activate-read-identity؛ PDO write=1/read=2 |
| replica غير مهيأة/schema ناقصة | I: uninitialized-incomplete-replica-refused ثم -ReseedReplica؛ F: replica-missing-schema |
| تأخر جاهزية DB | انتظار SQL عبر TCP على قواعد جديدة؛ F: delayed-readiness/readiness-timeout/nonboolean-output |
| فشل migration/init/replication | F: bootstrap-migration/config clear/database startup/build، وreplica dump/import/replication-configure |
| فشل recreate بعد التحويل | F: replica routing recreate وroute-and-fallback؛ حفظ primary وإيقاف التطبيق عند فشل المحاولتين |
| إعادة تشغيل مع بيانات | I: restart-preserves-fixture-and-resumes-initialization |
| استكمال تهيئة انقطعت | I: migrations/restart + إعادة تجهيز schema جزئية؛ F: أخطاء المراحل ثم إعادة التنفيذ |
| تعطل replica مفعلة | I: replica-outage-fails-closed ثم explicit-primary-fallback-preserves-fixture |
| هوية القراءة والكتابة | فحص PDO الحقيقي في Validate، وليس env فقط؛ 1/1 ثم 1/2 |
| عدم الاتصال بـdemo أو تعديلها | كل SQL إلى task-owned projects؛ فحص hashes .env/index/HEAD ومقارنة container IDs في I: demo-environment-and-git-index-unchanged |

## مصفوفة الاختبارات الإلزامية للإصلاح الخامس

| المطلوب | الدليل وطريقة التحقق |
|---|---|
| وجهة فارغة صحيحة | I: real-round-trip-into-empty-resources؛ F: empty-valid |
| غير فارغة دون overwrite | I: real-nonempty-destination-refused؛ F: nonempty-no-overwrite |
| غير فارغة مع السماح | I: real-overwrite-and-retry-after-partial-state؛ F: nonempty-allow-overwrite |
| فشل count/parsing | F: count-query-fails/count-parsing-fails؛ لا DDL حي |
| credentials/permissions غير صحيحة | F: invalid-credentials/wrong-identity؛ اختبار authentication حقيقي في native-ps51 |
| manifest مفقودة/غير صالحة | F: missing-manifest/invalid-manifest |
| ملف ناقص/checksum خاطئة | F: missing-file/checksum-mismatch/manifest-missing-checksum |
| dump/decompression تالفة | F: decompression؛ وفشل pg_restore متحقق منه كـnative failure |
| فشل MySQL import | F: mysql-import، قبل استبدال القواعد الحية |
| MySQL تنجح ثم cp/pg_restore تفشل | F: postgres-copy/staged-pg-restore؛ وmysql-live-success-then-pg-fails بعد MySQL الحية |
| PG جزئية أو validation تفشل | F: pg-post-validation؛ فحص schema/قيود حقيقي في pg-roundtrip-results |
| 200k قديم لا يخفي الفشل | F: old-200k-cannot-hide-failure؛ fingerprint mismatch فعلي على كائن count=200000 |
| فشل cleanup ولا نجاح كاذب/حذف أجنبي | F: cleanup-fails/cleanup-rejects-unowned وbackup-dump cleanup |
| Unicode ومسافات | I: أسماء ملفات عربية ومسار أرشيف بمسافات؛ native-ps51 bytes/UTF-8 |
| إعادة المحاولة بعد جزئية | I: تعديل PostgreSQL وحدها بحذف الحجوزات ثم restore الزوج مع AllowOverwrite والتحقق |
| round-trip حقيقي للقاعدتين | I: backup ثم restore إلى مشروع جديد، all-table fingerprints وFK/constraints/sequences وassertions fixture |
| أخطاء backup نفسها | F: MySQL dump/PostgreSQL dump/PostgreSQL dump copy/dump cleanup/resume backup writer |
| سياسة الكتّاب | F: رفض inventory غير قابل للتحليل وخدمة paused؛ I: إيقاف العمال والتطبيق وJava واستئنافهم في المصدر |

## إعادة التشغيل

من جذر المشروع، على بيئة الأداء المقصودة فقط:

```powershell
# primary فقط، دون seed:
.\scripts\perf\Initialize-PerfEnvironment.ps1 -WithLifecycle

# primary + replica، والتحويل بعد التحقق:
.\scripts\perf\Initialize-PerfEnvironment.ps1 -WithReplica -WithLifecycle

# فقط إذا كانت replica المقصودة جزئية وتريد إعادة تجهيزها:
.\scripts\perf\replica\Initialize-MysqlReplica.ps1 -ReseedReplica

.\scripts\perf\transfer\Backup-PerfDataset.ps1 -OutDir 'مسار جديد للنسخة'
.\scripts\perf\transfer\Restore-PerfDataset.ps1 -BackupDir 'مسار النسخة'
# وجود schema بعد migrate يستلزم السماح الصريح:
.\scripts\perf\transfer\Restore-PerfDataset.ps1 -BackupDir 'مسار النسخة' -AllowOverwrite

# مجموعات QA تنشئ مواردها الخاصة وتزيلها:
.\scripts\qa\Test-Round3Faults.ps1 -EvidenceDirectory '.\docs\qa\round3-evidence\ps7'
powershell.exe -NoProfile -File .\scripts\qa\Test-Round3Faults.ps1 -EvidenceDirectory '.\docs\qa\round3-evidence\ps51'
.\scripts\qa\Run-Round3Integration.ps1
.\scripts\qa\Test-Round3PostgresRoundTrip.ps1
```

وثيقة النقل القديمة التي تقترح -ResetReplicaVolume أو قبول archive بلا بصمات v2 لا تصف السياسة الجديدة؛ استخدم الأوامر أعلاه.

## ملاحظات التطوير والنتيجة النهائية

كشف التنفيذ الحقيقي، قبل الاعتماد، صورة Laravel محلية باعتماديات أقدم، وتعارض اسم متغير داخل readiness، واختلافَي صياغة schema بعد dump/reload، واستجابة socket أثناء bootstrap المؤقت. أُصلحت هذه الحالات وأعيد التحقق. أحد مدخلات fixture الأولية افترض عموداً غير موجود؛ صُحح الاختبار دون تغيير schema التطبيق.

اكتملت المجموعة الحقيقية النهائية: 15/15، بما فيها إعادة الاستعادة مع AllowOverwrite بعد حالة PostgreSQL جزئية. مجموعتا fault injection: 46/46 لكل runtime؛ لا يُعامل تكرار السيناريو على runtime آخر كحالة فريدة جديدة. فحوص native: 5/5؛ PostgreSQL المعزول: 3/3؛ syntax: 24/24 دون أخطاء.

فحص final-resource-inventory.json النهائي: صفر حاويات وvolumes وشبكات ومجلدات مؤقتة لهذه الجولة. أزيلت كذلك موارد محاولات التطوير، وبقيت فقط ملفات الشيفرة والتقرير والأدلة المقصودة. خدمات demo الست ظلت تعمل؛ لم تُوجّه إليها أي أوامر SQL أو Redis. تحقق اختبار الهوية من ثبات ملفات البيئة وindex وHEAD وMERGE_HEAD، ولم يحدث commit/push أو تغيير فرع.

الـround-trip الحقيقي الأول كشف اختلاف صياغة قيد PostgreSQL وتوقف في staging قبل الاستبدال؛ يحتفظ integration-before-pg-canonicalization.json بهذه النتيجة التاريخية. المرجع النهائي للنجاح هو integration-results.json، وتحتوي جميع حالاته النهائية على pass=true.

الأرشيفات مدخلات موثوقة: checksums تكشف التلف ولا تمثل توقيعاً ضد مهاجم يبدل dump وmanifest معاً. قواعد staging موجودة داخل خادمي الأداء المقصودين؛ ليست sandbox لتشغيل SQL عدائية.
