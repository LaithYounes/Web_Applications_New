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

    public function test_zero_stock_conditional_variants_and_disabled_feature(): void
    {
        config(['perf.product_etag_enabled' => true]);
        $product = $this->seedProduct();
        $this->mock(InventoryGrpcClient::class)->shouldReceive('getStock')
            ->with($product->id)->andReturn(0);
        $url = "/api/v1/products/{$product->id}";
        $first = $this->getJson($url)->assertOk()->assertJsonPath('data.available_quantity', 0);
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        foreach (['W/'.$etag, '"unrelated", W/'.$etag, '*'] as $header) {
            $this->withHeader('If-None-Match', $header)->getJson($url)
                ->assertNoContent(304)->assertHeader('ETag', $etag);
        }
        $this->withHeader('If-None-Match', '"unrelated"')->getJson($url)->assertOk();
        config(['perf.product_etag_enabled' => false]);
        $this->withHeader('If-None-Match', $etag)->getJson($url)
            ->assertOk()->assertHeaderMissing('ETag')->assertJsonPath('data.available_quantity', 0);
    }

    public function test_each_representation_field_changes_the_validator(): void
    {
        config(['perf.product_etag_enabled' => true]);
        $product = $this->seedProduct();
        $this->mock(InventoryGrpcClient::class)->shouldReceive('getStock')->andReturn(2);
        $url = "/api/v1/products/{$product->id}";
        $etag = $this->getJson($url)->assertOk()->headers->get('ETag');
        $other = Category::create(['name' => 'Other ETag category']);
        foreach (['name' => 'Changed', 'description' => 'New description', 'price' => '25.00',
            'image_path' => 'products/new.png', 'category_id' => $other->id] as $field => $value) {
            $product->update([$field => $value]);
            $next = $this->withHeader('If-None-Match', $etag)->getJson($url)->assertOk();
            $this->assertNotSame($etag, $next->headers->get('ETag'), $field);
            $etag = $next->headers->get('ETag');
        }
        $other->update(['name' => 'Renamed category']);
        $response = $this->withHeader('If-None-Match', $etag)->getJson($url)->assertOk();
        $this->assertNotSame($etag, $response->headers->get('ETag'));
    }
}
