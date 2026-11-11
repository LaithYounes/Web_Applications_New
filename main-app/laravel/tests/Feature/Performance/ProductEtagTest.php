<?php

namespace Tests\Feature\Performance;

use App\Modules\Inventory\Exceptions\InventoryServiceException;
use App\Modules\Inventory\Services\InventoryGrpcClient;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ProductEtagTest extends TestCase
{
    use RefreshDatabase;

    private function seedProduct(): Product
    {
        $category = Category::create(['name' => 'ETag Cat', 'description' => 'd']);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'ETag Product',
            'description' => 'd',
            'price' => '19.99',
            'image_path' => 'products/etag.png',
            'pending_initial_quantity' => null,
        ]);
    }

    public function test_product_show_returns_etag_and_304_when_unchanged(): void
    {
        config(['perf.product_etag_enabled' => true]);
        $product = $this->seedProduct();

        $this->mock(InventoryGrpcClient::class, function (MockInterface $mock) use ($product) {
            $mock->shouldReceive('getStock')->with($product->id)->andReturn(7);
        });

        $first = $this->getJson("/api/v1/products/{$product->id}");
        $first->assertOk()
            ->assertHeader('ETag')
            ->assertJsonPath('data.available_quantity', 7);
        $this->assertStringContainsString('private', (string) $first->headers->get('Cache-Control'));
        $this->assertStringContainsString('must-revalidate', (string) $first->headers->get('Cache-Control'));

        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->withHeader('If-None-Match', $etag)
            ->getJson("/api/v1/products/{$product->id}")
            ->assertStatus(304)
            ->assertHeader('ETag', $etag)
            ->assertNoContent(304);
    }

    public function test_stock_change_prevents_stale_304(): void
    {
        config(['perf.product_etag_enabled' => true]);
        $product = $this->seedProduct();
        $stock = 3;

        $this->mock(InventoryGrpcClient::class, function (MockInterface $mock) use ($product, &$stock) {
            $mock->shouldReceive('getStock')->with($product->id)->andReturnUsing(function () use (&$stock) {
                return $stock;
            });
        });

        $etag = $this->getJson("/api/v1/products/{$product->id}")->headers->get('ETag');
        $stock = 9;

        $this->withHeader('If-None-Match', $etag)
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.available_quantity', 9);
    }

    public function test_inventory_failure_does_not_return_304(): void
    {
        config(['perf.product_etag_enabled' => true]);
        $product = $this->seedProduct();

        $this->mock(InventoryGrpcClient::class, function (MockInterface $mock) use ($product) {
            $mock->shouldReceive('getStock')->with($product->id)
                ->andThrow(new InventoryServiceException('down'));
        });

        $this->withHeader('If-None-Match', '"anything"')
            ->getJson("/api/v1/products/{$product->id}")
            ->assertStatus(503)
            ->assertJsonPath('success', false);
    }
}
