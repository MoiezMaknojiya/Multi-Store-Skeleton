<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * One of the Ad Builder's categories — "Menu", "Beer", "Education" (owner, 2026-10-22). One list, the platform's: only above the
 * organizations is a category made, renamed or deleted (Categories, under Ad Builder), and every ad, the platform's and every
 * organization's, may be put in several. Deleting one takes it off every ad and nothing else.
 */
class AdCategory extends Model
{
    use HasFactory;

    /** As long as a name may be: a word or two on a chip. */
    public const MAX_NAME = 40;

    /** The most an ad may be put in at once. */
    public const MAX_PER_AD = 10;

    protected $fillable = ['name', 'created_by'];

    protected $hidden = ['created_by', 'pivot'];

    public function ads(): BelongsToMany
    {
        return $this->belongsToMany(BuilderAd::class, 'ad_category_builder_ad');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * A name as a person reads it, for telling two apart: accents, case, spacing and punctuation folded away, so "Menu",
     * "menu " and "Mênu" are one name.
     */
    public static function folded(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii($name)));
    }

    /** The list a picker offers: id and name, A to Z. */
    public static function forPickers(): array
    {
        return self::query()->orderBy('name')->get(['id', 'name'])->map(fn (self $category) => ['id' => $category->id, 'name' => $category->name])->all();
    }
}
