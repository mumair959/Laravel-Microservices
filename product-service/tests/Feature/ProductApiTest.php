<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_can_be_listed_with_pagination(): void
    {
        Product::create($this->productData(['sku' => 'LAP-010']));
        Product::create($this->productData(['sku' => 'LAP-011']));

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'data' => ['data', 'current_page', 'per_page']]);
    }

    public function test_products_can_be_filtered_by_status_and_search(): void
    {
        Product::create($this->productData(['name' => 'Laptop', 'sku' => 'LAP-013', 'status' => true]));
        Product::create($this->productData(['name' => 'Monitor', 'sku' => 'MON-001', 'status' => false]));

        $this->getJson('/api/v1/products?status=1&search=LAP-013')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.sku', 'LAP-013');
    }

    public function test_product_can_be_created(): void
    {
        $this->postJson('/api/v1/products', $this->productData())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sku', 'LAP-001');

        $this->assertDatabaseHas('products', ['sku' => 'LAP-001']);
    }

    public function test_product_creation_requires_valid_fields(): void
    {
        $this->postJson('/api/v1/products', [
            'name' => '',
            'sku' => 'LAP-002',
            'price' => -1,
            'stock' => -1,
            'status' => 'invalid',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'price', 'stock', 'status']);
    }

    public function test_product_can_be_shown(): void
    {
        $product = Product::create($this->productData(['sku' => 'LAP-012']));

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $product->id);
    }

    public function test_product_can_be_updated_without_changing_its_sku(): void
    {
        $product = Product::create($this->productData(['sku' => 'LAP-003']));

        $this->putJson("/api/v1/products/{$product->id}", $this->productData([
            'sku' => 'LAP-003',
            'price' => 1299.99,
        ]))
            ->assertOk()
            ->assertJsonPath('data.price', '1299.99');
    }

    public function test_duplicate_sku_is_rejected(): void
    {
        Product::create($this->productData(['sku' => 'LAP-004']));

        $this->postJson('/api/v1/products', $this->productData(['sku' => 'LAP-004']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);
    }

    public function test_product_can_be_deleted(): void
    {
        $product = Product::create($this->productData(['sku' => 'LAP-005']));

        $this->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_missing_product_returns_not_found(): void
    {
        $this->getJson('/api/v1/products/999999')
            ->assertNotFound()
            ->assertJson(['success' => false, 'data' => null]);
    }

    private function productData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Laptop',
            'sku' => 'LAP-001',
            'description' => 'A useful laptop',
            'price' => 999.99,
            'stock' => 10,
            'status' => true,
        ], $overrides);
    }
}