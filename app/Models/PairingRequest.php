<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PairingRequest extends Model
{
    protected $fillable = [
        'device_uuid', 'code', 'poll_secret_hash', 'expires_at',
        'claimed_screen_id', 'claimed_token',
    ];

    protected $hidden = ['poll_secret_hash', 'claimed_token'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            // The device's token sits here for the few seconds between the owner
            // claiming the code and the TV's next poll — encrypted at rest, and the
            // row is deleted the moment it is handed over.
            'claimed_token' => 'encrypted',
        ];
    }

    public function scopeAlive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
