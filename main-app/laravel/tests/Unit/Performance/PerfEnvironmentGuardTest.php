<?php

namespace Tests\Unit\Performance;

use App\Support\Performance\PerfEnvironmentGuard;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PerfEnvironmentGuardTest extends TestCase
{
    private PerfEnvironmentGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new PerfEnvironmentGuard;
    }

    public function test_refuses_sqlite_even_if_configured_name_matches(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('live driver must be mysql');

        $this->guard->assertSafeToMutate(
            PerfEnvironmentGuard::ALLOWED_DATABASE,
            'performance',
            '1',
        );
    }

    public function test_allows_only_when_live_mysql_database_is_perf(): void
    {
        $connection = \Mockery::mock();
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        DB::shouldReceive('connection')->once()->andReturn($connection);
        DB::shouldReceive('selectOne')
            ->once()
            ->with('SELECT DATABASE() AS db_name')
            ->andReturn((object) ['db_name' => PerfEnvironmentGuard::ALLOWED_DATABASE]);

        $this->guard->assertSafeToMutate(
            PerfEnvironmentGuard::ALLOWED_DATABASE,
            'performance',
            '1',
        );

        $this->assertTrue(true);
    }

    public function test_refuses_when_live_database_is_demo_despite_config_name(): void
    {
        $connection = \Mockery::mock();
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        DB::shouldReceive('connection')->andReturn($connection);
        DB::shouldReceive('selectOne')->andReturn((object) ['db_name' => 'ecommerce_main']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('live connection database');

        $this->guard->assertSafeToMutate(
            PerfEnvironmentGuard::ALLOWED_DATABASE,
            'performance',
            '1',
        );
    }

    public function test_refuses_when_live_database_name_cannot_be_read(): void
    {
        $connection = \Mockery::mock();
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        DB::shouldReceive('connection')->andReturn($connection);
        DB::shouldReceive('selectOne')->andReturn((object) ['db_name' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unidentified');

        $this->guard->assertSafeToMutate(
            PerfEnvironmentGuard::ALLOWED_DATABASE,
            'performance',
            '1',
        );
    }

    #[DataProvider('refusalCases')]
    public function test_refuses_unsafe_contexts(
        string $database,
        string $appEnv,
        string $perfFlag,
        string $expectedFragment,
    ): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedFragment);

        $this->guard->assertSafeToMutate($database, $appEnv, $perfFlag);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function refusalCases(): array
    {
        return [
            'missing perf flag' => [
                'ecommerce_main_perf', 'performance', '0', 'PERF_ENVIRONMENT',
            ],
            'empty perf flag' => [
                'ecommerce_main_perf', 'performance', '', 'PERF_ENVIRONMENT',
            ],
            'demo database' => [
                'ecommerce_main', 'performance', '1', 'forbidden',
            ],
            'local env' => [
                'ecommerce_main_perf', 'local', '1', 'APP_ENV',
            ],
            'production env' => [
                'ecommerce_main_perf', 'production', '1', 'APP_ENV',
            ],
            'testing env' => [
                'ecommerce_main_perf', 'testing', '1', 'APP_ENV',
            ],
            'unidentified database' => [
                '', 'performance', '1', 'unidentified',
            ],
            'unknown database name' => [
                'something_else', 'performance', '1', 'expected DB_DATABASE',
            ],
            'memory sqlite' => [
                ':memory:', 'performance', '1', 'forbidden',
            ],
        ];
    }
}
