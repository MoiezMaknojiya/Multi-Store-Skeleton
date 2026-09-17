<?php

namespace App\Http\Requests\Signage;

use App\Models\ChannelAd;
use App\Models\Media;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One ad inside a channel. Adding and editing share the rules; the FILE is required
 * only when the ad does not have one already.
 */
class ChannelAdRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $editing = $this->route('ad') !== null;

        return [
            'file' => [
                $editing ? 'nullable' : 'required',
                'file',
                'mimes:'.StoreMediaRequest::ALLOWED_MIMES,
                'max:'.StoreMediaRequest::MAX_KILOBYTES,
            ],
            // Blank takes the file's own name.
            'title' => ['nullable', 'string', 'max:255'],

            // Seconds belong to an IMAGE. A video runs to its own end and has no such
            // setting at all (owner's decision), so for a video this field is not
            // merely optional — whatever arrives is thrown away.
            'seconds' => $this->describesVideo()
                ? ['exclude']
                : ['required', 'integer', 'min:1', 'max:'.ChannelAd::MAX_IMAGE_SECONDS],

            // Browser-measured facts about a video: shape and length only, never
            // identity. The same contract as a media upload.
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'width' => ['nullable', 'integer', 'min:1', 'max:16384'],
            'height' => ['nullable', 'integer', 'min:1', 'max:16384'],
            'poster' => ['nullable', 'string', 'starts_with:data:image/'],

            // Both blank means "until it is taken out".
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ];
    }

    /**
     * Is the ad this request describes a video? The file being uploaded decides when
     * there is one; otherwise the file the ad already has.
     */
    public function describesVideo(): bool
    {
        $file = $this->file('file');

        if ($file !== null && ! is_array($file) && $file->isValid()) {
            return str_starts_with((string) $file->getMimeType(), 'video/');
        }

        return $this->route('ad')?->type === Media::TYPE_VIDEO;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose the ad to upload.',
            'file.mimes' => 'Only images (JPG, PNG, GIF, WEBP) and videos (MP4, WEBM) can be uploaded.',
            'file.max' => 'The file may not be larger than 250 MB.',
            'seconds.required' => 'Say how many seconds the image stays on screen.',
            'seconds.max' => 'An image may not stay up longer than '.ChannelAd::MAX_IMAGE_SECONDS.' seconds.',
            'ends_on.after_or_equal' => 'The end date cannot be before the start date.',
        ];
    }
}
