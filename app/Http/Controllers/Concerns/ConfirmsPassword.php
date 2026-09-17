<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Re-confirming the password of the person pressing the button (owner's rule, 2026-09-16).
 *
 * Big deletes ask for it — a store, an account, a role, a platform role, a member, a channel, a campaign,
 * a permission — and so does changing your own password. A session left open on a shared computer must not
 * be able to destroy or take over with two clicks.
 *
 * Call it AFTER every other refusal, so nobody types a password for something that would be refused
 * anyway. Wrong passwords are limited per person across all of these forms together, like the login
 * form, so an open session cannot be used to guess the password either.
 */
trait ConfirmsPassword
{
    /** Wrong passwords allowed per person, per minute, before a pause. */
    private const PASSWORD_ATTEMPTS = 5;

    /**
     * @param  string  $bag  the error bag of a plain form (JSON requests ignore it)
     * @param  string  $field  the field holding the password
     */
    protected function confirmPassword(Request $request, string $bag = 'default', string $field = 'password'): void
    {
        $key = 'confirm-password:'.$request->user()->id;

        if (RateLimiter::tooManyAttempts($key, self::PASSWORD_ATTEMPTS)) {
            throw ValidationException::withMessages([
                $field => 'Too many wrong passwords. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ])->errorBag($bag)->status(429);
        }

        try {
            // `bail` because `current_password` hashes what it is given: without it a password posted
            // as an array (password[]=x) failed `string` and then reached the hasher anyway — a 500 on
            // every big delete in the app, from one missing word.
            $request->validateWithBag($bag, [
                $field => ['bail', 'required', 'string', 'current_password'],
            ], [
                "{$field}.required" => 'Password is required.',
                "{$field}.current_password" => 'The password is incorrect.',
            ]);
        } catch (ValidationException $e) {
            if ($request->filled($field)) {
                RateLimiter::hit($key, 60);
            }

            throw $e;
        }

        RateLimiter::clear($key);
    }
}
