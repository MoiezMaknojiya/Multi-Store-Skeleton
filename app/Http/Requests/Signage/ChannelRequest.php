<?php

namespace App\Http\Requests\Signage;

use App\Models\Channel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create and rename a channel, and say how much of it plays each time.
 */
class ChannelRequest extends FormRequest
{
    /**
     * A channel is changed only from where it can be seen: another store's channel, or the platform's from
     * inside a store, is not found (404) — before its name is ever checked against anything.
     */
    public function authorize(): bool
    {
        $channel = $this->route('channel');

        abort_if($channel !== null && ! Channel::visibleTo($this->user())->whereKey($channel->id)->exists(), 404);

        return true;
    }

    protected function prepareForValidation(): void
    {
        // Only what was actually sent: an edit that leaves a field out must not pause
        // the channel or reset its "ads each time" behind somebody's back.
        if ($this->has('is_active')) {
            $this->merge(['is_active' => $this->boolean('is_active')]);
        }

        if ($this->has('ads_per_pass')) {
            // Blank means every ad, every time.
            $this->merge(['ads_per_pass' => blank($this->input('ads_per_pass')) ? null : $this->input('ads_per_pass')]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // A store's Channels box lists the platform's channels and the store's own, so a name has to
            // tell the channel apart from every other one in that list.
            // `bail` because the closure below assumes the rules before it held: a name posted as an
            // array (name[]=x) would otherwise reach the query and bind an array as a string.
            'name' => ['bail', 'required', 'string', 'max:120', function (string $attribute, mixed $value, \Closure $fail) {
                $storeId = $this->channelStoreId();

                $taken = Channel::query()
                    ->where('name', $value)
                    ->when($this->route('channel'), fn ($query, Channel $channel) => $query->whereKeyNot($channel->id))
                    ->where(fn ($query) => $storeId === null
                        ? $query->whereNull('store_id')
                        : $query->whereNull('store_id')->orWhere('store_id', $storeId))
                    ->exists();

                if ($taken) {
                    $fail($storeId === null
                        ? 'There is already a channel with this name. Every shop sees the name, so it has to be different.'
                        : 'There is already a channel with this name in this store\'s list. Choose a different name.');
                }
            }],
            'ads_per_pass' => ['nullable', 'integer', 'min:1', 'max:'.Channel::MAX_ADS_PER_PASS],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The store the channel belongs to: an edited channel's own; a new one's is the store it is made in —
     * none when it is made above the stores.
     */
    public function channelStoreId(): ?int
    {
        $channel = $this->route('channel');

        if ($channel !== null) {
            return $channel->store_id;
        }

        return $this->user()->globalRole() !== null ? null : ((int) session('current_store_id') ?: null);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ads_per_pass.min' => 'Play at least one ad each time, or leave it blank to play them all.',
            'ads_per_pass.max' => 'A channel can play at most '.Channel::MAX_ADS_PER_PASS.' ads each time.',
        ];
    }
}
