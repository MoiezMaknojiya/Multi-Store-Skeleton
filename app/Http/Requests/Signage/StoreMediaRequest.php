<?php

namespace App\Http\Requests\Signage;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMediaRequest extends FormRequest
{
    /** Formats the player can actually render. Anything else is rejected here
     *  rather than discovered on a TV in a shop. */
    public const ALLOWED_MIMES = 'jpg,jpeg,png,gif,webp,mp4,webm';

    /** 250 MB — comfortably above a long 1080p loop, well below php.ini's 2G. */
    public const MAX_KILOBYTES = 256000;

    /** ALLOWED_MIMES in words, for every message that refuses anything else. */
    public const FORMATS_IN_WORDS = 'images (JPG, PNG, GIF, WEBP) and videos (MP4, WEBM)';

    /** The refusal for a file over MAX_KILOBYTES, on every form that uploads one. */
    public static function tooLargeMessage(): string
    {
        return 'The file may not be larger than '.intdiv(self::MAX_KILOBYTES, 1024).' MB.';
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:'.self::ALLOWED_MIMES, 'max:'.self::MAX_KILOBYTES],
            'title' => ['nullable', 'string', 'max:255'],

            // Browser-measured facts about a video. Optional, and never trusted for
            // anything but display — see MediaStorage.
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'width' => ['nullable', 'integer', 'min:1', 'max:16384'],
            'height' => ['nullable', 'integer', 'min:1', 'max:16384'],
            'poster' => ['nullable', 'string', 'starts_with:data:image/'],

            // The library it joins, said by the platform team only: a shop's id, or nothing for the platform's
            // own. A store's person uploads to the store they stand in, and whatever they send here is not read.
            'store_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimes' => 'Only '.self::FORMATS_IN_WORDS.' can be uploaded.',
            'file.max' => self::tooLargeMessage(),
        ];
    }
}
