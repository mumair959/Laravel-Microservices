<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create 5 high-stock, active products
        Product::factory(5)
            ->highStock()
            ->active()
            ->create();

        // Create 10 regular products
        Product::factory(10)->create();

        // Create 3 out of stock products
        Product::factory(3)
            ->outOfStock()
            ->create();

        // Create specific test products
        Product::create([
            'name' => 'Test Product A',
            'sku' => 'TEST-001',
            'description' => 'A test product for API testing',
            'price' => 99.99,
            'stock' => 50,
            'status' => true,
        ]);

        Product::create([
            'name' => 'Test Product B',
            'sku' => 'TEST-002',
            'description' => 'Another test product for API testing',
            'price' => 149.99,
            'stock' => 30,
            'status' => true,
        ]);

        Product::create([
            'name' => 'Unavailable Product',
            'sku' => 'UNAVAIL-001',
            'description' => 'This product is currently unavailable',
            'price' => 199.99,
            'stock' => 0,
            'status' => false,
        ]);
    }
}
