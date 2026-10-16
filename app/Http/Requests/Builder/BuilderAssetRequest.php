<?php

namespace App\Http\Requests\Builder;

use App\Http\Requests\Concerns\TakesAFinishedUpload;
use App\Http\Requests\Signage\StoreMediaRequest;
use App\Models\BuilderAsset;
use App\Rules\VideoLength;
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
    use TakesAFinishedUpload;

    /** The refusal of a shelf that is not one of the Owner list's (`organization_id`). */
    private const SHELF_FROM_THE_LIST = 'Choose Platform or one organization from the Owner list.';

    /** A file sent in chunks arrives as `upload`, and is the `file` below from here on (docs/UPLOADS-SPEC.md). */
    protected function prepareForValidation(): void
    {
        $this->takeFinishedUpload('asset');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // A video on the shelf is 30 seconds at most: in a design it repeats for as long as the ad is on screen
            // (BuilderAsset::MAX_VIDEO_SECONDS). `bail`: see StoreMediaRequest.
            'file' => ['bail', 'required', 'file', 'mimes:'.StoreMediaRequest::ALLOWED_MIMES, 'max:'.StoreMediaRequest::MAX_KILOBYTES, new VideoLength(BuilderAsset::MAX_VIDEO_SECONDS)],
            'title' => ['nullable', 'string', 'max:255'],

            // Browser-measured facts about a video, never trusted for anything but display.
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'width' => ['nullable', 'integer', 'min:1', 'max:16384'],
            'height' => ['nullable', 'integer', 'min:1', 'max:16384'],
            'poster' => ['nullable', 'string', 'starts_with:data:image/', 'max:'.StoreMediaRequest::POSTER_MAX_CHARACTERS],

            // The shelf it joins, said by the platform team only: an organization's id from the Owner list, or nothing for the
            // platform's own. An organization's person uploads to the organization they stand in, and whatever they send here is
            // dropped unread — their page has no Owner list to say it in. Whether it exists is the controller's.
            'organization_id' => $this->user()?->globalRole() !== null ? ['nullable', 'integer', 'min:1'] : ['exclude'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimes' => 'Only '.StoreMediaRequest::FORMATS_IN_WORDS.' can be uploaded.',
            'file.max' => StoreMediaRequest::tooLargeMessage(),
            // The Assets page's Owner list, as a request made by hand might bend it: a list, a word, or nought.
            'organization_id.integer' => self::SHELF_FROM_THE_LIST,
            'organization_id.min' => self::SHELF_FROM_THE_LIST,
        ];
    }
}
