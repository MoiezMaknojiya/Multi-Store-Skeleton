<?php

namespace App\Http\Controllers;

use App\Models\Upload;
use App\Services\ChunkedUploads;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The tus protocol 1.0, with its creation and termination extensions: a file sent in chunks from any page that takes
 * one (docs/UPLOADS-SPEC.md). What an upload may be, and whether it may be at all, is ChunkedUploads' to say; this is
 * only the protocol's shape. Not logged: a chunk is not a change anybody made — the file it becomes is logged by the
 * form that makes its row.
 */
class UploadController extends Controller
{
    public function __construct(private readonly ChunkedUploads $uploads) {}

    /** What this server speaks. */
    public function options(): Response
    {
        return response()->noContent(204, [
            'Tus-Version' => '1.0.0',
            'Tus-Extension' => 'creation,termination',
            'Tus-Max-Size' => (string) ChunkedUploads::maxSize(),
        ]);
    }

    /** Open an upload: its size and what it is for, before any byte. */
    public function store(Request $request): Response
    {
        abort_if($request->hasHeader('Upload-Defer-Length'), 400, 'Say how large the file is.');

        $size = $this->number($request->header('Upload-Length'));

        abort_if($size === null, 400, 'Say how large the file is.');

        $upload = $this->uploads->open($request->user(), $size, $this->metadata($request->header('Upload-Metadata')));

        return response('', 201, [
            'Location' => route('uploads.show', $upload->id),
            'Upload-Offset' => '0',
        ]);
    }

    /** How far an upload got. */
    public function show(Request $request, string $upload): Response
    {
        $upload = $this->mine($request, $upload);

        return response('', 200, [
            'Upload-Offset' => (string) $upload->received,
            'Upload-Length' => (string) $upload->size,
            'Cache-Control' => 'no-store',
        ]);
    }

    /** One chunk, at the offset it says. */
    public function update(Request $request, string $upload): Response
    {
        abort_unless(str_starts_with(strtolower((string) $request->header('Content-Type')), 'application/offset+octet-stream'), 415,
            'Send the chunk as application/offset+octet-stream.');

        $offset = $this->number($request->header('Upload-Offset'));

        abort_if($offset === null, 400, 'Say where this chunk goes.');

        $received = $this->uploads->append(
            $this->mine($request, $upload),
            $offset,
            $request->getContent(true),
            $this->number($request->header('Content-Length')),
        );

        return response()->noContent(204, ['Upload-Offset' => (string) $received]);
    }

    /** Give an upload up. */
    public function destroy(Request $request, string $upload): Response
    {
        $this->uploads->discard($this->mine($request, $upload));

        return response()->noContent();
    }

    /** The person's own upload, unexpired — anybody else's is not found. */
    private function mine(Request $request, string $id): Upload
    {
        $upload = Upload::whereKey($id)->where('user_id', $request->user()->id)->open()->first();

        abort_if($upload === null, 404, 'That upload has expired. Choose the file again.');

        return $upload;
    }

    /** A header that is one whole number, or null. */
    private function number(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^\d{1,15}$/', $value) === 1 ? (int) $value : null;
    }

    /**
     * Upload-Metadata: "key base64,key base64". Keys this server does not know are kept but never read; a value that
     * is not base64 refuses the request.
     *
     * @return array<string, string>
     */
    private function metadata(mixed $header): array
    {
        if (! is_string($header) || trim($header) === '') {
            return [];
        }

        abort_if(strlen($header) > 4096, 400, 'The file\'s description is too long.');

        $meta = [];

        foreach (explode(',', $header) as $pair) {
            [$key, $encoded] = array_pad(explode(' ', trim($pair), 2), 2, '');

            if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $key) !== 1) {
                continue;
            }

            $value = base64_decode($encoded, true);

            abort_if($value === false, 400, 'The file\'s description could not be read.');

            $meta[$key] = $value;
        }

        return $meta;
    }
}
