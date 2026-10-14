<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\Concerns\TakesAFinishedUpload;
use App\Models\PlayerAppRelease;
use App\Services\ApkInspector;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A new version of the TV app, uploaded on Developer → TV App (owner, 2026-10-10). The file must be the TV app itself: an APK
 * whose manifest names com.thedisplaysolution.player. Its version is read from that manifest; only when it cannot be read is
 * the version typed on the page taken ("agar upload per version detect nahi kya toh mein manual dal sakhu") — a version read
 * from the file always wins, so a TV is never offered a number its file does not carry. Either way it must be newer than the
 * version every TV is offered now, since Android installs nothing older than what a TV has.
 */
class StorePlayerAppReleaseRequest extends FormRequest
{
    use TakesAFinishedUpload;

    /** What the manifest said, read once. @var array{is_apk: bool, package: ?string, version_code: ?int, version_name: ?string}|null */
    private ?array $read = null;

    protected function prepareForValidation(): void
    {
        $this->takeFinishedUpload('app-release');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['bail', 'required', 'file', 'max:'.intdiv(PlayerAppRelease::MAX_BYTES, 1024)],
            'version_code' => ['bail', 'nullable', 'integer', 'min:1', 'max:2100000000'],
            'version_name' => ['bail', 'nullable', 'string', 'max:32', 'regex:/^[\pL\pN ._+-]+$/u'],
            'notes' => ['bail', 'nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose the APK to upload.',
            'file.file' => 'Choose the APK to upload.',
            'file.max' => PlayerAppRelease::tooLargeMessage(),
            'version_code.integer' => 'The version code is a whole number, such as 2.',
            'version_code.min' => 'The version code is a whole number, such as 2.',
            'version_code.max' => 'The version code is a whole number, such as 2.',
            'version_name.string' => 'The version name is letters, numbers, dots and dashes, such as 1.1.',
            'version_name.max' => 'The version name may be 32 characters at most.',
            'version_name.regex' => 'The version name is letters, numbers, dots and dashes, such as 1.1.',
            'notes.string' => 'What is new is text, 500 characters at most.',
            'notes.max' => 'What is new may be 500 characters at most.',
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $read = $this->read();

            if (! $read['is_apk']) {
                $validator->errors()->add('file', PlayerAppRelease::ONLY_AN_APK);

                return;
            }

            if ($read['package'] !== null && $read['package'] !== PlayerAppRelease::PACKAGE) {
                $validator->errors()->add('file', "This is another app ({$read['package']}), not the TV app.");

                return;
            }

            [$code, $name] = $this->version();

            if ($code === null) {
                $validator->errors()->add('version_code', 'The version could not be read from this file: type its version code.');
            }

            if ($name === null) {
                $validator->errors()->add('version_name', 'The version could not be read from this file: type its version name.');
            }

            $notNewer = $code !== null ? PlayerAppRelease::notNewerThanCurrent($name, $code) : null;

            if ($notNewer !== null) {
                $validator->errors()->add($this->read()['version_code'] !== null ? 'file' : 'version_code', $notNewer);
            }
        }];
    }

    /** The manifest's word, read once. */
    public function read(): array
    {
        return $this->read ??= app(ApkInspector::class)->inspect($this->file('file')->getRealPath());
    }

    /** Whether the whole version came from the file (rather than the page, in part or whole). */
    public function versionWasRead(): bool
    {
        return $this->read()['version_code'] !== null && $this->read()['version_name'] !== null;
    }

    /**
     * The version to store, each half the file's own when it says it, else what was typed: a code the file carries is never
     * replaced by a typed one.
     *
     * @return array{0: ?int, 1: ?string}
     */
    public function version(): array
    {
        $code = $this->input('version_code');
        $name = trim((string) $this->input('version_name'));

        return [
            $this->read()['version_code'] ?? (filled($code) ? (int) $code : null),
            $this->read()['version_name'] ?? ($name !== '' ? $name : null),
        ];
    }
}
