<?php

namespace App\Console\Commands;

use App\Support\Performance\PerfEnvironmentGuard;
use App\Support\Performance\PerfOrderDatasetGenerator;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Baseline data generator for Requirement 3.
 * Writes synthetic orders only into ecommerce_main_perf. Never truncates.
 */
class PerfGenerateOrdersCommand extends Command
{
    protected $signature = 'perf:generate-orders
                            {--count=200000 : Number of orders to insert in this run}
                            {--chunk=2000 : Orders per transaction batch (1–5000)}
                            {--users=5000 : Synthetic customer accounts to ensure}
                            {--categories=10 : Synthetic categories to ensure}
                            {--products-per-category=10 : Products per synthetic category}
                            {--seed=20261110 : Deterministic seed base}
                            {--target-total= : If set, insert only max(0, target - current orders)}
                            {--dry-run : Validate environment and print plan without writing}';

    protected $description = 'Generate chunked synthetic orders for the isolated performance database only';

    /**
     * Append-only insert size. A target never deletes existing rows.
     */
    public static function ordersToInsert(int $count, ?int $targetTotal, int $currentOrders): int
    {
        if ($targetTotal !== null) {
            return max(0, $targetTotal - max(0, $currentOrders));
        }

        return max(0, $count);
    }

    public function handle(PerfEnvironmentGuard $guard, PerfOrderDatasetGenerator $generator): int
    {
        try {
            $guard->assertSafeToMutate();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $targetOption = $this->option('target-total');
        $targetTotal = ($targetOption !== null && $targetOption !== '') ? (int) $targetOption : null;
        $current = (int) \Illuminate\Support\Facades\DB::table('orders')->count();
        $count = self::ordersToInsert((int) $this->option('count'), $targetTotal, $current);

        if ($targetTotal !== null) {
            $this->info("target-total={$targetTotal} current={$current} will_insert={$count}");
        }

        $plan = [
            'APP_ENV' => config('app.env'),
            'PERF_ENVIRONMENT' => env('PERF_ENVIRONMENT'),
            'DB_DATABASE' => config('database.connections.'.config('database.default').'.database'),
            'LIVE_DATABASE' => $guard->resolveLiveDatabaseName(),
            'count' => $count,
            'chunk' => (int) $this->option('chunk'),
            'users' => (int) $this->option('users'),
            'categories' => (int) $this->option('categories'),
            'products_per_category' => (int) $this->option('products-per-category'),
            'seed' => (int) $this->option('seed'),
        ];

        $this->table(['key', 'value'], collect($plan)->map(fn ($v, $k) => [$k, (string) $v])->values()->all());

        if ($this->option('dry-run')) {
            $this->warn('Dry run only — no rows inserted.');

            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info('Nothing to insert.');

            return self::SUCCESS;
        }

        try {
            $result = $generator->generate([
                'count' => $count,
                'chunk' => (int) $this->option('chunk'),
                'users' => (int) $this->option('users'),
                'categories' => (int) $this->option('categories'),
                'products_per_category' => (int) $this->option('products-per-category'),
                'seed' => (int) $this->option('seed'),
                'progress' => fn (string $message) => $this->line($message),
            ]);
        } catch (Throwable $exception) {
            $this->error('Generation failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Generation finished (append-only; no truncate/delete).');
        $this->table(
            ['metric', 'value'],
            collect($result)->map(fn ($v, $k) => [$k, (string) $v])->values()->all()
        );

        return self::SUCCESS;
    }
}
