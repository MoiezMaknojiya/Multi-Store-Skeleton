<?php

namespace App\Http\Middleware;

use App\Services\DevicePairing;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A screen has no session and no cookie — it carries the token it was handed at
 * pairing. This resolves that token to its Screen and attaches it to the request,
 * or answers 401 so the player wipes its token and returns to the pairing code.
 */
class AuthenticateDevice
{
    public function __construct(private readonly DevicePairing $pairing) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?? $request->header('X-Device-Token');

        $screen = $token ? $this->pairing->screenForToken($token) : null;

        if (! $screen) {
            // Deliberately identical for a missing, malformed, revoked or unknown
            // token: the player's response to all four is the same.
            return response()->json(['message' => 'This screen is not paired.'], 401);
        }

        $request->attributes->set('screen', $screen);

        return $next($request);
    }
}
