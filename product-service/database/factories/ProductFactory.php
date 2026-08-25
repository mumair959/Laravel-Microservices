<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $productNames = [
            'Laptop Pro 15',
            'Wireless Mouse',
            'USB-C Cable',
            'Mechanical Keyboard',
            'Monitor 27 inch',
            'Headphones Pro',
            'Webcam HD',
            'SSD 1TB',
            'RAM 16GB',
            'Graphics Card',
        ];

        $descriptions = [
            'High-performance computing device',
            'Ergonomic input device',
            'Fast data transfer cable',
            'Professional typing experience',
            'Crystal clear display',
            'Premium audio quality',
            'Crystal clear video capture',
            'Lightning fast storage',
            'Ultimate performance boost',
            'Gaming powerhouse',
        ];

        return [
            'name' => $this->faker->randomElement($productNames),
            'sku' => strtoupper($this->faker->unique()->bothify('PROD-????-####')),
            'description' => $this->faker->randomElement($descriptions),
            'price' => $this->faker->randomFloat(2, 10, 5000),
            'stock' => $this->faker->numberBetween(0, 500),
            'status' => $this->faker->boolean(80), // 80% chance of true
        ];
    }

    /**
     * Indicate that the product is out of stock.
     */
    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'stock' => 0,
            'status' => false,
        ]);
    }

    /**
     * Indicate that the product has high stock.
     */
    public function highStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'stock' => $this->faker->numberBetween(200, 500),
        ]);
    }

    /**
     * Indicate that the product is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => true,
        ]);
    }
}
