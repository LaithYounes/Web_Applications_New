<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Exceptions\InventoryServiceException;
use App\Modules\Inventory\Services\InventoryGrpcClient;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    private function productInput(): array
    {
        return [
            'category_id' => Category::firstOrCreate(['name' => 'Test category'])->id,
            'name' => 'Test product',
            'price' => '12.50',
            'initial_quantity' => 3,
            'image' => UploadedFile::fake()->createWithContent(
                'product.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGNgaPgPAAIDAYAkYfWXAAAAAElFTkSuQmCC'),
            ),
        ];
    }

    public function test_an_image_write_failure_does_not_create_a_product(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $disk = Storage::fake('public');
        $failingDisk = \Mockery::mock($disk);
        $failingDisk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::set('public', $failingDisk);

        $this->actingAs($admin, 'api')
            ->post('/api/v1/products', $this->productInput(), ['Accept' => 'application/json'])
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_retrying_creation_reuses_the_hidden_product_after_an_inventory_outage(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        $headers = ['Accept' => 'application/json', 'Idempotency-Key' => 'product-stock-retry'];
        $inventory = $this->mock(InventoryGrpcClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('initializeStock')->twice()
                ->andThrow(new InventoryServiceException('Simulated lost response'));
        });

        $this->actingAs($admin, 'api')
            ->post('/api/v1/products', $this->productInput(), $headers)
            ->assertServiceUnavailable()
            ->assertJsonPath('success', false);

        $product = Product::sole();
        $this->assertSame(3, (int) $product->pending_initial_quantity);
        $this->getJson('/api/v1/products')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/products/{$product->id}")->assertNotFound();

        $inventory->shouldReceive('initializeStock')->once()->with($product->id, 3)->andReturn(3);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->post('/api/v1/products', $this->productInput(), $headers)
                ->assertCreated()
                ->assertJsonPath('data.id', $product->id);
        }

        $differentInput = $this->productInput();
        $differentInput['name'] = 'Different product';
        $this->post('/api/v1/products', $differentInput, $headers)->assertConflict();

        $this->assertDatabaseCount('products', 1);
        $this->assertNull($product->fresh()->pending_initial_quantity);
        $this->getJson('/api/v1/products')->assertJsonCount(1, 'data');
    }

    public function test_product_and_category_pagination_share_a_shape_and_keep_product_filters(): void
    {
        $category = Category::create(['name' => 'Filtered category']);
        $otherCategory = Category::create(['name' => 'Other category']);
        foreach ([$category->id, $category->id, $otherCategory->id] as $categoryId) {
            Product::create([
                'category_id' => $categoryId,
                'name' => 'Test product',
                'price' => '12.50',
                'image_path' => 'test.png',
            ]);
        }

        $firstPage = $this->getJson("/api/v1/products?category_id={$category->id}&per_page=1")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $secondPage = $this->getJson($firstPage->json('links.next'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category.id', $category->id)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 1);
        $this->assertNotSame($firstPage->json('data.0.id'), $secondPage->json('data.0.id'));

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_invalid_price_quantity_and_product_id_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $input = $this->productInput();
        $input['price'] = '3.123';
        $input['initial_quantity'] = '2147483648';

        $this->actingAs($admin, 'api')
            ->post('/api/v1/products', $input, ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price', 'initial_quantity']);

        $this->getJson('/api/v1/products/99999999999999999999999')->assertNotFound();
    }
}
