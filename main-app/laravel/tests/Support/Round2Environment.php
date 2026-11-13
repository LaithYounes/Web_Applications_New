<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

class Round2Environment
{
    public static function boot(): array
    {
        $path = getenv('ROUND2_TEST_MANIFEST');
        if (! $path || ! is_file($path)) {
            throw new RuntimeException('Use scripts/qa/Run-Round2Consistency.ps1 to provision disposable services.');
        }
        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! preg_match('/^round2_[a-f0-9]{12}$/D', $manifest['database'] ?? '')
            || ! preg_match('/^round2-[a-f0-9]{12}$/D', $manifest['run_id'] ?? '')
            || $manifest['primary_uuid'] === $manifest['replica_uuid']) {
            throw new RuntimeException('Unsafe round 2 manifest.');
        }
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->useEnvironmentPath(dirname($path));
        $app->useStoragePath(dirname($path).'/storage');
        foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'app/public'] as $directory) {
            @mkdir($app->storagePath($directory), 0777, true);
        }
        $app->make(Kernel::class)->bootstrap();
        config([
            'database.default' => 'round2',
            'database.connections.round2' => [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => $manifest['primary_port'],
                'read' => ['host' => '127.0.0.1', 'port' => $manifest['replica_port']],
                'write' => ['host' => '127.0.0.1', 'port' => $manifest['primary_port']],
                'database' => $manifest['database'], 'username' => 'root', 'password' => $manifest['password'],
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
                'sticky' => false, 'strict' => true,
            ],
            'perf.catalog_cache_enabled' => true, 'perf.orders_use_primary' => true,
            'order_notifications.driver' => 'disabled', 'cache.default' => 'redis',
            'cache.prefix' => $manifest['run_id'].':',
            'database.redis.client' => 'predis',
            'database.redis.options.prefix' => $manifest['run_id'].':',
            'database.redis.cache' => ['host' => '127.0.0.1', 'port' => $manifest['redis_port'], 'database' => 0],
            'session.driver' => 'array', 'queue.default' => 'sync', 'logging.default' => 'null',
        ]);
        $connection = DB::connection();
        foreach (['primary' => $connection->getPdo(), 'replica' => $connection->getReadPdo()] as $name => $pdo) {
            $uuid = $pdo->query('SELECT @@server_uuid')->fetchColumn();
            if ($uuid !== $manifest[$name.'_uuid'] || $pdo->query('SELECT DATABASE()')->fetchColumn() !== $manifest['database']) {
                throw new RuntimeException('Unsafe SQL endpoint; UUID or database does not match isolated run.');
            }
        }

        return [$app, $manifest];
    }

    public static function replicaControl(array $manifest): PDO
    {
        return new PDO('mysql:host=127.0.0.1;port='.$manifest['replica_port'].';dbname='.$manifest['database'], 'root', $manifest['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public static function synchronize(array $manifest): void
    {
        $gtid = DB::connection()->getPdo()->query('SELECT @@GLOBAL.gtid_executed')->fetchColumn();
        $replica = self::replicaControl($manifest);
        $replica->exec('START REPLICA SQL_THREAD');
        $wait = $replica->prepare('SELECT WAIT_FOR_EXECUTED_GTID_SET(?, 20)');
        $wait->execute([$gtid]);
        if ((int) $wait->fetchColumn() !== 0) {
            throw new RuntimeException('Isolated replica failed to catch up.');
        }
        DB::connection()->forgetRecordModificationState();
    }
}
