<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create 5 completed orders with items
        for ($i = 0; $i < 5; $i++) {
            $order = Order::factory()
                ->completed()
                ->create();

            $this->attachOrderItems($order);
        }

        // Create 5 pending orders with items
        for ($i = 0; $i < 5; $i++) {
            $order = Order::factory()
                ->pending()
                ->create();

            $this->attachOrderItems($order);
        }

        // Create 3 processing orders with items
        for ($i = 0; $i < 3; $i++) {
            $order = Order::factory()
                ->processing()
                ->create();

            $this->attachOrderItems($order);
        }

        // Create 2 cancelled orders with items
        for ($i = 0; $i < 2; $i++) {
            $order = Order::factory()
                ->cancelled()
                ->create();

            $this->attachOrderItems($order);
        }

        // Create specific test orders
        $testOrder1 = Order::create([
            'user_id' => 1,
            'status' => 'pending',
            'total_amount' => 299.97,
        ]);

        OrderItem::create([
            'order_id' => $testOrder1->id,
            'product_id' => 1,
            'product_name' => 'Test Product A',
            'quantity' => 1,
            'unit_price' => 99.99,
            'total_price' => 99.99,
        ]);

        OrderItem::create([
            'order_id' => $testOrder1->id,
            'product_id' => 2,
            'product_name' => 'Test Product B',
            'quantity' => 2,
            'unit_price' => 99.99,
            'total_price' => 199.98,
        ]);

        $testOrder2 = Order::create([
            'user_id' => 1,
            'status' => 'completed',
            'total_amount' => 149.99,
        ]);

        OrderItem::create([
            'order_id' => $testOrder2->id,
            'product_id' => 3,
            'product_name' => 'Premium Product',
            'quantity' => 1,
            'unit_price' => 149.99,
            'total_price' => 149.99,
        ]);
    }

    /**
     * Attach random order items to an order and calculate total.
     */
    private function attachOrderItems(Order $order): void
    {
        $itemCount = rand(1, 5);
        $totalAmount = 0;

        for ($i = 0; $i < $itemCount; $i++) {
            $item = OrderItem::factory()
                ->for($order)
                ->create();

            $totalAmount += $item->total_price;
        }

        // Update order total
        $order->update(['total_amount' => round($totalAmount, 2)]);
    }
}
