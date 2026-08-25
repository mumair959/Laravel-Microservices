<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedEvent extends Model
{
    protected $table = 'processed_events';

    protected $fillable = [
        'event_id',
        'event_type',
        'order_id',
        'user_id',
        'payload',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'json',
            'processed_at' => 'datetime',
        ];
    }
}
