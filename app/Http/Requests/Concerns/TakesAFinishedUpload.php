<?php

namespace App\Http\Requests\Concerns;

use App\Models\Upload;
use App\Services\ChunkedUploads;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A form that takes a file takes a finished chunked upload in its place (docs/UPLOADS-SPEC.md): `upload`, the id of
 * the person's own upload, complete, unexpired and opened for this form's purpose, becomes the request's `file` before
 * any rule runs — so every rule the form already has reads the file itself, exactly as if it had been posted whole.
 * Anything else is refused on `file`. One upload makes one row: while a request is making it, another naming the same
 * upload is refused, and the controller forgets the upload once its row is made.
 */
trait TakesAFinishedUpload
{
    private ?Upload $finishedUpload = null;

    protected function takeFinishedUpload(string $purpose): void
    {
        $id = $this->input('upload');

        if ($id === null || $id === '') {
            return;
        }

        if ($this->files->has('file')) {
            throw ValidationException::withMessages(['file' => 'Send the file or an upload, not both.']);
        }

        $uploads = app(ChunkedUploads::class);
        $upload = is_string($id) && Str::isUuid($id) && $this->user() !== null
            ? $uploads->finished($this->user(), $id, $purpose)
            : null;

        if ($upload === null) {
            throw ValidationException::withMessages(['file' => 'That upload is not finished, or it has expired. Choose the file again.']);
        }

        $lock = Cache::lock('upload-finish:'.$upload->id, 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['file' => 'This file is being added already. Wait a moment.']);
        }

        app()->terminating(fn () => $lock->release());

        $this->files->set('file', $uploads->asUploadedFile($upload));
        // The request read its files once already, perhaps; it must read them again with this one.
        $this->convertedFiles = null;
        $this->finishedUpload = $upload;
    }

    /** Once the row is made, the upload's bytes are needed no more. */
    public function forgetFinishedUpload(): void
    {
        if ($this->finishedUpload !== null) {
            app(ChunkedUploads::class)->discard($this->finishedUpload);
            $this->finishedUpload = null;
        }
    }
}
