<?php

namespace Tests\Feature\Performance;

use App\Models\User;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Support\Performance\CatalogCache;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogCacheTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['perf.catalog_cache_enabled' => true]);
        Cache::flush();
    }

    public function test_product_list_is_served_from_cache_on_second_request(): void
    {
        $category = Category::create(['name' => 'Cache Cat', 'description' => 'd']);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Cached Product',
            'description' => 'd',
            'price' => '10.00',
            'image_path' => 'products/x.png',
            'pending_initial_quantity' => null,
        ]);

        $cache = app(CatalogCache::class);
        $this->getJson('/api/v1/products?per_page=12')->assertOk()->assertJsonPath('success', true);
        $this->assertSame(1, $cache->misses);
        $key = $cache->productsKey(null, 12, 1);
        $this->assertIsArray(Cache::get($key), 'Catalog list payload must be stored after MISS.');

        $cachedPage = $cache->productsList(null, 12, 1, function () {
            $this->fail('Resolver must not run on catalog cache HIT.');
        });
        $this->assertSame(1, $cache->hits);
        $this->assertSame('Cached Product', $cachedPage->getCollection()->first()->name);

        $this->getJson('/api/v1/products?per_page=12')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Cached Product');
        $this->assertSame(2, $cache->hits, 'HTTP second request must also HIT catalog cache.');
    }

    public function test_product_creation_invalidates_catalog_cache(): void
    {
        config(['jwt.algo' => 'HS256', 'jwt.secret' => str_repeat('test', 8)]);

        $category = Category::create(['name' => 'Invalidate Cat', 'description' => 'd']);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Before',
            'description' => 'd',
            'price' => '10.00',
            'image_path' => 'products/x.png',
            'pending_initial_quantity' => null,
        ]);

        $this->getJson('/api/v1/products?per_page=12')->assertOk();
        $versionBefore = app(CatalogCache::class)->version();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->mock(\App\Modules\Inventory\Services\InventoryGrpcClient::class)
            ->shouldReceive('initializeStock')->andReturn(5);

        \Illuminate\Support\Facades\Storage::fake('public');

        $this->actingAs($admin, 'api')->post('/api/v1/products', [
            'category_id' => $category->id,
            'name' => 'After Create',
            'price' => '11.00',
            'initial_quantity' => 5,
            'image' => \Illuminate\Http\UploadedFile::fake()->createWithContent(
                'p.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGNgaPgPAAIDAYAkYfWXAAAAAElFTkSuQmCC'),
            ),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertGreaterThan($versionBefore, app(CatalogCache::class)->version());
        $this->getJson('/api/v1/products?per_page=12')
            ->assertOk()
            ->assertJsonFragment(['name' => 'After Create']);
    }
}
