<?php

namespace App\Support\Performance;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Hard gate for Requirement-3 bulk writers.
 * Configured names are necessary but not sufficient: the live MySQL schema must be ecommerce_main_perf.
 */
class PerfEnvironmentGuard
{
    public const ALLOWED_DATABASE = 'ecommerce_main_perf';

    /** @var list<string> */
    public const FORBIDDEN_DATABASES = [
        'ecommerce_main',
        'ecommerce_inventory',
        'ecommerce_inventory_perf',
        'mysql',
        'information_schema',
        'performance_schema',
        'sys',
        'laravel',
        'homestead',
        ':memory:',
    ];

    /**
     * @throws RuntimeException
     */
    public function assertSafeToMutate(?string $configuredDatabase = null, ?string $appEnv = null, ?string $perfFlag = null): void
    {
        $appEnv = strtolower((string) ($appEnv ?? config('app.env') ?? ''));
        $perfFlag = (string) ($perfFlag ?? env('PERF_ENVIRONMENT', ''));
        $configuredDatabase = (string) ($configuredDatabase ?? config('database.connections.'.config('database.default').'.database') ?? '');

        if ($perfFlag !== '1') {
            throw new RuntimeException(
                'Perf generator refused: PERF_ENVIRONMENT must be exactly "1".'
            );
        }

        if ($appEnv !== 'performance') {
            throw new RuntimeException(
                'Perf generator refused: APP_ENV must be "performance" (got "'.$appEnv.'").'
            );
        }

        if ($configuredDatabase === '' || $configuredDatabase === '0') {
            throw new RuntimeException(
                'Perf generator refused: DB_DATABASE is unidentified/empty.'
            );
        }

        if (in_array($configuredDatabase, self::FORBIDDEN_DATABASES, true)) {
            throw new RuntimeException(
                'Perf generator refused: database "'.$configuredDatabase.'" is forbidden.'
            );
        }

        if ($configuredDatabase !== self::ALLOWED_DATABASE) {
            throw new RuntimeException(
                'Perf generator refused: expected DB_DATABASE='.self::ALLOWED_DATABASE
                .' (got "'.$configuredDatabase.'").'
            );
        }

        $driver = (string) DB::connection()->getDriverName();
        if ($driver !== 'mysql') {
            throw new RuntimeException(
                'Perf generator refused: live driver must be mysql (got "'.$driver.'").'
            );
        }

        $liveDatabase = $this->resolveLiveDatabaseName();
        if ($liveDatabase !== self::ALLOWED_DATABASE) {
            throw new RuntimeException(
                'Perf generator refused: live connection database is "'.$liveDatabase
                .'", expected "'.self::ALLOWED_DATABASE.'".'
            );
        }
    }

    /**
     * SELECT DATABASE() on the active connection. Failure is a refusal, not a skip.
     *
     * @throws RuntimeException
     */
    public function resolveLiveDatabaseName(): string
    {
        try {
            $row = DB::selectOne('SELECT DATABASE() AS db_name');
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Perf generator refused: unable to read the live database name ('.$exception->getMessage().').',
                0,
                $exception
            );
        }

        $name = null;
        if (is_object($row)) {
            $name = $row->db_name ?? null;
        } elseif (is_array($row)) {
            $name = $row['db_name'] ?? null;
        }

        if ($name === null || $name === '') {
            throw new RuntimeException(
                'Perf generator refused: live database name is unidentified.'
            );
        }

        return (string) $name;
    }
}
