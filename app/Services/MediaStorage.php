<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BuilderAsset;
use App\Models\Media;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Puts an uploaded file on disk and works out everything the library needs to
 * describe it: type, dimensions, orientation, length and a thumbnail. A picture is
 * made light for the televisions first (PictureOptimizer), at every door.
 *
 * Thumbnails are made with GD, which ships with PHP — no image package is added
 * for this. GD cannot open a video, and ffmpeg is not available, so a video's
 * poster frame is captured in the BROWSER at upload time (a <video> drawn onto a
 * <canvas>) and posted alongside the file as a data URL, with its dimensions. Its
 * LENGTH is read from the file itself (VideoDuration): the browser's is a number
 * anybody can write by hand.
 *
 * An organization's files are kept only while the organization has room for them (OrganizationStorage).
 */
class MediaStorage
{
    public function __construct(
        private readonly VideoDuration $durations,
        private readonly OrganizationStorage $quota,
        private readonly DiskGuard $disk,
        private readonly PictureOptimizer $pictures,
    ) {}

    /** Longest edge of a generated thumbnail, in pixels. */
    private const THUMB_MAX = 480;

    /** A browser-supplied poster larger than this is ignored, not stored. */
    private const POSTER_MAX_BYTES = 2 * 1024 * 1024;

    /** Nor one wider or taller than this — a poster is a thumbnail, not a canvas to fill memory with. */
    private const POSTER_MAX_SIDE = 4096;

    /**
     * Store the file in a library and return the attributes for a Media row: an organization's library
     * (`media/{organization}/`), or the platform's own when there is no organization (`media/platform/`).
     *
     * @param  array<string, mixed>  $clientMeta  browser-measured duration/width/height/poster
     * @return array<string, mixed>
     */
    public function store(UploadedFile $file, ?int $organizationId, array $clientMeta = []): array
    {
        $folder = $organizationId === null ? 'media/platform' : "media/{$organizationId}";

        return ['organization_id' => $organizationId, ...$this->put($file, $folder, $clientMeta)];
    }

    /**
     * Put an upload into a library and make its row: the one way a library file is born, whether it was
     * uploaded on the Media page or inside a channel (docs/CHANNEL-CONTENT-SPEC.md).
     *
     * @param  array<string, mixed>  $clientMeta  browser-measured duration/width/height/poster
     */
    public function addToLibrary(UploadedFile $file, ?int $organizationId, array $clientMeta, ?string $typedTitle, ?int $createdBy): Media
    {
        // The server's own disk first, whoever uploads (DiskGuard) — the platform's library included.
        $this->disk->assertRoomFor((int) $file->getSize());

        // A full organization is told before its upload is even written. The platform's own library has no wall.
        if ($organizationId !== null) {
            $this->quota->assertRoomFor($organizationId, (int) $file->getSize());
        }

        $attributes = [
            ...$this->store($file, $organizationId, $clientMeta),
            'title' => $this->titleFor($typedTitle, $file),
            'created_by' => $createdBy,
        ];

        /** @var Media */
        return $this->keptWithinTheWall($organizationId, $attributes, fn () => Media::create($attributes));
    }

    /**
     * Put a file on an Ad Builder shelf and make its row: an organization's — its files count toward the organization's storage like
     * the library's (the shelf is a way onto the disk as much as the Media page is) — or, with no organization, the shelf the
     * platform shares with every organization, which has no wall of its own.
     *
     * @param  array<string, mixed>  $clientMeta  browser-measured width/height/poster
     */
    public function addBuilderAsset(UploadedFile $file, ?int $organizationId, array $clientMeta, string $title, ?int $createdBy): BuilderAsset
    {
        $this->disk->assertRoomFor((int) $file->getSize());

        if ($organizationId !== null) {
            $this->quota->assertRoomFor($organizationId, (int) $file->getSize());
        }

        $stored = $this->storeBuilderAsset($file, $organizationId, $clientMeta);

        /** @var BuilderAsset */
        return $this->keptWithinTheWall($organizationId, $stored, fn () => BuilderAsset::fromStoredFile($organizationId, $title, $stored, $createdBy));
    }

