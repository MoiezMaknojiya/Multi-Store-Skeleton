<?php

declare(strict_types=1);

namespace App\Services;

use App\Cache\DeadlockRetryingDatabaseStore;
use App\Models\PairingRequest;
use App\Models\Screen;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The pairing handshake, in one place.
 *
 * A TV has no login, so it proves who it is with a long random token instead.
 * Getting that token onto the TV is the whole problem: the device asks for a
 * short code, shows it on screen, and the organization owner types it into their own
 * panel. Only then does a token exist, and only the device that asked for the
 * code can collect it.
 */
class DevicePairing
{
    /** Read off a TV across a room, so no I/O/0/1 and no lowercase. */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 6;

    /** Long enough that a code on a screen is never a stale code. */
    public const CODE_TTL_MINUTES = 15;

    /** How often a register looks again when another request for the same uuid came or went at the same moment. */
    private const REGISTER_ATTEMPTS = 3;

    /**
     * Give a device a pairing code — or, to the device that asked for it, the one it already has: a page refresh does not
     * change the number an organization owner is halfway through typing.
     *
     * Only to that device (the review of 2026-10-15). A uuid is no secret — the panel lists every screen's — so it proves
     * nothing: the request's own poll secret does. Whoever sends a uuid with a live code, or with a code claimed and its
     * token not yet collected, without that code's secret is given a request of its own (a new uuid, code and secret) and
     * the waiting one is never touched: handed the same code with a new secret, they were the one the token went to once
     * the owner typed the code from the television; and a claimed request wiped by its own television's register left the
     * screen paired with a token nobody holds. Only a code that expired unclaimed starts over under the same uuid.
     *
     * @return array{device_uuid: string, code: string, expires_at: string, poll_secret: string, known_device: bool}
     */
    public function register(?string $deviceUuid = null, ?string $pollSecret = null): array
    {
        $this->pruneExpired();

        $asked = $deviceUuid && strlen($deviceUuid) <= 40 ? $deviceUuid : null;

        for ($attempt = 1; $attempt <= self::REGISTER_ATTEMPTS; $attempt++) {
            $registered = $this->registerOnce($asked, $pollSecret);

            if ($registered !== null) {
                return $registered;
            }
        }

        // Another register of the same uuid at every try: a request of its own, under a uuid nobody else is inserting.
        return DB::transaction(fn (): array => $this->newRequest((string) Str::uuid(), $asked));
    }

