# نتائج تحسين SQL (قياس فعلي)

نُفّذ على `ecommerce_main_perf` فقط. لم يُمسّ demo. لا Redis ولا ETag ولا Replica.

## ما تغيّر

هجرة قابلة للتراجع: `orders_created_at_index` على `orders.created_at`.

السبب: `OrderService::getAllOrders` ينفّذ `ORDER BY created_at DESC LIMIT 15` بلا `user_id`. الفهرس `(user_id, created_at)` لا يخدم هذا الترتيب. `EXPLAIN ANALYZE` قبل الفهرس: مسح 200,000 صف وفرز، **119ms**. بعده: `Backward index scan` لـ 15 صفًا، **0.67ms**.

## ما لم يُضَف

- لا فهرس على `products`: فرز 100 صف استغرق **0.56ms**.
- لا فهرس مكرر على `order_items.order_id` ولا على `orders(user_id, created_at)`.
- استعلام `order_items` الذي يقدّر 427,170 صفًا و`UNCACHEABLE SUBQUERY` هو استعلام تشخيصي في `scripts/perf/explain/queries.sql`، وليس استعلام Laravel. الاستعلام الفعلي `whereIn(order_id)` يستخدم `order_items_order_id_foreign` في **0.55ms**. تنفيذ الشكل التشخيصي نفسه استخدم الفهرس في **0.01ms**؛ تقدير `EXPLAIN` التقليدي مضلّل.

## k6 — 20 VU / 60s — الخطأ 0 في الحالتين

هذه السيناريوهات لا تستدعي قائمة المدير، لذلك لا يُنسب تغيّر HTTP إلى الفهرس.

| السيناريو | p50 قبل/بعد | p95 قبل/بعد | p99 قبل/بعد | طلب/ث قبل/بعد |
| --- | --- | --- | --- | --- |
| products-list | 300.81 / 266.18 | 616.29 / 705.94 | 890.64 / 962.88 | 31.29 / 32.56 |
| product-detail | 339.75 / 268.60 | 679.84 / 531.49 | 884.97 / 917.42 | 29.57 / 32.37 |
| orders-list | 401.40 / 377.32 | 738.81 / 961.29 | 996.58 / 1115.26 | 26.83 / 27.37 |

قائمة طلبات العميل بقيت على `orders_user_id_created_at_index` (الصفحة **0.14ms**). ارتفاع p95/p99 فيها تذبذب تشغيل، وليس تحسنًا.

الأصل محفوظ في `docs/performance/baselines/pre-opt/`. القياس اللاحق: `explain-baseline-20261110-165052.txt` وملفات k6 ذات الطابع `165111` و`165212` و`165313`.
