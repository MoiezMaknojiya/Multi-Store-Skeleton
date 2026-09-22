<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PairingRequest;
use App\Models\Screen;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The pairing handshake, in one place.
 *
 * A TV has no login, so it proves who it is with a long random token instead.
 * Getting that token onto the TV is the whole problem: the device asks for a
 * short code, shows it on screen, and the shop owner types it into their own
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

    /**
     * Give a device a fresh pairing code. Re-registering the same device returns
     * its live code rather than minting a new one, so a page refresh does not
     * change the number a shop owner is halfway through typing.
     *
     * @return array{device_uuid: string, code: string, expires_at: string, poll_secret: string, known_device: bool}
     */
    public function register(?string $deviceUuid = null): array
    {
        $this->pruneExpired();

        $uuid = $deviceUuid && strlen($deviceUuid) <= 40 ? $deviceUuid : (string) Str::uuid();

        // A new poll secret every time: the hash on file is replaced so an old
        // secret can never keep watching a request it no longer owns.
        $pollSecret = Str::random(40);

        $existing = PairingRequest::where('device_uuid', $uuid)->first();

        // Still alive and nobody has claimed it: keep the code that is already on
        // the TV rather than changing the number under the owner's fingers.
        if ($existing && ! $existing->hasExpired() && ! $existing->claimed_screen_id) {
            $existing->update(['poll_secret_hash' => $this->hash($pollSecret)]);

            return $this->registrationPayload($existing, $pollSecret);
        }

        // Otherwise this device starts over. updateOrCreate rather than create:
        // the uuid is unique, and a device whose previous code expired (or was
        // claimed by a screen it since lost) still has a row on file — inserting a
        // second one for the same device is a constraint violation, not a new TV.
        $request = PairingRequest::updateOrCreate(
            ['device_uuid' => $uuid],
            [
                'code' => $this->generateCode(),
                'poll_secret_hash' => $this->hash($pollSecret),
                'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
                'claimed_screen_id' => null,
                'claimed_token' => null,
            ]
        );

        return $this->registrationPayload($request, $pollSecret);
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
     * @return array{device_uuid: string, code: string, expires_at: string, poll_secret: string, known_device: bool}
     */
    private function registrationPayload(PairingRequest $request, string $pollSecret): array
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
            // is open, and whoever holds a uuid has no business learning what a
            // shop calls its televisions.
            'known_device' => Screen::where('device_uuid', $request->device_uuid)->exists(),
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
