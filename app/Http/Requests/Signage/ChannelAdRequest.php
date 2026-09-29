<?php

namespace App\Http\Requests\Signage;

use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Rules\VideoLength;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One ad inside a channel (docs/CHANNEL-CONTENT-SPEC.md). Its file comes from a media library, one of two ways:
 * `media_id` — a row already in the library, which is also how a published Ad Builder ad is chosen — or a fresh
 * `file`, which joins the channel's library first. Adding needs one of the two; editing may swap it or keep it.
 */
class ChannelAdRequest extends FormRequest
{
    /** The library row named by `media_id`, once looked up. */
    private Media|false|null $chosen = false;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $editing = $this->route('ad') !== null;

        return [
            // `bail`: the closure below assumes an id; an array or a word stops at `integer`.
            'media_id' => [
                'bail',
                $editing ? 'nullable' : 'required_without:file',
                'nullable',
                'integer',
                'min:1',
                function (string $attribute, mixed $value, Closure $fail) {
                    $media = $this->chosenMedia();

                    if ($media === null) {
                        $fail('Choose a file from this channel\'s library.');

                        return;
                    }

                    // A file a playlist holds stays out of channels (owner's rule, 2026-09-26): on a screen that
                    // also carries this channel it would play twice. The picker never offers one — this is the
                    // wall behind it (and ChannelAdController reads it again under the file's lock). The file an
                    // ad already shows is its own, and saving the ad keeps it.
                    if ((int) $media->id !== (int) $this->route('ad')?->media_id
                        && ($keptOut = $media->keptOutOfChannelsMessage()) !== null) {
                        $fail($keptOut);
                    }
                },
            ],
            // `bail`: the length is read only from a file that passed everything before it.
            'file' => [
                'bail',
                $editing ? 'nullable' : 'required_without:media_id',
                'nullable',
                'prohibits:media_id',
                'file',
                'mimes:'.StoreMediaRequest::ALLOWED_MIMES,
                'max:'.StoreMediaRequest::MAX_KILOBYTES,
                new VideoLength(Media::MAX_VIDEO_SECONDS),
            ],
            // Blank takes the file's own title.
            'title' => ['nullable', 'string', 'max:255'],

            // Seconds belong to an IMAGE. A video runs to its own end, and an Ad Builder page for the length its
            // design says (Media::ownLength()), and neither has such a setting at all (owner's decisions) — for
            // them this field is not merely optional: whatever arrives is thrown away.
            'seconds' => $this->runsItsOwnLength()
                ? ['exclude']
                : ['required', 'integer', 'min:'.PlaylistItem::MIN_IMAGE_SECONDS, 'max:'.ChannelAd::MAX_IMAGE_SECONDS],

            // Browser-measured facts about an uploaded video: shape and length only, never identity. The
            // same contract as a media upload.
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'width' => ['nullable', 'integer', 'min:1', 'max:16384'],
            'height' => ['nullable', 'integer', 'min:1', 'max:16384'],
            'poster' => ['nullable', 'string', 'starts_with:data:image/', 'max:'.StoreMediaRequest::POSTER_MAX_CHARACTERS],

            // Both blank means "until it is taken out".
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ];
    }

    /**
     * The library row `media_id` names — only a row this channel may show: a shop's channel takes that shop's
     * library alone; the platform's channel takes its own library or any shop's (owner, 2026-09-19). Anything
     * else is null, which the rule above turns into a 422 rather than a hint that the id exists.
     */
    public function chosenMedia(): ?Media
    {
        if ($this->chosen !== false) {
            return $this->chosen;
        }

        $id = $this->input('media_id');
        $channel = $this->route('channel');

        if (! is_scalar($id) || ! ctype_digit((string) $id) || ! $channel instanceof Channel) {
            return $this->chosen = null;
        }

        return $this->chosen = Media::query()
            ->whereKey((int) $id)
            ->when(! $channel->isPlatformChannel(), fn (Builder $query) => $query->where('store_id', $channel->store_id))
            ->first();
    }

    /**
     * Does the ad this request describes run for its file's own length — a video, or an Ad Builder page whose
     * design says how long? An uploaded file decides when there is one (a picture never does); then the library
     * row chosen; otherwise the file the ad already shows.
     */
    public function runsItsOwnLength(): bool
    {
        if ($this->describesVideo()) {
            return true;
        }

        $file = $this->file('file');

        if ($file !== null && ! is_array($file) && $file->isValid()) {
            return false;
        }

        $media = filled($this->input('media_id')) ? $this->chosenMedia() : $this->route('ad')?->media;

        return $media?->ownLength() !== null;
    }

    /**
     * Is the ad this request describes a video? The file being uploaded decides when there is one; then the
     * library row chosen; otherwise the file the ad already shows.
     */
    public function describesVideo(): bool
    {
        $file = $this->file('file');

        if ($file !== null && ! is_array($file) && $file->isValid()) {
            return str_starts_with((string) $file->getMimeType(), 'video/');
        }

        if (filled($this->input('media_id'))) {
            return $this->chosenMedia()?->type === Media::TYPE_VIDEO;
        }

        return $this->route('ad')?->type === Media::TYPE_VIDEO;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'media_id.required_without' => 'Choose a file from the library, an ad, or upload one.',
            'file.required_without' => 'Choose a file from the library, an ad, or upload one.',
            'file.prohibits' => 'Choose a file from the library or upload one — not both.',
            'file.mimes' => 'Only '.StoreMediaRequest::FORMATS_IN_WORDS.' can be uploaded.',
            'file.max' => StoreMediaRequest::tooLargeMessage(),
            'seconds.required' => 'Say how many seconds it stays on screen.',
            'seconds.integer' => 'Give the seconds as a whole number.',
            'seconds.min' => 'A picture stays on screen for at least '.PlaylistItem::MIN_IMAGE_SECONDS.' seconds.',
            'seconds.max' => 'A picture stays on screen for at most '.VideoLength::inWords(ChannelAd::MAX_IMAGE_SECONDS).'.',
            'title.max' => 'Title may not be longer than 255 characters.',
            'ends_on.after_or_equal' => 'The end date cannot be before the start date.',
        ];
    }
}
