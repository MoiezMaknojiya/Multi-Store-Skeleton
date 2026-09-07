<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id', 'actor_name', 'action', 'subject_type', 'subject_id', 'description',
    ];

    /** One-line audit entry. The actor's name is snapshotted at write time so the
     *  trail stays readable after the actor is deleted. Pass $actor explicitly for
     *  actions performed while unauthenticated (e.g. password reset via email link). */
    public static function record(string $action, ?Model $subject = null, string $description = '', ?User $actor = null): void
    {
        $actor ??= auth()->user();

        static::create([
            'actor_id' => $actor?->id,
            'actor_name' => $actor->name ?? 'System',
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
        ]);
    }
}
