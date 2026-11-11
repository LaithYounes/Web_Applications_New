# تسليم إشعارات الطلبات لفريق Android

الباك يتولى حفظ الإشعارات وإرسالها عبر Firebase. جميع عناوين الإشعارات ونصوصها بالإنكليزية. التطبيق يتولى تسجيل الجهاز وعرض رسائل data-only. المصادقة تبقى JWT.

## عند أول سحب لهذه الميزة

شغّل Docker Desktop. من مجلد المشروع، بعد حفظ أي تعديلات محلية:

```powershell
.\update.cmd
```

هذا الأمر يسحب التحديث، يثبت dependencies، يطبق migrations ويشغل التطبيق والمخزون وnotification-worker. إذا كانت هذه أول نسخة للمشروع، أكمل إعداد README أولاً.

لإعداد Firebase **مرة واحدة على كل كمبيوتر**، احصل على ملف Service Account بشكل خاص ثم نفّذ، مع تغيير المسار:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/setup-firebase.ps1 -CredentialsPath "C:\path\firebase-service-account.json"
```

الأداة تفحص OAuth وصلاحية FCM بطلب validate_only لا يسلّم أي رسالة، ثم تنسخ الاعتماد خارج المستودع إلى مجلد المستخدم، وتولد docker-compose.override.yml محلياً وتعيد تشغيل الخدمات المطلوبة. لا تعدّل .env ولا ترفع المفتاح إلى Git. إذا وجد override مخصص مسبقاً، تحفظه وتتوقف برسالة تشير للتكامل اليدوي في [التوثيق التقني](order-notifications.md).

بعد ذلك يكفي **update.cmd** للتحديثات التالية؛ Docker يقرأ الإعداد المحلي تلقائياً. لا يحتاج زميلك إلى مسارات ملفات كمبيوترك ولا APP_KEY/JWT_SECRET الخاصين بك. كل نسخة باك تحتفظ بمفاتيحها المحلية.

فحص الإعداد في أي وقت، دون إرسال إشعارات:

```powershell
docker compose exec -T app php artisan notifications:check-firebase
```

نجاح هذا الفحص يثبت صلاحية اعتماد الباك وطلب FCM التجريبي. ظهور الرسالة على الهاتف يحتاج التجربة على تطبيق Android أيضاً.

## ما يطبقه Android

1. بعد login، استخرج FCM registration token من تطبيق Android المرتبط بنفس مشروع Firebase. أنشئ installation UUID ثابتاً للتثبيت، وسجّل token عبر `PUT /api/v1/notification-devices/{installation_id}` مع JWT و`platform: android` و`expected_revision: 0` أول مرة. احفظ revision المسترجعة.
2. حدّث التسجيل عند تغيّر FCM token أو جلسة login، باستخدام آخر revision مؤكدة. نفّذ تحديثات الجهاز بالتسلسل ولا تعاود إرسال token قديم على revision أحدث. كل جهاز يسجل نفسه تلقائياً؛ لا حاجة لإرسال tokens يدوياً لفريق الباك.
3. استقبل رسائل data-only؛ اقرأ title/body واعرض إشعاراً محلياً، مع التعامل مع إذن الإشعارات والخلفية. امنع التكرار باستخدام notification_id قبل العرض.
4. عند الضغط، افتح order_id واجلب تفاصيله باستخدام JWT الحالي. اعرض القائمة من GET notifications والعدد من GET notifications/unread-count. علّم القراءة عبر PATCH notifications/{id}/read أو PATCH notifications/read-all.
5. نفّذ POST auth/logout قبل حذف JWT. يفصل الباك تسجيلات جلسة JWT الحالية تلقائياً؛ الأجهزة ذات الجلسات الأخرى تبقى فعالة. افصل الحساب السابق قبل تسجيل حساب جديد على الجهاز نفسه، وامسح إشعاراته المحلية.
6. للفصل الصريح استخدم DELETE notification-devices/{installation_id} مع expected_revision. احفظ revision الجديدة من الاستجابة؛ الفصل يرفعها لمنع PUT متأخر من عكسه. يبقى رد logout القديم data:null كما هو.

المسارات أعلاه تحت /api/v1 وتتطلب customer JWT. تظهر الأنواع الستة للإنشاء والتأكيد والتجهيز والشحن والتسليم والإلغاء لصاحب الطلب. لا يوجد endpoint لإرسال رسائل اعتباطية.

## تجربة Postman

استورد Ecommerce.Frontend.postman_collection.json واضبط baseUrl إلى http://localhost:8000. سجّل الدخول، أضف منتجاً متوفراً للسلة وأنشئ طلباً، ثم افتح مجلد 06 Customer Notifications لتجربة القائمة والعدد والقراءة. لا يلزم تسجيل جهاز لتجربة inbox API. لا تسجل token وهمياً لتجربة push بوضع firebase؛ لتجربة وصول فعلي استخدم token الجهاز الحقيقي بعد ربطه بالحساب.

لتغيير الحالات استخدم admin JWT والتسلسل confirmed ثم processing ثم shipped ثم delivered. اختبر الإلغاء بطلب pending آخر. جرّب حساباً ثانياً للتأكد أن إشعارات الأول غير متاحة له.

تفاصيل الحقول، الاستجابات، أخطاء 401/403/404/409/422، سياسة الأجهزة، payload، وضمانات التسليم في [order-notifications.md](order-notifications.md). قبول FCM لا يثبت العرض أو القراءة، وقد تتكرر رسالة عبر الشبكة بنفس notification_id عند ضياع الرد.
