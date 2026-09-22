<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A typeface this installation hosts itself (docs/AD-BUILDER-SPEC.md §7a).
 *
 * Downloaded once from Google Fonts, written to `fonts/{slug}/` on the public disk with a small
 * stylesheet beside it, and from then on served by us — because the television that shows the advert
 * may have no way out to the internet, and an advert in a fallback font is the wrong advert.
 */
class BuilderFont extends Model
{
    protected $fillable = ['family', 'slug', 'kind', 'weights', 'files', 'css_path', 'size', 'installed_by'];

    protected function casts(): array
    {
        return [
            'weights' => 'array',
            'files' => 'array',
            'size' => 'integer',
        ];
    }

    /** "Playfair Display" → "playfair-display". The folder name, and the row's key for a lookup. */
    public static function slugFor(string $family): string
    {
        return Str::slug($family);
    }

    /** The stylesheet a page loads to get this family. */
    public function getCssUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->css_path);
    }

    /** The weights this installation really has, smallest first — what the panel may offer. */
    public function availableWeights(): array
    {
        $weights = array_map('intval', $this->weights ?? []);
        sort($weights);

        return $weights;
    }
}
