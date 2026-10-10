/**
 * Developer → TV App (owner, 2026-10-10 — "mein new apk apne super admin mein upload karu"): the TV app's versions, and the
 * form a new one is uploaded with. The APK goes up in chunks like every file (<x-upload-dropzone>); once it is in, the
 * server reads its version from the file and the form shows it — or, only when it cannot be read, asks for it ("agar upload
 * per version detect nahi kya toh mein manual dal sakhu"). Publish Version makes it the version every TV is offered.
 */
import axios from 'axios';
import { createCrudTable } from '../core/crud-table-base.js';
import { maxLen, maxNumber, minNumber, requiredMessage, unreadableFields, validate, wholeNumber } from '../core/validate.js';

const CODE_WORDS = 'The version code is a whole number, such as 2.';

const blankForm = () => ({ version_name: '', version_code: '', notes: '' });

export function registerDeveloperPage(Alpine) {
    Alpine.data('developerPage', () => createCrudTable({
        fetchUrl: '/developer/app/releases/data',
        dataKey: 'releases',
        entityLabel: 'version',
        deleteModalName: 'confirm-tv-app-deletion',
        defaultForm: blankForm(),

        extraState: {
            /* The APK chosen ({name, size, type}), its upload once every byte is in ({upload}), and whether bytes are still
             * going: Publish waits for them. */
            picked: null,
            uploaded: null,
            uploading: false,
            /* What the server read from the file: {version_read, version_code, version_name, current}, while it is asked. */
            checking: false,
            read: null,
            checksAsked: 0,
        },

        extraMethods: {
            /* The uploader's events (docs/UPLOADS-SPEC.md), heard on the page's own element around the box. */
            onPicked(file) {
                this.picked = file;
                this.uploaded = null;
                this.read = null;
                this.formErrors = {};
            },

            onReady(detail) {
                this.uploaded = detail;
                this.check();
            },

            clearFile() {
                this.picked = null;
                this.uploaded = null;
                this.read = null;
                this.checking = false;
                this.checksAsked++;
            },

            /** Ask the server what the uploaded file is: the TV app or not, and its version. The newest question wins. */
            async check() {
                const asked = ++this.checksAsked;
                this.checking = true;

                try {
                    const { data } = await axios.post('/developer/app/inspect', { upload: this.uploaded?.upload });
                    if (asked === this.checksAsked) this.read = data;
                } catch (error) {
                    if (asked !== this.checksAsked) return;
                    const errors = error.response?.data?.errors;
                    this.formErrors = { file: [errors?.file?.[0] ?? error.response?.data?.message ?? 'The file could not be read. Try again.'] };
                } finally {
                    if (asked === this.checksAsked) this.checking = false;
                }
            },

            /* Whether the file left that half of its version for the page to ask. */
            typesName() {
                return !! this.read && this.read.version_name === null;
            },

            typesCode() {
                return !! this.read && this.read.version_code === null;
            },

            canPublish() {
                return !! this.uploaded && !! this.read && ! this.checking && ! this.uploading;
            },

            /** "Oct 10, 2026" */
            uploadedOn(item) {
                return item.uploaded_at
                    ? new Date(item.uploaded_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
                    : '';
            },

            async publish(event) {
                if (this.saving || ! this.canPublish()) return;

                const rules = { notes: [maxLen("What's new", 500)] };

                // Each half of the version is typed only when the file did not say it.
                if (this.typesName()) {
                    rules.version_name = [requiredMessage('Type the version name, such as 1.1.'), maxLen('Version name', 32)];
                }
                if (this.typesCode()) {
                    rules.version_code = [requiredMessage('Type the version code, such as 2.'), wholeNumber(CODE_WORDS),
                        minNumber(CODE_WORDS, 1), maxNumber(CODE_WORDS, 2100000000)];
                }

                const errors = { ...unreadableFields(event?.target), ...validate(this.form, rules) };

                if (Object.keys(errors).length > 0) {
                    this.formErrors = errors;

                    return;
                }

                this.saving = true;
                this.formErrors = {};

                try {
                    const payload = { upload: this.uploaded.upload, notes: this.form.notes };
                    if (this.typesName()) payload.version_name = this.form.version_name;
                    if (this.typesCode()) payload.version_code = this.form.version_code;

                    const { data } = await axios.post('/developer/app/releases', payload);
                    window.toast(data.message, 'success');
                    this.startAgain();
                    this.refreshInPlace();  // not awaited: the button is free again the moment the request is over
                } catch (error) {
                    if (error.response?.status === 422) {
                        this.formErrors = error.response.data.errors ?? {};
                    } else {
                        window.toast(error.response?.data?.message ?? 'The version could not be published. Try again.');
                    }
                } finally {
                    this.saving = false;
                }
            },

            /** The form as it opened: no file, no version, no notes. */
            startAgain() {
                this.form = blankForm();
                this.clearFile();
                const box = this.$refs.appUpload?.querySelector('[dusk="tv-app-dropzone"]');
                if (box) window.Alpine.$data(box).clear();
            },
        },
    })());
}
