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
     * Its name alone (owner, 2026-10-01): when a file plays is said on its playlist line.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Title is required.',
            'title.max' => 'Title may not be longer than 255 characters.',
        ];
    }
}
