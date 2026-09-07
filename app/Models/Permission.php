<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $fillable = ['name', 'label'];

    protected $appends = ['display_name'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_has_permissions')
            ->withTimestamps();
    }

    /** Human-readable label, falling back to the raw permission name when unset. */
    public function getDisplayNameAttribute(): string
    {
        return $this->label ?: $this->name;
    }
}
