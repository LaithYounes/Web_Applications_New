# نقل بيئة الأداء إلى حاسوب Windows ثانٍ

لا ترفع ملفات `.env.perf` أو نسخ SQL إلى GitHub.

**تحذير حتى يتم اعتماد commit:** ملف `MANIFEST.json` يسجّل آخر commit موجود على `main` (`cb0dbb9`). هذا الـcommit لا يحتوي شيفرة المتطلب 3 (Redis / ETag / Replica / سكربتات `scripts/perf`). استنساخ `origin/main` وحده لا يكفي لعرض اللجنة. انقل مجلد العمل الحالي، أو انتظر commit وpush معتمدين ثم استخدم ذلك الـSHA الجديد في الـmanifest التالي.

## 1) على الحاسوب الحالي (المصدر)

```powershell
cd "C:\Users\asasv\Desktop\Cursor projects\Web_Applications_New"
git status
git rev-parse --abbrev-ref HEAD
git rev-parse HEAD
powershell -ExecutionPolicy Bypass -File .\scripts\perf\transfer\Backup-PerfDataset.ps1
```

انسخ مجلد `backups\perf\<timestamp>\` بشكل خاص (USB/سحابة مشفّرة).  
انسخ أيضًا عند الحاجة: `docs\performance\baselines\` و`docs\performance\artifacts\` (غير مضمّنة في Git).

اختياري — تصدير صور Docker لتفادي السحب البطيء:

```powershell
docker save mysql:8.4 postgres:16 -o backups\perf\images-db.tar
# بعد بناء التطبيق محليًا إن لزم:
# docker compose -p ecommerce2026-perf -f docker-compose.perf.yml build
# docker save ecommerce2026-perf-app ecommerce2026-perf-inventory-service -o backups\perf\images-app.tar
```

## 2) على الحاسوب الثاني

```powershell
git clone https://github.com/LaithYounes/Web_Applications_New.git
cd Web_Applications_New
git checkout main
git checkout <COMMIT_FROM_MANIFEST>

Copy-Item .\main-app\laravel\.env.perf.example .\main-app\laravel\.env.perf
# عبّئ APP_KEY و JWT_SECRET محليًا (لا تشاركهما في Git)

# إن وُجدت صور محفوظة:
# docker load -i <path>\images-db.tar

powershell -ExecutionPolicy Bypass -File .\scripts\perf\Initialize-PerfEnvironment.ps1
powershell -ExecutionPolicy Bypass -File .\scripts\perf\transfer\Restore-PerfDataset.ps1 -BackupDir <path-to-backup> 
# إن كانت القاعدة غير فارغة بعد تجربة سابقة:
# ... -AllowOverwrite

powershell -ExecutionPolicy Bypass -File .\scripts\perf\transfer\Preflight-SecondLaptop.ps1
```

### بعد الاستعادة: Redis + Replica

1. تأكد أن `CACHE_STORE=redis` و`REDIS_HOST=redis` في `.env.perf` (موجودة في المثال).
2. أنشئ نقطة حفظ جديدة إن لزم، ثم:
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\perf\replica\Initialize-MysqlReplica.ps1 -ResetReplicaVolume
# ثم تأكد DB_READ_HOST=mysql-replica وأعد إنشاء app
docker compose -p ecommerce2026-perf -f docker-compose.perf.yml up -d app
powershell -ExecutionPolicy Bypass -File .\scripts\perf\replica\Verify-MysqlReplica.ps1
```
3. Preflight اللجنة:
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\perf\demo\Preflight-CommitteeDemo.ps1
```

نسخ احتياطي معروف سليم: `backups/perf/20261111-111524/` وcheckpoint ما قبل الـreplica: `backups/perf/20261111-113412/`.

## 3) ما الذي يجب أن يبقى سليمًا

| عنصر | تحقق |
| --- | --- |
| Git commit | يطابق `MANIFEST.json` |
| MySQL | `ecommerce_main_perf` ≥ 200,000 طلب |
| الفهرس | `orders_created_at_index` |
| Postgres | `ecommerce_inventory_perf` |
| Redis | `redis-cli PING` → PONG؛ `CACHE_STORE=redis` |
| Replica | `Verify-MysqlReplica.ps1` → ROUTING_OK |
| HTTP | `http://127.0.0.1:8010/api/v1/health` |
| عرض SQL | `Show-SqlPerformance.ps1 -Mode Verify` |

الاستعادة **ترفض** الكتابة فوق طلبات موجودة إلا مع `-AllowOverwrite`.
