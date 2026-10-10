<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A version of the TV app, uploaded on Developer → TV App (owner, 2026-10-10). The newest version code is what every TV is
 * offered (/player-app/latest.json); the file lives on the public disk, so a television downloads it like any other file.
 */
class PlayerAppRelease extends Model
{
    /** The app every release must be: the Android player (its own project, outside this repo). */
    public const PACKAGE = 'com.thedisplaysolution.player';

    /** The largest APK taken. The app is some 12 MB; this leaves it room to grow. */
    public const MAX_BYTES = 200 * 1024 * 1024;

    /** Where the files go on the public disk. */
    public const FOLDER = 'player-app';

    /** The refusal for any other kind of file, the same before a byte is sent and once it is in. */
    public const ONLY_AN_APK = 'Only the TV app can be uploaded here: an Android app, an .apk file.';

    /** The refusal for an APK over MAX_BYTES. */
    public static function tooLargeMessage(): string
    {
        return 'The app may not be larger than '.intdiv(self::MAX_BYTES, 1024 * 1024).' MB.';
    }

    protected $fillable = ['version_code', 'version_name', 'version_read_from_file', 'path', 'size', 'sha256', 'notes', 'uploaded_by'];

    protected function casts(): array
    {
        return [
            'version_code' => 'integer',
            'version_read_from_file' => 'boolean',
            'size' => 'integer',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Newest first. */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByDesc('version_code');
    }

    /** The version every TV is offered: the newest there is, or none. */
    public static function current(): ?self
    {
        return self::query()->newestFirst()->first();
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    /** What a television reads to decide whether to offer this version (resources of the Android app's AppUpdater). */
    public function forTelevisions(): array
    {
        return [
            'package' => self::PACKAGE,
            'version_code' => $this->version_code,
            'version_name' => $this->version_name,
            'url' => $this->url(),
            'size' => $this->size,
            'sha256' => $this->sha256,
            'notes' => $this->notes,
            'published_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** "TheDisplaySolution-1.0-1.apk": the name the file is kept and downloaded as. */
    public static function fileNameFor(string $versionName, int $versionCode): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $versionName) ?: 'version';

        return "TheDisplaySolution-{$name}-{$versionCode}.apk";
    }
}
