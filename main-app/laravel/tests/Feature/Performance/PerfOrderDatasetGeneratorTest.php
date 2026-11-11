<?php

namespace Tests\Feature\Performance;

use App\Console\Commands\PerfGenerateOrdersCommand;
use App\Support\Performance\PerfOrderDatasetGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PerfOrderDatasetGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generator_inserts_orders_with_valid_foreign_keys_without_truncate(): void
    {
        $generator = new PerfOrderDatasetGenerator;

        $first = $generator->generate([
            'count' => 25,
            'chunk' => 10,
            'users' => 5,
            'categories' => 2,
            'products_per_category' => 3,
            'seed' => 42,
            'skip_guard' => true,
        ]);

        $this->assertSame(25, $first['inserted_orders']);
        $this->assertGreaterThanOrEqual(25, $first['inserted_items']);
        $this->assertSame(25, (int) DB::table('orders')->count());
        $this->assertSame(5, (int) DB::table('users')->where('email', 'like', 'perf.user.%@example.test')->count());
        $this->assertSame(6, (int) DB::table('products')->where('name', 'like', 'Perf Product %')->count());

        $orphanItems = DB::table('order_items as oi')
            ->leftJoin('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereNull('o.id')
            ->count();
        $this->assertSame(0, $orphanItems);

        $orphanProducts = DB::table('order_items as oi')
            ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
            ->whereNull('p.id')
            ->count();
        $this->assertSame(0, $orphanProducts);

        $orphanUsers = DB::table('orders as o')
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->whereNull('u.id')
            ->count();
        $this->assertSame(0, $orphanUsers);

        $duplicateReservations = DB::table('orders')
            ->select('reservation_id', DB::raw('count(*) as c'))
            ->groupBy('reservation_id')
            ->having('c', '>', 1)
            ->count();
        $this->assertSame(0, $duplicateReservations);

        // Append-only: second run must not delete the first batch.
        $second = $generator->generate([
            'count' => 10,
            'chunk' => 5,
            'users' => 5,
            'categories' => 2,
            'products_per_category' => 3,
            'seed' => 42,
            'skip_guard' => true,
        ]);

        $this->assertSame(10, $second['inserted_orders']);
        $this->assertSame(35, (int) DB::table('orders')->count());
        $this->assertSame(25, $second['orders_before']);
        $this->assertSame(35, $second['orders_after']);
    }

    public function test_target_total_is_append_only_and_distribution_matches_benchmark_user(): void
    {
        $generator = new PerfOrderDatasetGenerator;
        $options = [
            'chunk' => 50,
            'users' => 10,
            'categories' => 2,
            'products_per_category' => 3,
            'seed' => 42,
            'skip_guard' => true,
        ];

        $generator->generate($options + ['count' => 100]);

        $this->assertSame(100, (int) DB::table('orders')->count());
        $this->assertSame(0, PerfGenerateOrdersCommand::ordersToInsert(200_000, 100, 100));
        $this->assertSame(10, PerfGenerateOrdersCommand::ordersToInsert(200_000, 110, 100));

        $keptIds = DB::table('orders')->orderBy('id')->pluck('id')->all();
        $generator->generate($options + [
            'count' => PerfGenerateOrdersCommand::ordersToInsert(200_000, 110, 100),
        ]);

        $this->assertSame(110, (int) DB::table('orders')->count());
        $this->assertSame($keptIds, DB::table('orders')->orderBy('id')->limit(100)->pluck('id')->all());

        $benchmarkUserId = (int) DB::table('users')->where('email', 'perf.user.00001@example.test')->value('id');
        $owned = (int) DB::table('orders')->where('user_id', $benchmarkUserId)->count();
        $this->assertGreaterThan(50, $owned);

        $duplicateLines = DB::table('order_items')
            ->select('order_id', 'product_id', DB::raw('COUNT(*) as c'))
            ->groupBy('order_id', 'product_id')
            ->having('c', '>', 1)
            ->get();
        $this->assertCount(0, $duplicateLines);

        $timestamps = DB::table('orders')->selectRaw('MIN(created_at) as min_at, MAX(created_at) as max_at')->first();
        $this->assertNotSame($timestamps->min_at, $timestamps->max_at);

        $mismatchedTotals = 0;
        foreach (DB::table('orders')->get() as $order) {
            $sum = (float) DB::table('order_items')->where('order_id', $order->id)->sum('subtotal');
            if (abs(((float) $order->total_amount) - $sum) > 0.001) {
                $mismatchedTotals++;
            }
        }
        $this->assertSame(0, $mismatchedTotals);
    }

    public function test_artisan_command_refuses_sqlite_even_when_names_match_perf(): void
    {
        config(['app.env' => 'performance']);
        putenv('PERF_ENVIRONMENT=1');
        $_ENV['PERF_ENVIRONMENT'] = '1';
        $_SERVER['PERF_ENVIRONMENT'] = '1';
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => 'ecommerce_main_perf',
        ]);

        $exit = Artisan::call('perf:generate-orders', [
            '--count' => 5,
            '--target-total' => 5,
            '--dry-run' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('mysql', Artisan::output());
        $this->assertSame(0, (int) DB::table('orders')->count());
    }

    public function test_artisan_command_refuses_non_performance_environment(): void
    {
        config(['app.env' => 'testing']);
        putenv('PERF_ENVIRONMENT=1');
        $_ENV['PERF_ENVIRONMENT'] = '1';
        $_SERVER['PERF_ENVIRONMENT'] = '1';
        config([
            'database.connections.sqlite.database' => ':memory:',
            'database.default' => 'sqlite',
        ]);

        $exit = Artisan::call('perf:generate-orders', [
            '--count' => 1,
            '--dry-run' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('refused', Artisan::output());
        $this->assertSame(0, (int) DB::table('orders')->count());
    }

    public function test_artisan_command_refuses_when_perf_flag_missing(): void
    {
        config(['app.env' => 'performance']);
        putenv('PERF_ENVIRONMENT=');
        $_ENV['PERF_ENVIRONMENT'] = '';
        $_SERVER['PERF_ENVIRONMENT'] = '';
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => 'ecommerce_main_perf',
        ]);

        // Force configured database name check through env/config path used by the command.
        config(['database.connections.mysql.database' => 'ecommerce_main_perf']);

        $command = $this->app->make(PerfGenerateOrdersCommand::class);
        $this->assertInstanceOf(PerfGenerateOrdersCommand::class, $command);

        // Guard path used by the command (without relying on live SELECT DATABASE()).
        $this->expectException(RuntimeException::class);
        $this->app->make(\App\Support\Performance\PerfEnvironmentGuard::class)
            ->assertSafeToMutate('ecommerce_main_perf', 'performance', '');
    }
}
