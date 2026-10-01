<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id', 'actor_name', 'organization_id', 'action', 'subject_type', 'subject_id', 'description',
    ];

    /**
     * One-line audit entry. The actor's name is snapshotted at write time so the trail stays readable
     * after the actor is deleted. Pass $actor explicitly for actions performed while unauthenticated
     * (e.g. password reset via email link).
     *
     * The entry belongs to an organization — and shows in that organization's own Activity Log — when the subject is an
     * organization or something an organization owns (a screen, a media file, a daypart, a custom role, an organization's
     * channel), or when $organizationId names one: pass it wherever the subject is gone (a delete) or is a
     * person (a member's role changed). An account's own sign-in, profile or password, and the platform's
     * own work, belong to no organization.
     *
     * The name and the description are cut to their columns (255 and 1000 characters). A person may have a
     * first and a last name of 255 each, and a playlist copied to thirty screens names them all: on MySQL
     * in strict mode the longer value is an error, and the log line that fails is the sign-out's — the
     * person could never sign out.
     */
    public static function record(string $action, ?Model $subject = null, string $description = '', ?User $actor = null, ?int $organizationId = null): void
    {
        $actor ??= auth()->user();

        static::create([
            'actor_id' => $actor?->id,
            'actor_name' => mb_substr($actor->name ?? 'System', 0, 255),
            'organization_id' => $organizationId ?? self::organizationOf($subject),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'description' => mb_substr($description, 0, 1000),
        ]);
    }

    /** The organization a subject is, or belongs to. */
    private static function organizationOf(?Model $subject): ?int
    {
        $organizationId = $subject instanceof Organization ? $subject->getKey() : $subject?->getAttribute('organization_id');

        return $organizationId ? (int) $organizationId : null;
    }
}
