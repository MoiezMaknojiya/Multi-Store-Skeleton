<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The uploads' routes speak tus 1.0.0 (docs/UPLOADS-SPEC.md): every answer says so, and a request that speaks another
 * version is refused before it reaches the controller (412). OPTIONS is how a client asks, and needs no version.
 */
class SpeaksTus
{
    public const VERSION = '1.0.0';

    public function handle(Request $request, Closure $next): Response
    {
        $response = ! $request->isMethod('OPTIONS') && $request->header('Tus-Resumable') !== self::VERSION
            ? response()->json(['message' => 'This server speaks tus '.self::VERSION.'.'], 412, ['Tus-Version' => self::VERSION])
            : $next($request);

        $response->headers->set('Tus-Resumable', self::VERSION);

        return $response;
    }
}
