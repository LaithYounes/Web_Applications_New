<?php

namespace Tests\Feature\Performance;

use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Support\Performance\CatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CatalogCacheFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['perf.catalog_cache_enabled' => true]);
    }

    public function test_catalog_list_survives_redis_read_failure(): void
    {
        $category = Category::create(['name' => 'Fail Cat', 'description' => 'd']);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Still Served',
            'description' => 'd',
            'price' => '10.00',
            'image_path' => 'products/x.png',
            'pending_initial_quantity' => null,
        ]);

        Cache::shouldReceive('get')
            ->andThrow(new \RuntimeException('redis down'));
        Cache::shouldReceive('put')->andReturn(true);
        Cache::shouldReceive('forever')->andReturn(true);

        $this->getJson('/api/v1/products?per_page=12')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Still Served');
    }

    public function test_catalog_list_survives_redis_write_failure(): void
    {
        $category = Category::create(['name' => 'Write Fail Cat', 'description' => 'd']);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Write Fail Product',
            'description' => 'd',
            'price' => '10.00',
            'image_path' => 'products/x.png',
            'pending_initial_quantity' => null,
        ]);

        Cache::shouldReceive('get')->andReturn(null);
        Cache::shouldReceive('put')
            ->andThrow(new \RuntimeException('redis write down'));
        Cache::shouldReceive('forever')->andReturn(true);

        $this->getJson('/api/v1/products?per_page=12')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Write Fail Product');

        $this->assertSame(1, app(CatalogCache::class)->misses);
    }
}
