<?php

namespace App\Http\Requests\Concerns;

use App\Models\Upload;
use App\Services\ChunkedUploads;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A form that takes a file takes a finished chunked upload in its place (docs/UPLOADS-SPEC.md): `upload`, the id of
 * the person's own upload, complete, unexpired and opened for this form's purpose, becomes the request's `file` before
 * any rule runs — so every rule the form already has reads the file itself, exactly as if it had been posted whole.
 * Anything else is refused on `file`. One upload makes one row: while a request is making it (ChunkedUploads::finishLock),
 * another naming the same upload is refused, the box's × waits, and the controller forgets the upload once its row is made. A
 * file the rules refuse is refused for good, and its upload goes at once (failedValidation); the controller asks that the
 * upload joins the place it was opened for (assertUploadOpenedFor).
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
        $gone = ValidationException::withMessages(['file' => 'That upload is not finished, or it has expired. Choose the file again.']);
        $upload = is_string($id) && Str::isUuid($id) && $this->user() !== null
            ? $uploads->finished($this->user(), $id, $purpose)
            : null;

        if ($upload === null) {
            // Gone, or never finished: posting it again cannot help, so the uploader offers no Try Again.
            $uploads->refuseForGood($gone);
        }

        // Held until the answer is sent: a second request naming it, the box's × (ChunkedUploads::cancel) and uploads:prune
        // wait for it.
        $lock = ChunkedUploads::finishLock($upload->id);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['file' => 'This file is being added already. Wait a moment.']);
        }

        app()->terminating(fn () => $lock->release());

        // Given up between the look and the lock — × in the box, the request before this one done with it.
        $upload = $uploads->finished($this->user(), $id, $purpose) ?? $uploads->refuseForGood($gone);

        $this->files->set('file', $uploads->asUploadedFile($upload));
        // The request read its files once already, perhaps; it must read them again with this one.
        $this->convertedFiles = null;
        $this->finishedUpload = $upload;
    }

    /**
     * A file the rules refused — its format, its size, a video's length or one nobody can measure, an APK that is not the TV
     * app — is refused again however often it is posted: its upload is given up at once (ChunkedUploads::refuseForGood) rather
     * than kept for a day. A refusal of the form's other fields (a date, the seconds) keeps it, for the form to be sent again.
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->finishedUpload !== null && $validator->errors()->has('file')) {
            $upload = $this->finishedUpload;
            $this->finishedUpload = null;

            app(ChunkedUploads::class)->refuseForGood(new ValidationException($validator), $upload);
        }

        parent::failedValidation($validator);
    }

    /**
     * A finished upload joins only the place it was opened for — the library or the shelf the box showed when the file was
     * dropped, whose storage was asked then — never the one a page's list shows by the time it is posted (an upload above the
     * organizations once landed in the organization chosen while its bytes were going up). The box keeps each row's place, so
     * only an upload another place opened reaches here (a file dropped again elsewhere once carried on the upload a reload had cut
     * short): nothing will add it where it was opened for, so it is given up, and the file chosen again makes its own. A file
     * posted whole is not asked: the door's own target is where it goes.
     */
    public function assertUploadOpenedFor(?int $organizationId, string $place): void
    {
        if ($this->finishedUpload !== null && $this->finishedUpload->organization_id !== $organizationId) {
            $upload = $this->finishedUpload;
            $this->finishedUpload = null;

            app(ChunkedUploads::class)->refuseForGood(
                ValidationException::withMessages(['file' => "This file was uploaded for another {$place}. Choose it again to add it here."]),
                $upload,
            );
        }
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
