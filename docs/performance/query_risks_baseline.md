# مخاطر الاستعلامات وN+1 والفهارس — خط أساس من الشيفرة (قبل التحسين)

**الحالة:** تحليل ثابت من الشيفرة والمخطط فقط.  
**لم يُنفَّذ:** EXPLAIN حي، ولا قياس عدّ استعلامات تحت الحمل، ولا إضافة فهارس/كاش.

---

## 1. مسارات محمية من N+1 (دليل من الشيفرة)

| المسار | الدليل |
| --- | --- |
| `GET /api/v1/products` | `ProductService::getAll` يستخدم `->with('category')` |
| `GET /api/v1/products/{id}` | `findById` يستخدم `with('category')` ثم استدعاء gRPC واحد لـ `GetStock` |
| `GET /api/v1/orders` (عميل) | `OrderService::getUserOrders` يستخدم `with('items')` |
| `GET /api/v1/orders` (admin) | `getAllOrders` يستخدم `with(['user','items'])` |
| `GET /api/v1/orders/{id}` | `with('items')` في الخدمة/المتحكّم |
| السلة | `CartService` يستخدم `load('items.product.category')` |
| الفئات | `CategoryService::getAll` يستخدم `withCount('products')` (استعلام تجميعي، ليس N+1) |

**الخلاصة:** لا يوجد حالياً مسار قراءة رئيسي يحمّل علاقة Eloquent داخل حلقة بدون `with`/`load`.  
**لا يُدَّعى وجود N+1 مؤكَّد في الإنتاج دون عدّ استعلامات وقت التشغيل.**

---

## 2. مرشّحات أداء (Potential — تحتاج EXPLAIN/قياس)

| المرشّح | الدليل | ليس ادعاء N+1؟ |
| --- | --- | --- |
| قائمة المنتجات مع `latest()->orderByDesc('id')` + فلتر `category_id` + `pending_initial_quantity IS NULL` | `ProductService::getAll`؛ الفهرس الحالي `(category_id, created_at)` قد لا يغطّي شرط النشر والترتيب بـ `id` بالكامل | صحيح — مرشّح فهرس/خطة تنفيذ |
| قائمة admin لكل الطلبات `Order::latest()->paginate(15)` | مسح/فرز على `orders.created_at` بدون فهرس منفصل على `created_at` فقط (الموجود `(user_id, created_at)` و`status`) | مرشّح فهرس |
| `order_items` حسب `order_id` لصفحة طلبات | FK InnoDB عادةً يفهرس `order_id`؛ يجب تأكيد ذلك بـ `SHOW INDEX` على perf DB | تحقق مخطط |
| تفاصيل المنتج | استعلام MySQL واحد + **RPC gRPC** لكل طلب | اختناق شبكة/خدمة وليس N+1 SQL |
| تضخيم قائمة المنتجات لاحقاً بنداء gRPC لكل عنصر | غير موجود اليوم؛ إن أُضيف سيكون N استدعاءات شبكية | خطر مستقبلي موثَّق |

---

## 3. الفهارس الموجودة (من الهجرات)

| الجدول | الفهارس |
| --- | --- |
| `orders` | `status`; unique `reservation_id`; composite `(user_id, created_at)` |
| `products` | composite `(category_id, created_at)`; unique `creation_key` |
| `users` | unique `email`; `role` |
| `order_items` | لا فهرس صريح في الهجرة؛ الاعتماد على فهرس FK الافتراضي في InnoDB |
| `categories` | unique `name` |

---

## 4. فصل خط الأساس عن التحسين

- سكربتات k6 تحت `scripts/perf/k6/baseline-*.js` معلَّمة `phase: baseline`.
- لا تُطبَّق فهارس جديدة ولا Redis ولا ETag ولا Replica في هذه المرحلة.
- بعد تشغيل EXPLAIN الحي عبر `scripts/perf/explain/Run-ExplainBaseline.ps1`، احفظ المخرجات تحت `docs/performance/baselines/` بوسم `pre-opt`.
- أي تحسين لاحق يُوثَّق كمقارنة قبل/بعد على نفس حجم البيانات.
