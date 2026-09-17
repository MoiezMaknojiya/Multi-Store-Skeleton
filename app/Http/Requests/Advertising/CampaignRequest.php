<?php

namespace App\Http\Requests\Advertising;

use App\Http\Requests\Signage\StoreMediaRequest;
use App\Models\Media;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create and update share one set of rules. The FILE is required only when there is
 * not one already — an edit that only renames a campaign or moves its dates should
 * not make somebody upload the same advert again.
 */
class CampaignRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'screen_ids' => array_values(array_filter(
                array_map('intval', (array) $this->input('screen_ids', []))
            )),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $editing = $this->route('campaign') !== null;

        return [
            'name' => ['required', 'string', 'max:120'],
            // Who the advert is FOR, kept apart from what the campaign is called so
            // that "never run this brand in that shop" is a later table, not a later
            // migration of this one.
            'advertiser_name' => ['nullable', 'string', 'max:120'],

            'file' => [
                $editing ? 'nullable' : 'required',
                'file',
                'mimes:'.StoreMediaRequest::ALLOWED_MIMES,
                'max:'.StoreMediaRequest::MAX_KILOBYTES,
            ],

            // An IMAGE's seconds on screen — or, for a video, the length the browser
            // measured. A video's form has no seconds field at all (it runs to its own
            // end), so for one this may simply be missing.
            'duration_seconds' => [$this->describesVideo() ? 'nullable' : 'required', 'integer', 'min:1', 'max:300'],
            // Browser-measured facts about a video — never trusted for identity, only
            // for shape. Same contract as a media upload.
            'width' => ['nullable', 'integer', 'min:1', 'max:16384'],
            'height' => ['nullable', 'integer', 'min:1', 'max:16384'],
            'poster' => ['nullable', 'string', 'starts_with:data:image/'],

            // The contract period. Both blank means "until I switch it off".
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],

            // The window inside a day, read on each screen's own clock. Both blank
            // means all day; an end EARLIER than the start crosses midnight.
            'start_time' => ['nullable', 'date_format:H:i', 'required_with:end_time'],
            'end_time' => ['nullable', 'date_format:H:i', 'required_with:start_time', 'different:start_time'],

            'is_active' => ['boolean'],

            // Which screens carry it. An empty list is allowed — a campaign can be
            // set up before its screens are chosen.
            'screen_ids' => ['present', 'array'],
            'screen_ids.*' => ['integer'],
        ];
    }

    /**
     * Is the advert this request describes a video? The file being uploaded decides when
     * there is one; otherwise the advert the campaign already has.
     */
    public function describesVideo(): bool
    {
        $file = $this->file('file');

        if ($file !== null && ! is_array($file) && $file->isValid()) {
            return str_starts_with((string) $file->getMimeType(), 'video/');
        }

        return $this->route('campaign')?->type === Media::TYPE_VIDEO;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose the advert to upload.',
            'file.mimes' => 'Only images (JPG, PNG, GIF, WEBP) and videos (MP4, WEBM) can be uploaded.',
            'file.max' => 'The file may not be larger than 250 MB.',
            'duration_seconds.max' => 'A single advert may not run longer than 5 minutes.',
            'ends_on.after_or_equal' => 'The end date cannot be before the start date.',
            'start_time.required_with' => 'Give both a start and an end time, or leave both blank to run all day.',
            'end_time.required_with' => 'Give both a start and an end time, or leave both blank to run all day.',
            'end_time.different' => 'The start and end time cannot be the same. To run past midnight, set an end time EARLIER than the start.',
        ];
    }
}
