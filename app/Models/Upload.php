<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A file on its way in, sent in chunks by the tus protocol (docs/UPLOADS-SPEC.md). It belongs to the person sending
 * it and to the purpose it was opened for, counts to the shop it will join (store_id, NULL for the platform's library
 * and the ads network), and lives until the form it was chosen for makes its row — or until it expires.
 *
 * @property string $id
 * @property int $user_id
 * @property int|null $store_id
 * @property string $purpose
 * @property string $filename
 * @property string|null $file_type
 * @property int $size
 * @property int $received
 * @property Carbon $expires_at
 */
class Upload extends Model
{
    use HasUuids;

    /** What an upload may be opened for: the four places that take a file. */
    public const PURPOSES = ['media', 'channel', 'campaign', 'asset'];

    /** Where the bytes wait (config/filesystems.php): private, never served, a folder of its own per environment. */
    public const DISK = 'uploads';

    protected $fillable = ['user_id', 'store_id', 'purpose', 'filename', 'file_type', 'size', 'received', 'expires_at'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'store_id' => 'integer',
            'size' => 'integer',
            'received' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Where this upload's bytes are, on the uploads disk. */
    public function partName(): string
    {
        return "{$this->id}.part";
    }

    /** The same, as a path the filesystem can open. */
    public function partPath(): string
    {
        return Storage::disk(self::DISK)->path($this->partName());
    }

    public function isComplete(): bool
    {
        return $this->received === $this->size;
    }

    /** Bytes still to come. */
    public function remaining(): int
    {
        return max(0, $this->size - $this->received);
    }

    /** Uploads that have not expired. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
