/**
 * Copies the animation library into public/ad-runtime/ (docs/AD-BUILDER-SPEC.md §8).
 *
 * A published advert is a page of its own, shown in a frame on a television — it cannot use the panel's
 * Vite bundle, whose file names change with every build. So Anime.js (MIT) lives at a FIXED address beside
 * the ad runtime, and every published page points there. Running this before each build keeps that copy
 * the same version as the one in package.json, so the editor's preview and the television never disagree.
 * The file goes out exactly as published, licence header included.
 */
import { copyFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const target = resolve(root, 'public/ad-runtime');

mkdirSync(target, { recursive: true });

copyFileSync(resolve(root, 'node_modules/animejs/dist/bundles/anime.umd.min.js'), resolve(target, 'anime.min.js'));
console.log('builder vendor: anime.min.js');
