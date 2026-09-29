<?php

namespace App\Rules;

use App\Services\VideoDuration;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * A video no longer than a ceiling — measured from the file itself (App\Services\VideoDuration), never taken
 * from the browser, whose number is a field anybody can write by hand (owner's rules, 2026-09-28: 5 minutes in
 * a library or a channel, 30 seconds on the Ad Builder's shelf, 60 seconds in the ads network).
 *
 * A picture passes untouched, and so does anything that is not an upload (the rules before this one say so).
 * A video the server cannot measure is refused: a file whose length nobody can read is not one a screen gets.
 * Its length is rounded to the nearest second, as a phone shows it, so a "5:00" video is 5 minutes — and one
 * under half a second, which rounds to nothing, is refused too (the owner's brute-force round, 2026-09-29): it
 * was kept with no length, so a playlist timed it as an unmeasured video of two minutes and a break counted it
 * as nought.
 */
class VideoLength implements ValidationRule
{
    public function __construct(private readonly int $maxSeconds, private readonly string $noun = 'A video') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! str_starts_with((string) $value->getMimeType(), 'video/')) {
            return;
        }

        $seconds = app(VideoDuration::class)->seconds((string) $value->getRealPath());

        if ($seconds === null) {
            $fail('We could not read how long this video is. Save it again as an MP4 and upload that file.');

            return;
        }

        $rounded = (int) round($seconds);

        if ($rounded < 1) {
            $fail("{$this->noun} must be at least 1 second long.");

            return;
        }

        if ($rounded > $this->maxSeconds) {
            $fail("{$this->noun} may be at most ".self::inWords($this->maxSeconds).' long. This one is '.self::clock($rounded).'.');
        }
    }

    /** 300 as "5 minutes", 60 as "60 seconds", 90 as "1 minute 30 seconds". */
    public static function inWords(int $seconds): string
    {
        if ($seconds <= 60) {
            return $seconds.' '.($seconds === 1 ? 'second' : 'seconds');
        }

        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        return $minutes.' '.($minutes === 1 ? 'minute' : 'minutes')
            .($rest > 0 ? ' '.$rest.' '.($rest === 1 ? 'second' : 'seconds') : '');
    }

    /** 432 as "7:12", as a player shows it. */
    public static function clock(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return ($hours > 0 ? $hours.':'.str_pad((string) $minutes, 2, '0', STR_PAD_LEFT) : (string) $minutes)
            .':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }
}
