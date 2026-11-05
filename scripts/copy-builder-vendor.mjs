/**
 * Copies the animation library into public/ad-runtime/ (docs/AD-BUILDER-SPEC.md §8).
 *
 * A published advert is a page of its own, shown in a frame on a television — it cannot use the panel's
 * Vite bundle, whose file names change with every build. So Anime.js (MIT) lives at a FIXED address beside
 * the ad runtime, and every published page points there. Running this before each build keeps that copy
 * the same version as the one in package.json, so the editor's preview and the television never disagree.
 * The file goes out as published, licence header included, with one change: what a WebView 79 cannot read
 * is written out (below).
 */
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const target = resolve(root, 'public/ad-runtime');

/**
 * The player's floor is Chrome 79 (vite.config.js; owner, 2026-11-04), and a WebView 79 stops at the first piece
 * of newer syntax and runs nothing of the file: every advert then stands still. Vite never builds this file, so
 * what Anime.js writes in Chrome 80's syntax (`a?.b`, `a ?? b`) is written out here, the same thing in older
 * syntax. A new Anime.js that changes these lines, or adds another, stops the build until it is written out too.
 */
const REWRITES = [
    // Anime.js 4.5.0 reading a CSS variable: getComputedStyle never answers null, but the check stays as it was.
    ['let r=getComputedStyle(i)?.getPropertyValue(s[1]);', 'let r=getComputedStyle(i);r=null==r?void 0:r.getPropertyValue(s[1]);'],
];

const NOTE = ' *\n * Written out for Chrome 79 by scripts/copy-builder-vendor.mjs: its optional chaining, in older syntax.\n';

let code = readFileSync(resolve(root, 'node_modules/animejs/dist/bundles/anime.umd.min.js'), 'utf8');

for (const [from, to] of REWRITES) {
    if (code.split(from).length !== 2) {
        throw new Error(`Anime.js has changed: "${from}" is not in it exactly once. Look again at REWRITES in scripts/copy-builder-vendor.mjs.`);
    }
    code = code.replace(from, () => to);
}

const header = code.match(/^\/\*\*[\s\S]*?\*\/\n/);
if (header === null) {
    throw new Error('Anime.js has changed: its licence header is not where it was.');
}

// `x?.5:1` is a ternary, not optional chaining.
const newer = code.slice(header[0].length).match(/\?\.(?!\d)|\?\?/);
if (newer !== null) {
    const around = code.slice(header[0].length).slice(Math.max(0, newer.index - 40), newer.index + 40);
    throw new Error(`Anime.js has syntax newer than Chrome 79 at "${around}": write it out in REWRITES in scripts/copy-builder-vendor.mjs.`);
}

code = header[0].replace(/ \*\/\n$/, NOTE + ' */\n') + code.slice(header[0].length);

mkdirSync(target, { recursive: true });
writeFileSync(resolve(target, 'anime.min.js'), code);
console.log('builder vendor: anime.min.js');
