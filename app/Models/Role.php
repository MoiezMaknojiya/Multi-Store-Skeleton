<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = ['name', 'created_by', 'is_global', 'is_signup_default', 'store_id'];

    protected function casts(): array
    {
        return [
            'is_global' => 'boolean',
            'is_signup_default' => 'boolean',
        ];
    }

    /** Global roles are assigned on the store_id = 0 sentinel row, with no store.
     *  The Super-Admin role is global by name for backward compatibility. */
    public function isGlobal(): bool
    {
        return $this->is_global || $this->name === 'Super-Admin';
    }

    public function scopeVisibleTo($query, User $user)
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        // Global users assign across every store, so they see every store-level
        // role regardless of creator — but global roles themselves (their own
        // included, and Super-Admin) stay super-admin territory.
        if ($user->globalRole()) {
            return $query->where('is_global', false)->where('name', '!=', 'Super-Admin');
        }

        // Store users: roles are isolated PER STORE. You only see the roles you
        // created in the store you are currently working in — a role made in another
        // store is invisible here, and therefore cannot be edited, deleted or
        // assigned from here. With no store selected there is nothing to see.
        $currentStoreId = session('current_store_id');

        if (! $currentStoreId) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where('created_by', $user->id)->where('store_id', $currentStoreId);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_has_permissions')->withTimestamps();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Users currently assigned this role at any store (including the store_id = 0 global sentinel). */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'store_user')->withPivot('store_id')->withTimestamps();
    }
}
