# خطة تحسين SQL — لا تُنفَّذ قبل أرشفة خط الأساس

لا migrations ولا فهارس في هذه المرحلة. القرار بعد ملفات `docs/performance/baselines/k6-metrics-*.json` و`explain-baseline-*.txt`.

## ما هو مؤكَّد من الشيفرة

| الاستعلام الفعلي | الدليل | الحكم |
| --- | --- | --- |
| منتجات منشورة بدون فئة: `WHERE pending_initial_quantity IS NULL ORDER BY created_at DESC, id DESC LIMIT 20` + `COUNT(*)` | `ProductService::getAll` | لا فهرس يغطّي الشرط والترتيب معًا. الموجود `(category_id, created_at)` لا يخدم القائمة غير المفلترة. |
| نفس القائمة مع `category_id` | المعامل الاختياري في `getAll` | الفهرس الحالي يبدأ بـ `category_id` ثم `created_at` ولا يتضمن `pending_initial_quantity` ولا `id`. |
| تفاصيل منتج | `findById`: `id` + `pending_initial_quantity IS NULL` | مفتاح أساسي. التكلفة الإضافية استدعاء gRPC واحد، ليست N+1 SQL. |
| طلبات العميل | `OrderService::getUserOrders`: `WHERE user_id=? ORDER BY created_at DESC LIMIT 10` + `COUNT(*)` | الفهرس `(user_id, created_at)` موجود أصلًا. لا يُقترح فهرس مكرر. |
| بنود الصفحة | `whereIn(order_id)` بعد `with('items')` | `foreignId` ينشئ فهرس FK في InnoDB. تأكيد بـ `SHOW INDEX` فقط؛ لا إضافة إن وُجد. |
| كل الطلبات (admin) | `getAllOrders`: `ORDER BY created_at DESC LIMIT 15` بلا `user_id` | `(user_id, created_at)` لا يخدم ترتيب `created_at` وحده. سيناريو k6 للعميل لا يضرب هذا المسار. |
| الفئات | `withCount('products')` استعلام واحد فيه subquery | ليس N+1. |

## N+1

لا يوجد N+1 SQL مؤكَّد في مسارات القراءة المستهدفة: `with('category')` و`with('items')` و`with(['user','items'])` و`withCount`. `OrderResource` يقرأ `items` المحمّلة مسبقًا. لا تُضاف تحسينات N+1 قبل عدّ الاستعلامات على خط الأساس.

## مرشّحات الفهارس (بعد EXPLAIN فقط)

1. `products (pending_initial_quantity, created_at, id)` لقائمة k6 الافتراضية و`COUNT(*)`.
2. `products (category_id, pending_initial_quantity, created_at, id)` لفلتر الفئة إن أظهر EXPLAIN عدم استخدام الفهرس الحالي.
3. `orders (created_at)` فقط إذا قيس مسار admin. ليس جزءًا من k6 العميل.
4. لا فهرس جديد على `orders (user_id, created_at)` ولا على `order_items.order_id` إلا إذا `SHOW INDEX` أثبت غيابه.

## خارج هذه الخطة

Redis وETag وRead Replica تبقى مراحل لاحقة ولا تُخلط مع قياس SQL.
