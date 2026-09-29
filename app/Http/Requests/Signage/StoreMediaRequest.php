<?php

namespace App\Http\Requests\Signage;

use App\Models\Media;
use App\Rules\VideoLength;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMediaRequest extends FormRequest
{
    /** Formats the player can actually render. Anything else is rejected here
     *  rather than discovered on a TV in a shop. */
    public const ALLOWED_MIMES = 'jpg,jpeg,png,gif,webp,mp4,webm';

    /** 250 MB — comfortably above a long 1080p loop, well below php.ini's 2G. */
    public const MAX_KILOBYTES = 256000;

    /**
     * A browser-drawn poster frame, as a data URL, is never longer than this: MediaStorage keeps none over 2 MB
     * decoded, and a longer string would only be decoded to be thrown away — a request made by hand could send
     * one as large as the whole upload.
     */
    public const POSTER_MAX_CHARACTERS = 3_000_000;

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
            // `bail`: the length is read only from a file that passed everything before it.
            'file' => ['bail', 'required', 'file', 'mimes:'.self::ALLOWED_MIMES, 'max:'.self::MAX_KILOBYTES, new VideoLength(Media::MAX_VIDEO_SECONDS)],
            'title' => ['nullable', 'string', 'max:255'],

            // Browser-measured facts about a video. Optional, and never trusted for anything but display — a
            // video's length is read from the file itself (MediaStorage, App\Rules\VideoLength).
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'width' => ['nullable', 'integer', 'min:1', 'max:16384'],
            'height' => ['nullable', 'integer', 'min:1', 'max:16384'],
            'poster' => ['nullable', 'string', 'starts_with:data:image/', 'max:'.self::POSTER_MAX_CHARACTERS],

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
            'title.max' => 'Title may not be longer than 255 characters.',
        ];
    }
}
