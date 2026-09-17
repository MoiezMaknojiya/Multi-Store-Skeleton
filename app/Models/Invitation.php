<?php

namespace App\Models;

use App\Notifications\InvitationNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * An open invitation to join a store — or, with no store, the platform team.
 *
 * The token is shown exactly once, in the email link; only its SHA-256 is stored, so a
 * leaked database row cannot be turned into an accepted invitation. A row exists only while
 * the invitation is open: accepting, declining or revoking deletes it.
 */
class Invitation extends Model
{
    use HasFactory;

    /** How long a link works. Resending starts the week again with a new link. */
    public const LIFETIME_DAYS = 7;

    protected $fillable = ['store_id', 'email', 'role_id', 'token_hash', 'invited_by', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Open an invitation. Returns the row and the plain token — which goes into the email and nowhere else.
     *
     * @return array{0: self, 1: string}
     */
    public static function open(?Store $store, string $email, Role $role, User $inviter): array
    {
        $token = Str::random(64);

        $invitation = self::create([
            'store_id' => $store?->id,
            'email' => self::normalizeEmail($email),
            'role_id' => $role->id,
            'token_hash' => self::hashToken($token),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ]);

        return [$invitation, $token];
    }

    /** The invitation a link points at, whatever its state — or null when there is none. */
    public static function findByToken(string $token): ?self
    {
        return self::where('token_hash', self::hashToken($token))->first();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * Email the link. A mail server that refuses is reported and answered with false, never
     * thrown: the invitation already stands and can be sent again, while a 500 would leave the
     * person inviting unsure whether anything had happened at all.
     */
    public function sendLink(string $token): bool
    {
        try {
            Notification::route('mail', $this->email)->notify(new InvitationNotification($this, $token));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** A new link and a new week; the old link stops working at once. Returns the new token. */
    public function renew(): string
    {
        $token = Str::random(64);

        $this->update([
            'token_hash' => self::hashToken($token),
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ]);

        return $token;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isForPlatform(): bool
    {
        return $this->store_id === null;
    }

    /** Whether the invitation is for this person's email address. */
    public function isFor(User $user): bool
    {
        return self::normalizeEmail($user->email) === $this->email;
    }

    public function scopeForStore(Builder $query, Store $store): Builder
    {
        return $query->where('store_id', $store->id);
    }

    public function scopeForPlatform(Builder $query): Builder
    {
        return $query->whereNull('store_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
