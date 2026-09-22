<?php

namespace App\Http\Requests\Builder;

use App\Http\Requests\Signage\StoreMediaRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A picture or a video going onto the Builder's shelf.
 *
 * The formats are the ones the player can render — `StoreMediaRequest::ALLOWED_MIMES` is the source of
 * truth for that in this app, so it is referenced rather than copied: a format added there is added here
 * the same day. The size cap is the library's too, for the same reason.
 */
class BuilderAssetRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:'.StoreMediaRequest::ALLOWED_MIMES, 'max:'.StoreMediaRequest::MAX_KILOBYTES],
            'title' => ['nullable', 'string', 'max:255'],

            // Browser-measured facts about a video, never trusted for anything but display.
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'width' => ['nullable', 'integer', 'min:1', 'max:16384'],
            'height' => ['nullable', 'integer', 'min:1', 'max:16384'],
            'poster' => ['nullable', 'string', 'starts_with:data:image/'],

            // The shop, said by the platform team only (a store's person uploads to the store they stand
            // in, and whatever they send here is not read). Whether it exists is the controller's.
            'store_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimes' => 'Only '.StoreMediaRequest::FORMATS_IN_WORDS.' can be used in an ad.',
            'file.max' => StoreMediaRequest::tooLargeMessage(),
        ];
    }
}
