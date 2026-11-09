<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Demo data can only be seeded in local or testing environments.');
        }

        Cache::lock('seed-demo-store', 600)->block(5, function (): void {
            $this->call([
                DemoUserSeeder::class,
                DemoCatalogSeeder::class,
                DemoOrderSeeder::class,
            ]);
        });
    }
}