    /**
     * One try at register(), or null when another request for the same uuid came or went at the same moment, or InnoDB
     * made this try a deadlock's victim — rolled back by then, nothing written, and the next try looks again.
     *
     * A uuid with no request on file is never read under a lock (the review of 2026-10-15). On MySQL a locking read of a key
     * that is not there holds the gap the key would go in, and two registers holding one gap deadlocked on their inserts (a
     * 1213, a 500): any two new sets while the table is nearly empty, and every set asking again once its code expired,
     * whose row the prune has just taken. So it is looked for before the transaction, and a uuid not on file is inserted at
     * once — a second register of it at the same moment meets the unique key, and its next try finds the row. A uuid on
     * file is held, as claim() holds it: a code typed in at this very moment is claimed before this reads it or after this
     * replaced it, never in between. Gone between the look and the lock (collected, pruned), the try ends with nothing
     * written, its gap let go with its transaction before anything is inserted.
     *
     * @return array{device_uuid: string, code: string, expires_at: string, poll_secret: string, known_device: bool}|null
     */
    private function registerOnce(?string $asked, ?string $pollSecret): ?array
    {
        $onFile = $asked !== null && PairingRequest::where('device_uuid', $asked)->exists();

        try {
            return DB::transaction(function () use ($asked, $pollSecret, $onFile): ?array {
                if (! $onFile) {
                    return $this->newRequest($asked ?? (string) Str::uuid(), $asked);
                }

                $existing = PairingRequest::where('device_uuid', $asked)->lockForUpdate()->first();

                if ($existing === null) {
                    return null;
                }

                $live = $existing->claimed_screen_id !== null || ! $existing->hasExpired();

                if ($live && $pollSecret !== null && hash_equals($existing->poll_secret_hash, $this->hash($pollSecret))) {
                    // The device that asked for it: its code and its secret as they are. A claimed one is collected by its
                    // next poll (collect()).
                    return $this->registrationPayload($existing, $pollSecret, $asked);
                }

                // A live or claimed code belongs to the device that holds its secret: anybody else starts a request of their own.
                if ($live) {
                    return $this->newRequest((string) Str::uuid(), $asked);
                }

                // A code that expired unclaimed starts over under the same uuid, on the row it already has.
                $secret = Str::random(40);
                $existing->update($this->freshCode($secret));

                return $this->registrationPayload($existing, $secret, $asked);
            });
        } catch (QueryException $e) {
            // The other register's row met at the unique key — or, the same uuid inserted twice at once just after the prune
            // took it, InnoDB's own duplicate check making one the victim of a deadlock (1213). Rolled back: look again.
            if ($e instanceof UniqueConstraintViolationException || DeadlockRetryingDatabaseStore::isADeadlock($e)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * A request of its own under $uuid, inserted: never read under a lock first (registerOnce).
     *
     * @return array{device_uuid: string, code: string, expires_at: string, poll_secret: string, known_device: bool}
     */
    private function newRequest(string $uuid, ?string $asked): array
    {
        $secret = Str::random(40);
        $request = PairingRequest::create(['device_uuid' => $uuid, ...$this->freshCode($secret)]);

        return $this->registrationPayload($request, $secret, $asked);
    }

    /**
     * A new code on the screen, answering to $secret alone, for the next CODE_TTL_MINUTES.
     *
     * @return array{code: string, poll_secret_hash: string, expires_at: CarbonInterface, claimed_screen_id: null, claimed_token: null}
     */
    private function freshCode(string $secret): array
    {
        return [
            'code' => $this->generateCode(),
            'poll_secret_hash' => $this->hash($secret),
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'claimed_screen_id' => null,
            'claimed_token' => null,
        ];
    }

    /**
     * Attach a live code to a screen and mint that screen's token. Returns false
     * when the code is unknown, expired or already claimed — the caller turns that
     * into a validation message, never a hint about which of the three it was.
     */
    public function claim(string $code, Screen $screen): bool
    {
        $token = Str::random(64);

        // The lookup happens INSIDE the transaction and holds the row, because a
        // code answers to exactly one screen. Read it outside and two people
        // typing the same code — two staff, two tabs, a retried request — can both
        // see it unclaimed and both be told "screen added". Only one token can be
        // stored, so the loser is left with a screen whose token nobody holds: it
        // never comes online and nothing ever says why. Holding the row makes the
        // second attempt wait, find it claimed, and get an honest refusal.
        return DB::transaction(function () use ($code, $screen, $token) {
            $request = PairingRequest::where('code', strtoupper(trim($code)))
                ->alive()
                ->whereNull('claimed_screen_id')
                ->lockForUpdate()
                ->first();

            if (! $request) {
                return false;
            }

            // Re-pairing an existing screen rotates its token: the old device is
            // locked out on its very next request.
            $screen->forceFill([
                'token_hash' => $this->hash($token),
                'device_uuid' => $request->device_uuid,
                'paired_at' => now(),
                'paired_by' => auth()->id(),
                'last_seen_at' => null,
            ])->save();

            $request->update([
                'claimed_screen_id' => $screen->id,
                'claimed_token' => $token,
            ]);

            return true;
        });
    }

    /**
     * The device's poll. Hands the token over exactly once — the request row is
     * destroyed in the same breath, so a replayed poll gets nothing.
     *
     * @return array{status: string, token?: string, screen_id?: int}
     */
    public function collect(string $deviceUuid, string $pollSecret): array
    {
        $request = PairingRequest::where('device_uuid', $deviceUuid)->first();

        if (! $request || ! hash_equals($request->poll_secret_hash, $this->hash($pollSecret))) {
            return ['status' => 'unknown'];
        }

        if (! $request->claimed_screen_id) {
            return ['status' => $request->hasExpired() ? 'expired' : 'pending'];
        }

        $payload = [
            'status' => 'paired',
            'token' => (string) $request->claimed_token,
            'screen_id' => (int) $request->claimed_screen_id,
        ];

        $request->delete();

        return $payload;
    }

    /** Resolve a device token to its screen. */
    public function screenForToken(string $token): ?Screen
    {
        return Screen::where('token_hash', $this->hash($token))->first();
    }

    /**
     * Codes only exist while a TV is waiting, so an expired one nobody claimed is
     * litter the moment it dies. A CLAIMED request is different: it still holds a
     * token its device has not collected yet, and that device may be slow or
     * briefly offline — those are kept for a day before being given up on.
     */
    public function pruneExpired(): int
    {
        return PairingRequest::query()
            ->where(fn (Builder $q) => $q->whereNull('claimed_screen_id')->where('expires_at', '<', now()))
            ->orWhere(fn (Builder $q) => $q->whereNotNull('claimed_screen_id')->where('expires_at', '<', now()->subDay()))
            ->delete();
    }

    /**
     * @param  ?string  $askedUuid  the uuid the device asked with, which it may have been given a new one in place of
     * @return array{device_uuid: string, code: string, expires_at: string, poll_secret: string, known_device: bool}
     */
    private function registrationPayload(PairingRequest $request, string $pollSecret, ?string $askedUuid): array
    {
        return [
            'device_uuid' => $request->device_uuid,
            'code' => $request->code,
            'expires_at' => $request->expires_at->toIso8601String(),
            'poll_secret' => $pollSecret,

            // Has this device been set up before? Only ever used to choose which
            // sentence the television prints — never to let anybody in. It decides
            // between "Add Screen" and "Replace device", and getting that wrong is
            // expensive: a returning device told to Add Screen makes a SECOND
            // screen and leaves the original stranded with all of its playlist.
            //
            // The screen's NAME deliberately does not travel with it. This endpoint
            // is open, and whoever holds a uuid has no business learning what an
            // organization calls its televisions. The uuid it asked with counts too: a set that lost its poll secret is
            // given a new uuid, and is still the screen it was.
            'known_device' => Screen::whereIn('device_uuid', array_values(array_filter([$request->device_uuid, $askedUuid])))->exists(),
        ];
    }

    private function generateCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (PairingRequest::where('code', $code)->exists());

        return $code;
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
