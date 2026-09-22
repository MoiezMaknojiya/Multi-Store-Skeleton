<?php

namespace App\Http\Requests\Signage;

use App\Models\Media;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMediaRequest extends FormRequest
{
    /**
     * A file is changed only from where it can be seen: another store's is not found (404), before
     * anything sent is checked — a 422 would say the id exists.
     */
    public function authorize(): bool
    {
        $media = $this->route('media');

        abort_if($media instanceof Media && ! Media::visibleTo($this->user())->whereKey($media->id)->exists(), 404);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],

            // The file's own schedule window. Both blank means "always eligible";
            // an expiry must sit after the start or the item could never play.
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expires_at.after' => 'The expiry date must be after the start date.',
        ];
    }
}
