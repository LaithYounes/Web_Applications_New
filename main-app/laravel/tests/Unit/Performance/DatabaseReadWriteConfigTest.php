<?php

namespace Tests\Unit\Performance;

use Tests\TestCase;

class DatabaseReadWriteConfigTest extends TestCase
{
    public function test_mysql_connection_defines_read_and_write_hosts(): void
    {
        $mysql = config('database.connections.mysql');

        $this->assertArrayHasKey('read', $mysql);
        $this->assertArrayHasKey('write', $mysql);
        $this->assertIsArray($mysql['read']['host']);
        $this->assertIsArray($mysql['write']['host']);
        $this->assertTrue(array_key_exists('sticky', $mysql));
    }

    public function test_orders_use_primary_flag_defaults_true(): void
    {
        $this->assertTrue(config('perf.orders_use_primary'));
    }
}