    /**
     * Make the row for files just written: an organization's only while it has room for them and their preview — decided
     * under the organization's lock (OrganizationStorage::withRoom) — and the platform's, which has no wall, at once. Refused, or
     * failed for any reason, the files go again: nothing is left on disk that no row names.
     *
     * @param  array<string, mixed>  $stored
     * @param  Closure(): Model  $create
     */
    private function keptWithinTheWall(?int $organizationId, array $stored, Closure $create): Model
    {
        try {
            return $organizationId === null ? $create() : $this->quota->withRoom($organizationId, $this->bytesOf($stored), $create);
        } catch (Throwable $refused) {
            $this->deleteFiles((string) $stored['disk'], (string) $stored['path'], $stored['thumbnail_path']);

            throw $refused;
        }
    }

    /**
     * What a stored file weighs: itself, as its row records it, and its preview on disk.
     *
     * @param  array<string, mixed>  $stored
     */
    private function bytesOf(array $stored): int
    {
        return (int) $stored['size'] + ($stored['thumbnail_path'] === null ? 0 : $this->sizeOnDisk((string) $stored['disk'], (string) $stored['thumbnail_path']));
    }

    private function sizeOnDisk(string $disk, string $path): int
    {
        try {
            return (int) Storage::disk($disk)->size($path);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * What to call the file: the person's own words, or the file's name.
     *
     * The typed title is already capped at 255 by the request. The FALLBACK is not, and it must be
     * treated as untrusted: a filename arrives in the multipart header and a client can put anything
     * of any length there, real filesystem limits or not. Left alone it reaches a varchar(255) column
     * and an organization owner gets a 500 instead of a file in their library.
     *
     * Two other shapes worth handling rather than storing: a title of nothing but spaces, and a file
     * called ".jpg", whose name-without-extension is empty. Both would otherwise leave a blank row that
     * nobody can identify.
     */
    private function titleFor(?string $typed, UploadedFile $file): string
    {
        $title = trim((string) $typed);

        if ($title === '') {
            $title = trim(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        }

        $title = mb_substr($title, 0, 255);

        return $title === '' ? 'Untitled' : $title;
    }

    /**
     * The same pipeline for a network advertisement.
     *
     * A campaign's file is the contract's own, sold to a brand and switched on and off with
     * the campaign, so it stays out of every library. Only the plumbing is shared: the same
     * thumbnailing, the same measuring, a different shelf.
     *
     * @param  array<string, mixed>  $clientMeta  browser-measured duration/width/height/poster
     * @return array<string, mixed>
     */
    public function storeCampaignFile(UploadedFile $file, array $clientMeta = []): array
    {
        // The ads network has no allowance of its own; the server's disk still has its reserve (DiskGuard).
        $this->disk->assertRoomFor((int) $file->getSize());

        return $this->put($file, 'campaigns', $clientMeta);
    }

    /**
     * The same pipeline for a picture or a video used INSIDE an ad built in the Ad Builder.
     *
     * Its own shelf again (owner's decision, 2026-09-17): the organization's media library is what an organization
     * PLAYS, while this is raw material that only means something inside a design — a logo, a texture,
     * a looping background. Same thumbnailing and measuring, different folder.
     *
     * @param  array<string, mixed>  $clientMeta  browser-measured duration/width/height/poster
     * @return array<string, mixed>
     */
    public function storeBuilderAsset(UploadedFile $file, ?int $organizationId, array $clientMeta = []): array
    {
        return $this->put($file, 'builder/'.($organizationId ?? 'platform').'/assets', $clientMeta);
    }

    /**
     * Put the file on disk and measure it.
     *
     * @param  array<string, mixed>  $clientMeta
     * @return array<string, mixed>
     */
    private function put(UploadedFile $file, string $directory, array $clientMeta = []): array
    {
        $disk = 'public';
        $mime = (string) $file->getMimeType();
        $type = str_starts_with($mime, 'video/') ? Media::TYPE_VIDEO : Media::TYPE_IMAGE;

        // A video's length, from its own bytes — the upload's validation read the same file (App\Rules\VideoLength),
        // so this is remembered, not read twice. Only a caller that never validated falls back to the browser's.
        $measured = $type === Media::TYPE_VIDEO ? $this->durations->seconds((string) $file->getRealPath()) : null;
        $clientSeconds = isset($clientMeta['duration_seconds']) ? (int) $clientMeta['duration_seconds'] : null;

        // A picture is stored light enough for a television (PictureOptimizer): what lands on disk, is counted to the
        // organization and is sent to the screens is the lighter file, when there is one.
        $name = (string) Str::ulid();
        $lighter = $type === Media::TYPE_IMAGE ? $this->pictures->optimize((string) $file->getRealPath(), $mime) : null;

        if ($lighter !== null) {
            $path = "{$directory}/{$name}.{$lighter['extension']}";
            Storage::disk($disk)->put($path, $lighter['bytes']);
            $mime = $lighter['mime'];
            $size = strlen($lighter['bytes']);
        } else {
            // The extension comes from the type read off the BYTES, never from the name the client sent:
            // `mimes:` judges the bytes, so a real PNG named `promo.html` passes it — and kept as .html it
            // would be served as a page from the panel's own address, running whatever its text chunks held.
            $extension = $file->extension() ?: 'bin';
            $path = $file->storeAs($directory, "{$name}.{$extension}", $disk);
            $size = $file->getSize();
        }

        $width = null;
        $height = null;
        $thumbnailPath = null;

        if ($type === Media::TYPE_IMAGE) {
            [$width, $height] = $this->imageDimensions($disk, $path);
            $thumbnailPath = $this->makeImageThumbnail($disk, $path, "{$directory}/thumbs/{$name}.jpg", $mime);
        } else {
            // Trust the browser only for shape, never for identity: these values
            // are already validated as integers by the form request.
            $width = isset($clientMeta['width']) ? (int) $clientMeta['width'] : null;
            $height = isset($clientMeta['height']) ? (int) $clientMeta['height'] : null;
            $thumbnailPath = $this->savePoster($disk, "{$directory}/thumbs/{$name}.jpg", $clientMeta['poster'] ?? null);
        }

        return [
            'type' => $type,
            'mime_type' => $mime,
            'disk' => $disk,
            'path' => $path,
            'thumbnail_path' => $thumbnailPath,
            'size' => $size,
            'width' => $width,
            'height' => $height,
            'duration_seconds' => $type === Media::TYPE_VIDEO
                ? ($measured !== null ? (int) round($measured) : $clientSeconds)
                : null,
            'orientation' => $this->orientation($width, $height),
        ];
    }

    /** Remove a media file and its thumbnail from disk. Missing files are not an error. */
    public function delete(Media $media): void
    {
        $this->deleteFiles($media->disk, $media->path, $media->thumbnail_path);
    }

    /** The same, for anything else that owns a file and a thumbnail. */
    public function deleteFiles(string $disk, ?string $path, ?string $thumbnailPath = null): void
    {
        Storage::disk($disk)->delete(array_filter([$path, $thumbnailPath]));
    }

    /** Portrait when taller than wide; square counts as landscape (it letterboxes the same). */
    private function orientation(?int $width, ?int $height): ?string
    {
        if (! $width || ! $height) {
            return null;
        }

        return $height > $width ? 'portrait' : 'landscape';
    }

    /** @return array{0: int|null, 1: int|null} */
    private function imageDimensions(string $disk, string $path): array
    {
        $size = @getimagesize(Storage::disk($disk)->path($path));

        return $size ? [(int) $size[0], (int) $size[1]] : [null, null];
    }

    /**
     * Scale an image down to THUMB_MAX on its longest edge and write a JPEG.
     * Returns null when GD cannot read the format — the library then falls back
     * to showing the original, which is correct, just heavier — and when the picture is
     * too big to open in the memory the request has left: a small file claiming a huge
     * picture gets no thumbnail, rather than taking the upload down with it.
     */
    private function makeImageThumbnail(string $disk, string $path, string $target, string $mime): ?string
    {
        $absolute = Storage::disk($disk)->path($path);
        $size = @getimagesize($absolute);

        if ($size === false || ! PictureOptimizer::fitsInMemory(((int) $size[0] * (int) $size[1] + self::THUMB_MAX ** 2) * PictureOptimizer::BYTES_PER_PIXEL)) {
            return null;
        }

        $source = $this->readImage($absolute, $mime);

        if (! $source) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::THUMB_MAX / max($width, $height));
        $thumbWidth = max(1, (int) round($width * $scale));
        $thumbHeight = max(1, (int) round($height * $scale));

        $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
        // Flatten transparency onto white so a PNG logo doesn't come out black.
        imagefilledrectangle($thumb, 0, 0, $thumbWidth, $thumbHeight, imagecolorallocate($thumb, 255, 255, 255));
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);

        ob_start();
        imagejpeg($thumb, null, 80);
        $contents = (string) ob_get_clean();

        Storage::disk($disk)->put($target, $contents);

        return $target;
    }

    /** @return \GdImage|false */
    private function readImage(string $absolutePath, string $mime)
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($absolutePath),
            'image/png' => @imagecreatefrompng($absolutePath),
            'image/gif' => @imagecreatefromgif($absolutePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolutePath) : false,
            default => false,
        };
    }

    /**
     * An image the BROWSER drew, handed over as a data URI, written to a path we choose — an organization's design's
     * poster, kept only while the organization has room for it, in place of the poster it had.
     *
     * The Ad Builder's poster comes this way: an ad is HTML, and there is no headless browser on the
     * server to photograph it, so the editor captures its own stage and sends the picture along with the
     * save. Anything that is not a small PNG or JPEG data URI is quietly refused — a listing without a
     * poster is a nuisance, a listing with somebody's arbitrary bytes in it is a problem. A poster is a
     * nicety, so a full organization keeps the one it had (or none) and the save goes on: nothing is ever refused
     * for a photograph of a design (OrganizationStorage).
     */
    public function storePosterWithin(?int $organizationId, string $target, ?string $dataUrl, Closure $record, string $disk = 'public'): ?string
    {
        $jpeg = $this->encodePoster($dataUrl);

        if ($jpeg === null) {
            return null;
        }

        // Written and recorded ($record names it on its row) inside the lock, so the poster is counted from the
        // moment it exists.
        return $this->quota->withRoomOrSkip($organizationId, strlen($jpeg) - $this->sizeOnDisk($disk, $target), function () use ($disk, $target, $jpeg, $record) {
            Storage::disk($disk)->put($target, $jpeg);
            $record($target);

            return $target;
        });
    }

    /**
     * Decode a poster frame a browser drew — an uploaded video's first frame, an ad's stage — and organization
     * GD's own re-encoding of it, never the bytes that were sent.
     *
     * Whatever those bytes claimed to be, what lands on disk is then a JPEG GD drew, or nothing: a data URI
     * carrying a script, a page or a PHP file under an `image/jpeg` label stores nothing at all. The size is
     * read from the header BEFORE anything is decoded, so a tiny file claiming to be 50 000 pixels across
     * cannot make GD allocate for it. Anything malformed is dropped silently — a missing thumbnail is a
     * cosmetic loss, never a failed upload.
     */
    private function savePoster(string $disk, string $target, ?string $dataUrl): ?string
    {
        $jpeg = $this->encodePoster($dataUrl);

        if ($jpeg === null) {
            return null;
        }

        Storage::disk($disk)->put($target, $jpeg);

        return $target;
    }

    /** GD's own JPEG of a browser-drawn poster, or null for anything that is not a small, real picture. */
    private function encodePoster(?string $dataUrl): ?string
    {
        if (! $dataUrl || ! preg_match('#^data:image/(jpeg|png);base64,#', $dataUrl, $match)) {
            return null;
        }

        $binary = base64_decode(substr($dataUrl, strlen($match[0])), true);

        if ($binary === false || $binary === '' || strlen($binary) > self::POSTER_MAX_BYTES) {
            return null;
        }

        $size = @getimagesizefromstring($binary);

        if ($size === false
            || ! in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
            || $size[0] < 1 || $size[1] < 1
            || $size[0] > self::POSTER_MAX_SIDE || $size[1] > self::POSTER_MAX_SIDE) {
            return null;
        }

        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            return null;
        }

        ob_start();
        imagejpeg($image, null, 85);
        $jpeg = (string) ob_get_clean();

        return $jpeg === '' ? null : $jpeg;
    }
}
