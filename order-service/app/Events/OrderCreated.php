<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Ramsey\Uuid\Uuid;

class OrderCreated
{
    use Dispatchable, SerializesModels;

    public readonly string $event_id;
    public readonly string $event;
    public readonly int $order_id;
    public readonly int $user_id;
    public readonly array $items;
    public readonly float $total_amount;

    /**
     * Create a new event instance.
     */
    public function __construct(
        int $order_id,
        int $user_id,
        array $items,
        float $total_amount,
    ) {
        $this->event_id = Uuid::uuid4()->toString();
        $this->event = 'OrderCreated';
        $this->order_id = $order_id;
        $this->user_id = $user_id;
        $this->items = $items;
        $this->total_amount = $total_amount;
    }

    /**
     * Get the event payload as an array.
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->event_id,
            'event' => $this->event,
            'order_id' => $this->order_id,
            'user_id' => $this->user_id,
            'items' => $this->items,
            'total_amount' => $this->total_amount,
        ];
    }
}
