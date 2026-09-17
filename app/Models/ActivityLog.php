<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id', 'actor_name', 'store_id', 'action', 'subject_type', 'subject_id', 'description',
    ];

    /**
     * One-line audit entry. The actor's name is snapshotted at write time so the trail stays readable
     * after the actor is deleted. Pass $actor explicitly for actions performed while unauthenticated
     * (e.g. password reset via email link).
     *
     * The entry belongs to a store — and shows in that store's own Activity Log — when the subject is a
     * store or something a store owns (a screen, a media file, a daypart, a custom role, a store's
     * channel), or when $storeId names one: pass it wherever the subject is gone (a delete) or is a
     * person (a member's role changed). An account's own sign-in, profile or password, and the platform's
     * own work, belong to no store.
     */
    public static function record(string $action, ?Model $subject = null, string $description = '', ?User $actor = null, ?int $storeId = null): void
    {
        $actor ??= auth()->user();

        static::create([
            'actor_id' => $actor?->id,
            'actor_name' => $actor->name ?? 'System',
            'store_id' => $storeId ?? self::storeOf($subject),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
        ]);
    }

    /** The store a subject is, or belongs to. */
    private static function storeOf(?Model $subject): ?int
    {
        $storeId = $subject instanceof Store ? $subject->getKey() : $subject?->getAttribute('store_id');

        return $storeId ? (int) $storeId : null;
    }
}
