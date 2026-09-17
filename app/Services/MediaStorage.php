<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Puts an uploaded file on disk and works out everything the library needs to
 * describe it: type, dimensions, orientation and a thumbnail.
 *
 * Thumbnails are made with GD, which ships with PHP — no image package is added
 * for this. GD cannot open a video, and ffmpeg is not available, so a video's
 * poster frame is captured in the BROWSER at upload time (a <video> drawn onto a
 * <canvas>) and posted alongside the file as a data URL. Same for a video's
 * duration and dimensions, which the browser already knows.
 */
class MediaStorage
{
    /** Longest edge of a generated thumbnail, in pixels. */
    private const THUMB_MAX = 480;

    /** A browser-supplied poster larger than this is ignored, not stored. */
    private const POSTER_MAX_BYTES = 2 * 1024 * 1024;

    /**
     * Store the file and return the attributes for a Media row.
     *
     * @param  array<string, mixed>  $clientMeta  browser-measured duration/width/height/poster
     * @return array<string, mixed>
     */
    public function store(UploadedFile $file, int $storeId, array $clientMeta = []): array
    {
        return ['store_id' => $storeId, ...$this->put($file, "media/{$storeId}", $clientMeta)];
    }

    /**
     * The same pipeline for a network advertisement.
     *
     * A campaign's file belongs to the PLATFORM, not to any shop, so it never
     * becomes a `media` row — that table is the store's library and stays that way,
     * with no exceptions to reason about. Only the plumbing is shared: the same
     * thumbnailing, the same measuring, a different shelf.
     *
     * @param  array<string, mixed>  $clientMeta  browser-measured duration/width/height/poster
     * @return array<string, mixed>
     */
    public function storeCampaignFile(UploadedFile $file, array $clientMeta = []): array
    {
        return $this->put($file, 'campaigns', $clientMeta);
    }

    /**
     * The same pipeline for an ad inside a channel.
     *
     * Like a campaign's file it belongs to the platform or to one shop, so it never becomes a `media` row.
     * Each channel keeps its own shelf, so a whole channel's files can be accounted for
     * together.
     *
     * @param  array<string, mixed>  $clientMeta  browser-measured duration/width/height/poster
     * @return array<string, mixed>
     */
    public function storeChannelFile(UploadedFile $file, int $channelId, array $clientMeta = []): array
    {
        return $this->put($file, "channels/{$channelId}", $clientMeta);
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

        $name = (string) Str::ulid();
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $path = $file->storeAs($directory, "{$name}.{$extension}", $disk);

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
            'size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'duration_seconds' => $type === Media::TYPE_VIDEO && isset($clientMeta['duration_seconds'])
                ? (int) $clientMeta['duration_seconds']
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
     * to showing the original, which is correct, just heavier.
     */
    private function makeImageThumbnail(string $disk, string $path, string $target, string $mime): ?string
    {
        $source = $this->readImage(Storage::disk($disk)->path($path), $mime);

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

        imagedestroy($thumb);
        imagedestroy($source);

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

    /** Decode the browser's poster frame. Anything malformed is dropped silently —
     *  a missing thumbnail is a cosmetic loss, never a failed upload. */
    private function savePoster(string $disk, string $target, ?string $dataUrl): ?string
    {
        if (! $dataUrl || ! preg_match('#^data:image/(jpeg|png);base64,#', $dataUrl, $match)) {
            return null;
        }

        $binary = base64_decode(substr($dataUrl, strlen($match[0])), true);

        if ($binary === false || $binary === '' || strlen($binary) > self::POSTER_MAX_BYTES) {
            return null;
        }

        Storage::disk($disk)->put($target, $binary);

        return $target;
    }
}
