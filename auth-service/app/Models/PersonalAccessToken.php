<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PersonalAccessToken extends Model
{
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'last_used_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'json',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Get the tokenable model.
     */
    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Determine if the token has been revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revoked ?? false;
    }

    /**
     * Get the plain text token value.
     */
    public function getPlainTextToken(): string
    {
        return $this->getAttributes()['plain_text_token'] ?? null;
    }
}
