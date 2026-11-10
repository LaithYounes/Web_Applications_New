<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Services\InventoryGrpcClient;
use App\Modules\Orders\Models\Cart;
use App\Modules\Orders\Models\Order;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ProductMutationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_edit_catalog_fields_without_uploading_another_image(): void
    {
        Storage::fake('public');
        $this->mock(InventoryGrpcClient::class);
        $product = $this->product();
        $category = Category::create(['name' => 'New category']);
        $this->admin();

        $this->patchJson("/api/v1/products/{$product->id}", [
            'category_id' => $category->id,
            'name' => 'New name',
            'description' => 'New description',
            'price' => '19.95',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.name', 'New name')
            ->assertJsonPath('data.description', 'New description')
            ->assertJsonPath('data.price', '19.95')
            ->assertJsonPath('data.image', Storage::disk('public')->url($product->image_path));

        $this->patchJson("/api/v1/products/{$product->id}", ['description' => null])
            ->assertOk()
            ->assertJsonPath('data.description', null);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'category_id' => $category->id,
            'name' => 'New name',
            'description' => null,
            'price' => '19.95',
            'image_path' => $product->image_path,
        ]);
        Storage::disk('public')->assertExists($product->image_path);
        $this->getJson("/api/v1/products?category_id={$category->id}")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'New name');
    }

    public function test_admin_can_replace_image_using_multipart_method_override(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $this->admin();

        $response = $this->post("/api/v1/products/{$product->id}", [
            '_method' => 'PATCH',
            'image' => $this->image(),
        ], ['Accept' => 'application/json'])->assertOk();

        $newPath = $product->fresh()->image_path;
        $this->assertNotSame($product->image_path, $newPath);
        $response->assertJsonPath('data.image', Storage::disk('public')->url($newPath));
        Storage::disk('public')->assertExists($newPath);
        Storage::disk('public')->assertMissing($product->image_path);
    }

    public function test_update_rejects_invalid_fields_and_inventory_quantities(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $this->admin();

        $this->patchJson("/api/v1/products/{$product->id}", [
            'category_id' => 9999,
            'name' => str_repeat('x', 256),
            'description' => [],
            'price' => '12.501',
            'initial_quantity' => 0,
            'available_quantity' => null,
        ])->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['category_id', 'name', 'description', 'price', 'initial_quantity', 'available_quantity']);

        $this->patchJson("/api/v1/products/{$product->id}", [
            'category_id' => null,
            'name' => '',
            'price' => '0.00',
            'image' => null,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id', 'name', 'price', 'image']);

        foreach ([UploadedFile::fake()->create('file.pdf'), $this->image()->size(5121)] as $image) {
            $this->post("/api/v1/products/{$product->id}", [
                '_method' => 'PATCH',
                'image' => $image,
            ], ['Accept' => 'application/json'])->assertUnprocessable()
                ->assertJsonValidationErrors('image');
        }

        $this->assertSame($product->getAttributes(), $product->fresh()->getAttributes());
        $this->assertSame([$product->image_path], Storage::disk('public')->allFiles('products'));
    }

    public function test_an_image_write_failure_leaves_the_product_and_original_image_intact(): void
    {
        $disk = Storage::fake('public');
        $product = $this->product();
        $failingDisk = \Mockery::mock($disk);
        $failingDisk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::set('public', $failingDisk);
        $this->admin();

        $this->post("/api/v1/products/{$product->id}", [
            '_method' => 'PATCH',
            'name' => 'New name',
            'image' => $this->image(),
        ], ['Accept' => 'application/json'])->assertStatus(500);

        $this->assertSame($product->getAttributes(), $product->fresh()->getAttributes());
        $disk->assertExists($product->image_path);
    }

    public function test_an_update_failure_removes_the_new_image_and_keeps_the_original(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $this->admin();
        Product::updated(fn () => throw new RuntimeException('Simulated failure after updating the product'));

        $this->post("/api/v1/products/{$product->id}", [
            '_method' => 'PATCH',
            'name' => 'New name',
            'image' => $this->image(),
        ], ['Accept' => 'application/json'])->assertStatus(500);

        $this->assertSame($product->getAttributes(), $product->fresh()->getAttributes());
        $this->assertSame([$product->image_path], Storage::disk('public')->allFiles('products'));
    }

    public function test_edit_and_delete_require_admin_authentication(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $path = "/api/v1/products/{$product->id}";

        $this->patchJson($path, ['name' => 'New name'])->assertUnauthorized();
        $this->deleteJson($path)->assertUnauthorized();

        $this->actingAs(User::factory()->create(['role' => 'customer']), 'api');
        $this->patchJson($path, ['name' => 'New name'])->assertForbidden();
        $this->deleteJson($path)->assertForbidden();

        $this->assertSame($product->getAttributes(), $product->fresh()->getAttributes());
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_mutations_return_not_found_for_missing_invalid_or_unpublished_products(): void
    {
        Storage::fake('public');
        $product = $this->product(['pending_initial_quantity' => 3]);
        $this->admin();

        foreach ([9999, 0, 'invalid', '99999999999999999999999', $product->id] as $id) {
            $this->patchJson("/api/v1/products/{$id}", ['name' => 'New name'])->assertNotFound();
            $this->deleteJson("/api/v1/products/{$id}")->assertNotFound();
        }

        $this->assertSame($product->getAttributes(), $product->fresh()->getAttributes());
    }

    public function test_deleting_a_product_hides_it_from_catalog_and_carts_and_preserves_orders(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $customer = User::factory()->create(['role' => 'customer']);
        $cart = Cart::create(['user_id' => $customer->id]);
        $cartItem = $cart->items()->create(['product_id' => $product->id, 'quantity' => 1]);
        $order = Order::create([
            'user_id' => $customer->id,
            'status' => 'pending',
            'total_amount' => '12.50',
            'reservation_id' => (string) str()->uuid(),
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price' => '12.50',
            'quantity' => 1,
            'subtotal' => '12.50',
        ]);
        $this->mock(InventoryGrpcClient::class, function (MockInterface $inventory) use ($order) {
            $inventory->shouldReceive('releaseStock')->once()->with($order->reservation_id);
        });

        $this->admin();
        $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'Renamed', 'price' => '19.95'])->assertOk();
        $this->deleteJson("/api/v1/products/{$product->id}")->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);
        $this->assertSoftDeleted($product);
        Storage::disk('public')->assertMissing($product->image_path);

        $this->deleteJson("/api/v1/products/{$product->id}")->assertNotFound();
        $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'New name'])->assertNotFound();
        $this->getJson("/api/v1/products/{$product->id}")->assertNotFound();
        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');

        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->actingAs($customer, 'api');
        $this->getJson('/api/v1/cart')->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.total_amount', '0.00');
        $this->putJson("/api/v1/cart/items/{$cartItem->id}", ['quantity' => 2])->assertNotFound();
        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_id');
        $this->postJson('/api/v1/orders')->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->getJson("/api/v1/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.items.0.product_id', $product->id)
            ->assertJsonPath('data.items.0.product_name', $product->name)
            ->assertJsonPath('data.items.0.unit_price', '12.50');
    }

    public function test_deleting_a_product_does_not_prevent_checkout_of_other_cart_items(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $other = $this->product(['name' => 'Another product']);
        $customer = User::factory()->create(['role' => 'customer']);
        $cart = Cart::create(['user_id' => $customer->id]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 1]);
        $cart->items()->create(['product_id' => $other->id, 'quantity' => 2]);
        $this->mock(InventoryGrpcClient::class, function (MockInterface $inventory) use ($other) {
            $inventory->shouldReceive('reserveStock')->once()
                ->with(\Mockery::type('string'), [['product_id' => $other->id, 'quantity' => 2]]);
        });

        $this->admin();
        $this->deleteJson("/api/v1/products/{$product->id}")->assertOk();
        Storage::disk('public')->assertExists($other->image_path);

        $this->actingAs($customer, 'api');
        $this->getJson('/api/v1/cart')->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.product.id', $other->id);
        $this->postJson('/api/v1/orders')->assertCreated()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.product_id', $other->id)
            ->assertJsonPath('data.total_amount', '25.00');
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items', []);
    }

    public function test_a_delete_failure_keeps_the_product_and_image(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $this->admin();
        Product::softDeleted(fn () => throw new RuntimeException('Simulated failure after deleting the product'));

        $this->deleteJson("/api/v1/products/{$product->id}")->assertStatus(500);

        $this->assertNotSoftDeleted($product);
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_an_image_cleanup_failure_is_logged_after_the_product_is_deleted(): void
    {
        $disk = Storage::fake('public');
        $product = $this->product();
        $failingDisk = \Mockery::mock($disk);
        $failingDisk->shouldReceive('delete')->once()->with($product->image_path)->andReturn(false);
        Storage::set('public', $failingDisk);
        Log::spy();
        $this->admin();

        $this->deleteJson("/api/v1/products/{$product->id}")->assertOk();

        $this->assertSoftDeleted($product);
        Log::shouldHaveReceived('warning')->once()
            ->with('Could not remove product image.', ['path' => $product->image_path]);
    }

    public function test_a_shared_image_is_kept_until_the_last_product_using_it_is_deleted(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $other = $this->product(['image_path' => $product->image_path]);
        $this->admin();

        $this->deleteJson("/api/v1/products/{$product->id}")->assertOk();
        Storage::disk('public')->assertExists($product->image_path);

        $this->deleteJson("/api/v1/products/{$other->id}")->assertOk();
        Storage::disk('public')->assertMissing($product->image_path);
    }

    public function test_retrying_creation_with_the_same_key_cannot_restore_a_deleted_product(): void
    {
        Storage::fake('public');
        $this->admin();
        $category = Category::create(['name' => 'New category']);
        $headers = ['Accept' => 'application/json', 'Idempotency-Key' => 'test-product-creation'];
        $data = ['name' => 'New product', 'category_id' => $category->id, 'price' => '12.50', 'initial_quantity' => 0];

        $created = $this->post('/api/v1/products', [...$data, 'image' => $this->image()], $headers)
            ->assertCreated();
        $id = $created->json('data.id');
        $this->deleteJson("/api/v1/products/{$id}")->assertOk();

        $this->post('/api/v1/products', [...$data, 'image' => $this->image()], $headers)->assertConflict();
        $this->assertDatabaseCount('products', 1);
        $this->assertSoftDeleted('products', ['id' => $id]);
        $this->assertSame([], Storage::disk('public')->allFiles('products'));
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'api');
    }

    private function product(array $attributes = []): Product
    {
        return Product::create($attributes + [
            'category_id' => Category::firstOrCreate(['name' => 'Test category'])->id,
            'name' => 'Test product',
            'description' => 'Original description',
            'price' => '12.50',
            'image_path' => $attributes['image_path'] ?? $this->image()->store('products', 'public'),
        ])->refresh();
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'product.png',
            file_get_contents(database_path('seeders/fixtures/phone.png')),
        );
    }
}
