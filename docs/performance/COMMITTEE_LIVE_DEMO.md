# عرض اللجنة الحي — المتطلب 3 (أداء وتخزين مؤقت)

## Preflight واحد قبل العرض

```powershell
cd "<repo>"
powershell -ExecutionPolicy Bypass -File .\scripts\perf\demo\Preflight-CommitteeDemo.ps1
```

يؤكد: بيئة `ecommerce2026-perf`، Redis، replica، 200k طلب، فهرس SQL، وHTTP/gRPC.

---

## ترتيب العرض المقترح (قصير)

### 1) البيانات
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\perf\demo\Show-Dataset.ps1
```
المتوقع: `orders=200000`.

### 2) تحسين SQL (قائمة المدير)
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\perf\demo\Show-SqlPerformance.ps1 -Mode Compare
```
- Before = `IGNORE INDEX (orders_created_at_index)` على **نفس** القاعدة (لا حذف للفهرس).
- After = استخدام الفهرس.
- صياغة صادقة: الاستثناء لا يعيد البيئة القديمة كاملة؛ يثبت فرق الخطة على نفس البيانات.

### 3) Redis
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\perf\demo\Show-RedisCache.ps1
```
- MISS: استعلامات DB > 0  
- HIT: استعلامات DB = 0  
- لا يُخزَّن المخزون السلطوي.

### 4) ETag
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\perf\demo\Show-Etag.ps1
```
- 200 + ETag ثم 304 بجسم فارغ.
- على Windows استخدم ملف ترويسة لـ `If-None-Match` (السكربت يفعل ذلك).

### 5) Read Replica
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\perf\demo\Show-Replica.ps1
```
- `server_id` للكتابة=1 وللقراءة=2  
- لا تدّعِ اتساقًا فوريًا دائمًا.

### 6) k6 قصير + gRPC
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\perf\demo\Show-K6Short.ps1
powershell -ExecutionPolicy Bypass -File .\scripts\perf\demo\Show-GrpcInventory.ps1
```

---

## ماذا نقوله عن k6؟

خط الأساس محفوظ في `docs/performance/baselines/pre-opt/`.  
القياس بعد التحسين في `docs/performance/baselines/post-opt/`.

| الطبقة | الدليل |
| --- | --- |
| SQL | تحسن كبير على استعلام المدير |
| Redis HIT | عدّ استعلامات 3→0 |
| ETag | 304 للعملاء المشروطين |
| API k6 غير المشروط | قد لا يتحسن تحت `php artisan serve`؛ التقرير النهائي يوثق الأرقام بصراحة |

---

## إن فشل شيء

1. أعد `Preflight-CommitteeDemo.ps1`.
2. لا تستخدم `down -v`.
3. إن توقفت الـreplica: عيّن `DB_READ_HOST=mysql` مؤقتًا وأعد إنشاء `app`.
4. لا تختلق أرقامًا أمام اللجنة.
