<?php

namespace App\Http\Requests\Signage;

use App\Models\Channel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create and rename a channel, and say how much of it plays each time.
 */
class ChannelRequest extends FormRequest
{
    /**
     * A channel is changed only from where it can be seen: another organization's channel, or the platform's from
     * inside an organization, is not found (404) — before its name is ever checked against anything.
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
            // An organization's Channels box lists the platform's channels and the organization's own, so a name has to
            // tell the channel apart from every other one in that list.
            // `bail` because the closure below assumes the rules before it held: a name posted as an
            // array (name[]=x) would otherwise reach the query and bind an array as a string.
            'name' => ['bail', 'required', 'string', 'max:120', function (string $attribute, mixed $value, \Closure $fail) {
                $organizationId = $this->channelOrganizationId();

                $taken = Channel::query()
                    ->where('name', $value)
                    ->when($this->route('channel'), fn (Builder $query, Channel $channel) => $query->whereKeyNot($channel->id))
                    ->where(fn (Builder $query) => $organizationId === null
                        ? $query->whereNull('organization_id')
                        : $query->whereNull('organization_id')->orWhere('organization_id', $organizationId))
                    ->exists();

                if ($taken) {
                    $fail($organizationId === null
                        ? 'There is already a channel with this name. Every organization sees the name, so it has to be different.'
                        : 'There is already a channel with this name in this organization\'s list. Choose a different name.');
                }
            }],
            'ads_per_pass' => ['nullable', 'integer', 'min:1', 'max:'.Channel::MAX_ADS_PER_PASS],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The organization the channel belongs to: an edited channel's own; a new one's is the organization it is made in —
     * none when it is made above the organizations.
     */
    public function channelOrganizationId(): ?int
    {
        $channel = $this->route('channel');

        if ($channel !== null) {
            return $channel->organization_id;
        }

        return $this->user()->globalRole() !== null ? null : ((int) session('current_organization_id') ?: null);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // The same words as the form's own check (channels-table.js), for every way the number can be wrong.
            'ads_per_pass.integer' => 'Enter a number from 1 to '.Channel::MAX_ADS_PER_PASS.', or leave it blank to play every ad.',
            'ads_per_pass.min' => 'Enter a number from 1 to '.Channel::MAX_ADS_PER_PASS.', or leave it blank to play every ad.',
            'ads_per_pass.max' => 'Enter a number from 1 to '.Channel::MAX_ADS_PER_PASS.', or leave it blank to play every ad.',
            'name.required' => 'Channel name is required.',
            'name.max' => 'Channel name may not be longer than 120 characters.',
        ];
    }
}
