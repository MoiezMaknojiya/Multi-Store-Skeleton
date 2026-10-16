<?php

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A page's own script run under Node, for what only the browser's side decides (a dialog a late answer must not shut, a
 * label a form works out): the very module the page loads — resources/js/… imported as it is — with Alpine's magics and
 * the page around it stood in for by the prelude below, and every request through the real axios, answered by the test.
 * Dusk sees the same in a browser; this sees it in a second, on every run of the suite.
 *
 * The script ends with report(value); what it reported comes back decoded.
 */
final class PageScript
{
    private const PRELUDE = <<<'JS'
        import axios from 'axios';

        const toasts = [];
        const dispatched = [];
        const boxes = {};

        globalThis.window = {
            toast: (message, type = 'error') => toasts.push({ message, type }),
            Alpine: { $data: (element) => element.data },
        };
        globalThis.document = {
            querySelector: (selector) => boxes[selector] ?? null,
            querySelectorAll: () => [],
            getElementById: () => null,
        };
        globalThis.matchMedia ??= () => ({ matches: false });

        /** A promise the test settles when it chooses: the answer of a slow line. */
        const later = () => {
            let resolve;
            let reject;
            const promise = new Promise((yes, no) => { resolve = yes; reject = no; });

            return { promise, resolve, reject };
        };

        /** Lets every answer already given be heard. */
        const settle = () => new Promise((done) => setTimeout(done, 0));

        /** What axios throws for a refused request. */
        const refusal = (status, data) => Object.assign(new Error(`Request failed with status code ${status}`), { response: { status, data } });

        /** A component as Alpine holds it, its magics stood in for; `refs` is what $refs finds where the call is made. */
        const asComponent = (data, { refs = {} } = {}) => Object.assign(data, {
            $dispatch: (name, detail) => dispatched.push({ name, detail }),
            $nextTick: (callback) => callback?.(),
            $refs: refs,
            $root: { querySelector: () => null },
            $watch: () => {},
        });

        /** A form on the page, shown or (its dialog shut) not. */
        const formOnPage = () => {
            const form = { shown: true, isConnected: true, querySelectorAll: () => [], querySelector: () => null };
            form.getClientRects = () => (form.shown ? [{}] : []);

            return form;
        };

        /** An upload box (<x-upload-dropzone mode="form">) holding rows, as upload-dropzone.js keeps them. */
        const uploadBox = (selector, rows = []) => {
            const box = {
                given: [],
                cleared: 0,
                data: {
                    uploads: rows,
                    clear() {
                        box.cleared++;
                        this.uploads.forEach((row) => { if (row.uploadId && row.status !== 'done') box.given.push(row.uploadId); });
                        this.uploads = [];
                    },
                    giveUp(id) { box.given.push(id); },
                },
            };
            boxes[selector] = box;

            return box;
        };

        const report = (value) => console.log(JSON.stringify(value));

        JS;

    /** Runs `$script` after the prelude, from the project's root (so `./resources/js/…` and `axios` resolve as Vite's do). */
    public static function run(string $script): mixed
    {
        $process = new Process(['node', '--input-type=module', '-'], base_path(), timeout: 60);
        $process->setInput(self::PRELUDE."\n".$script);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException("The page's script failed:\n".$process->getErrorOutput().$process->getOutput());
        }

        $lines = preg_split('/\R/', trim($process->getOutput()));

        return json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
