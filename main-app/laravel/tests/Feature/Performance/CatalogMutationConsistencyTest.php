<?php

namespace Tests\Feature\Performance;

use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\ProductService;
use App\Modules\Products\Services\CategoryService;
use App\Support\Performance\CatalogCache;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CatalogMutationConsistencyTest extends TestCase
{
    use DatabaseMigrations;

    private Product $product;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        config(['perf.catalog_cache_enabled' => true]);
        Storage::fake('public');
        Storage::disk('public')->put('products/old.png', 'old');
        $this->category = Category::create(['name' => 'A']);
        $this->product = Product::create([
            'category_id' => $this->category->id, 'name' => 'Old',
            'description' => 'Old description', 'price' => '10.00',
            'image_path' => 'products/old.png',
        ]);
    }

    public static function fields(): array
    {
        return [['name', 'New'], ['description', 'New description'], ['price', '25.50'],
            ['pending_initial_quantity', 2]];
    }

    #[DataProvider('fields')]
    public function test_hot_lists_reflect_each_catalog_field(string $field, mixed $value): void
    {
        $this->getJson('/api/v1/products')->assertJsonPath('data.0.name', 'Old');
        $this->assertSame(1, app(CategoryService::class)->getAll()->first()->products_count);
        $before = app(CatalogCache::class)->version();
        app(ProductService::class)->update($this->product->id, [$field => $value]);
        $this->assertGreaterThan($before, app(CatalogCache::class)->version());
        $response = $this->getJson('/api/v1/products')->assertOk();
        if ($field === 'pending_initial_quantity') {
            $response->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        } else {
            $response->assertJsonPath('data.0.'.$field, $value);
        }
    }

    public function test_move_delete_counts_and_pagination(): void
    {
        $other = Category::create(['name' => 'B']);
        foreach (['', '?category_id='.$this->category->id, '?category_id='.$other->id, '?per_page=1&page=2'] as $query) {
            $this->getJson('/api/v1/products'.$query)->assertOk();
        }
        $this->getJson('/api/v1/categories')->assertOk();
        app(ProductService::class)->update($this->product->id, ['category_id' => $other->id]);
        $this->getJson('/api/v1/products?category_id='.$this->category->id)->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/products?category_id='.$other->id)->assertJsonPath('data.0.id', $this->product->id);
        $counts = app(CategoryService::class)->getAll()->pluck('products_count', 'id');
        $this->assertSame(0, $counts[$this->category->id]);
        $this->assertSame(1, $counts[$other->id]);
        app(ProductService::class)->delete($this->product->id);
        $this->getJson('/api/v1/products')->assertJsonPath('meta.total', 0)->assertJsonPath('meta.last_page', 1);
        $this->getJson('/api/v1/products?per_page=1&page=2')->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/categories')->assertOk();
        $this->assertSame(0, app(CategoryService::class)->getAll()->pluck('products_count', 'id')[$other->id]);
    }

    public function test_outer_commit_and_rollback_do_not_publish_uncommitted_data_or_remove_old_image(): void
    {
        $this->getJson('/api/v1/products')->assertOk();
        $cache = app(CatalogCache::class);
        $before = $cache->version();
        $key = $cache->productsKey(null, 12, 1);
        $payload = Cache::get($key);
        DB::beginTransaction();
        try {
            app(ProductService::class)->update($this->product->id, ['name' => 'Rolled back', 'image' => UploadedFile::fake()->create('new.png', 1)]);
            $rolledBackImage = $this->product->fresh()->image_path;
            $this->getJson('/api/v1/products')->assertJsonPath('data.0.name', 'Rolled back');
            $this->assertSame($payload, Cache::get($key));
            Storage::disk('public')->assertExists('products/old.png');
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $cache->version());
        Storage::disk('public')->assertMissing($rolledBackImage);
        $this->getJson('/api/v1/products')->assertJsonPath('data.0.name', 'Old');
        DB::beginTransaction();
        app(ProductService::class)->update($this->product->id, ['name' => 'Committed']);
        DB::commit();
        $this->getJson('/api/v1/products')->assertJsonPath('data.0.name', 'Committed');
    }

    public function test_failed_save_and_image_leave_hot_cache_unchanged(): void
    {
        $this->getJson('/api/v1/products')->assertOk();
        $before = app(CatalogCache::class)->version();
        Product::updating(fn () => throw new RuntimeException('Injected save failure'));
        try {
            app(ProductService::class)->update($this->product->id, ['name' => 'Failed']);
            $this->fail('Save must fail');
        } catch (RuntimeException $e) {
            $this->assertSame('Injected save failure', $e->getMessage());
        } finally {
            Product::flushEventListeners();
        }
        $storage = Storage::getFacadeRoot();
        Storage::shouldReceive('disk')->with('public')->andReturnSelf();
        Storage::shouldReceive('putFileAs')->andReturn(false);
        try {
            app(ProductService::class)->update($this->product->id, ['image' => UploadedFile::fake()->create('broken.png', 1)]);
            $this->fail('Image must fail');
        } catch (RuntimeException $e) {
            $this->assertSame('The product image could not be saved.', $e->getMessage());
        } finally {
            Storage::swap($storage);
        }
        $this->assertSame($before, app(CatalogCache::class)->version());
        $this->getJson('/api/v1/products')->assertJsonPath('data.0.name', 'Old');
    }

    public function test_missing_generation_bypasses_cache_and_rejects_mutation_without_fallback_to_one(): void
    {
        $this->getJson('/api/v1/products')->assertOk();
        $cache = app(CatalogCache::class);
        $generation = $cache->version();
        DB::table('catalog_cache_state')->where('id', 1)->delete();
        // Deliberately leave a hot old key; it must not be reused if generation
        // cannot be read, and a mutation must not silently lose invalidation.
        $this->getJson('/api/v1/products')->assertJsonPath('data.0.name', 'Old');
        $this->assertNull($cache->lastKey);
        try {
            app(ProductService::class)->update($this->product->id, ['name' => 'Must roll back']);
            $this->fail('Mutation must reject lost durable invalidation');
        } catch (RuntimeException $e) {
            $this->assertSame('Catalog cache generation is unavailable.', $e->getMessage());
        }
        $this->assertSame('Old', $this->product->fresh()->name);
        DB::table('catalog_cache_state')->insert(['id' => 1, 'generation' => $generation + 1]);
        app(ProductService::class)->update($this->product->id, ['name' => 'Recovered']);
        $this->getJson('/api/v1/products')->assertJsonPath('data.0.name', 'Recovered');
    }

    public function test_hot_image_and_http_revalidation_change_after_mutation(): void
    {
        $this->getJson('/api/v1/products')->assertOk()->assertHeader('Cache-Control', 'max-age=0, must-revalidate, public');
        $this->mock(\App\Modules\Inventory\Services\InventoryGrpcClient::class)->shouldReceive('getStock')->andReturn(3);
        $before = $this->getJson('/api/v1/products/'.$this->product->id)->assertOk();
        $etag = $before->headers->get('ETag');
        $this->getJson('/api/v1/products/'.$this->product->id, ['If-None-Match' => $etag])->assertStatus(304);
        app(ProductService::class)->update($this->product->id, ['image' => UploadedFile::fake()->create('replacement.png', 1)]);
        $path = $this->product->fresh()->image_path;
        $this->getJson('/api/v1/products', ['If-Modified-Since' => now()->toRfc7231String()])
            ->assertOk()->assertJsonPath('data.0.image', Storage::disk('public')->url($path));
        $after = $this->getJson('/api/v1/products/'.$this->product->id, ['If-None-Match' => $etag])->assertOk();
        $this->assertNotSame($etag, $after->headers->get('ETag'));
        $this->getJson('/api/v1/products/'.$this->product->id, ['If-None-Match' => $after->headers->get('ETag')])->assertStatus(304);
    }

    public function test_pages_filters_users_and_current_request_links_are_isolated(): void
    {
        $category = Category::create(['name' => 'B']);
        $other = Product::create(['category_id' => $category->id, 'name' => 'Other', 'price' => '2.00', 'image_path' => 'other.png']);
        $first = $this->getJson('/api/v1/products?per_page=1&page=1')->assertOk();
        $second = $this->getJson('/api/v1/products?per_page=1&page=2')->assertOk();
        $this->assertNotSame($first->json('data.0.id'), $second->json('data.0.id'));
        $this->getJson('/api/v1/products?category_id='.$this->category->id)->assertJsonPath('data.0.id', $this->product->id);
        $this->getJson('/api/v1/products?category_id='.$category->id)->assertJsonPath('data.0.id', $other->id);
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user, 'api')->getJson('http://current.example/api/v1/products?per_page=1&page=1')
            ->assertOk()->assertJsonPath('data.0.id', $first->json('data.0.id'))
            ->assertJsonPath('meta.path', 'http://current.example/api/v1/products');
        $this->assertArrayNotHasKey('available_quantity', $first->json('data.0'));
        $this->assertArrayNotHasKey('user_id', $first->json('data.0'));
    }

    public function test_hot_cache_initial_stock_failure_retry_and_idempotent_zero_creation(): void
    {
        $this->getJson('/api/v1/products')->assertOk();
        $data = ['category_id' => $this->category->id, 'name' => 'Pending', 'price' => '8.00',
            'initial_quantity' => 3, 'image' => UploadedFile::fake()->create('pending.png', 1)];
        $this->mock(\App\Modules\Inventory\Services\InventoryGrpcClient::class)
            ->shouldReceive('initializeStock')->twice()->andThrow(new \App\Modules\Inventory\Exceptions\InventoryServiceException('Injected inventory failure'));
        try {
            app(ProductService::class)->create($data, 'retry-key');
            $this->fail('Inventory must fail');
        } catch (\App\Modules\Inventory\Exceptions\InventoryServiceException) {
            $this->getJson('/api/v1/products')->assertJsonCount(1, 'data');
        }
        $this->mock(\App\Modules\Inventory\Services\InventoryGrpcClient::class)
            ->shouldReceive('initializeStock')->once()->andReturn(3);
        $created = app(ProductService::class)->create($data, 'retry-key');
        $this->getJson('/api/v1/products')->assertJsonPath('meta.total', 2)->assertJsonFragment(['name' => 'Pending']);
        $this->assertSame($created->id, app(ProductService::class)->create($data, 'retry-key')->id);
        $this->assertSame(2, Product::count());
        $data['name'] = 'Zero';
        $data['initial_quantity'] = 0;
        $zero = app(ProductService::class)->create($data, 'zero-key');
        $this->getJson('/api/v1/products')->assertJsonPath('meta.total', 3)->assertJsonFragment(['name' => 'Zero']);
        $generation = app(CatalogCache::class)->version();
        $this->assertSame($zero->id, app(ProductService::class)->create($data, 'zero-key')->id);
        $this->assertSame($generation, app(CatalogCache::class)->version());
    }

    public function test_outer_rollback_of_delete_and_category_creation_preserves_catalog_and_image(): void
    {
        $this->getJson('/api/v1/products')->assertOk();
        $this->getJson('/api/v1/categories')->assertOk();
        $before = app(CatalogCache::class)->version();
        DB::beginTransaction();
        app(ProductService::class)->delete($this->product->id);
        app(CategoryService::class)->create(['name' => 'Rolled back category']);
        Storage::disk('public')->assertExists('products/old.png');
        DB::rollBack();
        Storage::disk('public')->assertExists('products/old.png');
        $this->assertSame($before, app(CatalogCache::class)->version());
        $this->getJson('/api/v1/products')->assertJsonPath('data.0.id', $this->product->id);
        $this->getJson('/api/v1/categories')->assertJsonCount(1, 'data');
    }

    public function test_database_cache_backend_uses_the_same_durable_invalidation(): void
    {
        config(['cache.default' => 'database']);
        $cache = app(CatalogCache::class);
        $this->getJson('/api/v1/products')->assertJsonPath('data.0.name', 'Old');
        $hits = $cache->hits;
        $this->getJson('/api/v1/products')->assertJsonPath('data.0.name', 'Old');
        $this->assertSame($hits + 1, $cache->hits);
        app(ProductService::class)->update($this->product->id, ['name' => 'Database cache']);
        $this->getJson('/api/v1/products')->assertJsonPath('data.0.name', 'Database cache');
        app(ProductService::class)->delete($this->product->id);
        $this->getJson('/api/v1/products')->assertJsonCount(0, 'data');
    }

    public function test_mutations_shift_hot_pages_and_update_all_pagination_metadata(): void
    {
        for ($i = 1; $i <= 16; $i++) {
            $category = Category::create(['name' => sprintf('Category %02d', $i)]);
            Product::create(['category_id' => $this->category->id, 'name' => 'Page '.$i, 'price' => '1.00', 'image_path' => 'page.png']);
        }
        $first = $this->getJson('/api/v1/products?per_page=8&page=1')->assertJsonPath('meta.total', 17)->assertJsonPath('meta.last_page', 3);
        $second = $this->getJson('/api/v1/products?per_page=8&page=2')->assertJsonCount(8, 'data');
        $third = $this->getJson('/api/v1/products?per_page=8&page=3')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/categories?page=2')->assertJsonPath('meta.total', 17);
        app(ProductService::class)->delete($first->json('data.0.id'));
        $updatedSecond = $this->getJson('/api/v1/products?per_page=8&page=2')->assertJsonPath('meta.total', 16)->assertJsonPath('meta.last_page', 2);
        $this->assertNotContains($second->json('data.0.id'), array_column($updatedSecond->json('data'), 'id'));
        $this->assertContains($third->json('data.0.id'), array_column($updatedSecond->json('data'), 'id'));
        $this->getJson('/api/v1/products?per_page=8&page=3')->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 16);
        app(CategoryService::class)->create(['name' => 'AA new category']);
        $this->getJson('/api/v1/categories?page=2')->assertJsonPath('meta.total', 18)->assertJsonCount(3, 'data');
    }
}
