/**
 * The Ad Builder's editor (docs/AD-BUILDER-SPEC.md §7, §10a).
 *
 * One fixed stage — 1920×1080, or 1080×1920 for a portrait ad (§12), chosen before the editor opened —
 * absolutely positioned elements, and a panel that edits whatever is selected. Everything the person does
 * happens to `doc` — the document — and every change goes through `commit()`, which is what makes undo,
 * the History panel, the dirty flag and autosave possible without a single special case scattered through
 * the handlers.
 *
 * Stage pixels, never screen pixels: the stage is drawn at whatever zoom fits the window, so every
 * pointer movement is converted once and the design never depends on the zoom.
 *
 * What is drawn mirrors the compiler (styles.js, background.js) and what moves is the television's own
 * runtime (motion.js), so the stage a person designs on is the page a screen shows. The panels live in
 * their own modules and are spread in: the Background panel (stage-background.js), the Animation tab
 * (motion.js), arranging and the clipboard (arrange.js), and zoom, rulers, guides and history (view.js).
 */
import axios from 'axios';
import { arrangePanel, CLIPBOARD_KEY, readClipboard } from './arrange.js';
import {
    clampName, isGroup, MAX_NAME, MAX_TEXT, newId, normaliseDocument, parentIdOf, readPreference, renumberDepth, syncGroupBounds,
    writePreference,
} from './document.js';
import { angleFromCentre, boundsOf, intersects, MIN_SIZE, normaliseAngle, resizeRotated, snapMove, stepAngle, toStage } from './geometry.js';
import { groupPanel } from './groups.js';
import { clone, createHistory } from './history.js';
import { motionPanel } from './motion.js';
import { capturePoster } from './poster.js';
import { backgroundPanel } from './stage-background.js';
import { boxCss, limit, pictureCss, shapeCss, textCss } from './styles.js';
import { viewPanel } from './view.js';

/** How often a saved ad with changes is saved again on its own. */
const AUTOSAVE_MS = 30_000;

/** Something a key presses: a button, a link, a checkbox. */
const PRESSABLE = 'button, a[href], summary, [role="button"], [role="menuitem"], input[type="checkbox"], input[type="radio"]';

/** What each kind of font is called under its name in the picker. */
const FONT_KINDS = { system: 'Built in', sans: 'Sans serif', display: 'Display', script: 'Script', serif: 'Serif', mono: 'Monospace' };

/** The face a family not installed yet is previewed in: a name of its own, so it never stands in for the family. */
function previewFamily(family) {
    return `${family} preview`;
}

/** A dialog of the panel (Discard changes, Unpublish) is open: its keys are its own, never the stage's. */
function aDialogIsOpen() {
    return [...document.querySelectorAll('[role="dialog"][aria-modal="true"]')]
        .some((dialog) => dialog.getClientRects().length > 0);
}

/** 50 (%) → 0.5, as the design keeps an opacity; anything that is not a number is handed on for limit() to refuse. */
function fractionOf(percent) {
    const number = Number(percent);

    return String(percent ?? '').trim() === '' || !Number.isFinite(number) ? percent : number / 100;
}

export function registerAdEditor(Alpine) {
    Alpine.data('adEditor', (config) => {
        // What Anime.js is running for the preview — deliberately NOT part of the component's reactive state
        // (see motion.js).
        const motion = { running: [], easeTweens: {}, lengthTimer: null };
        // Where the keyboard was before an overlay of the editor opened, to go back to when it closes — a DOM node,
        // so never in the reactive state.
        const overlay = { returnTo: null };
        // Which rows of the font picker have come into sight, to preview their families (watchFontRow) — an observer
        // and DOM nodes, so never in the reactive state either.
        const fontRows = { observer: null, preview: new WeakMap() };

        return {
            ...backgroundPanel(),
            ...motionPanel(motion),
            ...arrangePanel(),
            ...viewPanel(),
            ...groupPanel(),

            /* ── What is being edited ──────────────────────────────────── */
            adId: config.adId ?? null,
            name: config.name ?? 'Untitled ad',
            /* Which way the screen is mounted (§12). Posted with the first save and fixed after: the
             * server measures every later save against the ad's own orientation, never this value. */
            orientation: config.orientation === 'portrait' ? 'portrait' : 'landscape',
            doc: normaliseDocument(clone(config.document), {
                minSeconds: config.adSeconds?.min ?? null,
                maxSeconds: config.adSeconds?.max ?? null,
            }),
            assets: config.assets ?? [],
            organizationId: config.organizationId ?? null,
            /* The platform team making a NEW ad says which organization it is for — All organizations first, an ad for every
             * organization (owner, 2026-10-01) — and the shelf follows the choice, so nothing from another organization can be
             * placed and then lost when the ad is saved. */
            choosesOrganization: config.choosesOrganization ?? false,
            /* Made for every organization: its shelf is the files shared with every organization alone. */
            shared: config.shared ?? false,

            /* What this person may do here, as the routes decide it: a saved ad is changed and published
             * with Update Ads, and a font is fetched by whoever may create or change an ad. The page asks
             * the server, so nothing is offered that a click would only have refused. */
            canUpdate: config.canUpdate ?? false,
            canInstallFonts: config.canInstallFonts ?? false,
            canUpload: config.canUpload ?? false,

            /* The server's own tables (AdCompiler::LIMITS and ::FILTERS, AdAnimations::NUMBERS) and limits,
             * so every input here is held inside exactly what the server accepts and the compiler writes. */
            limits: config.limits ?? {},
            filterTable: config.filters ?? {},
            animationNumbers: config.animationNumbers ?? {},
            maxLayers: config.maxLayers ?? 12,
            maxStops: config.maxStops ?? 6,
            maxElements: config.maxElements ?? 200,
            maxGuides: config.maxGuides ?? 50,
            /* How long the ad is on screen (BuilderAd::DEFAULT_SECONDS, ::MAX_SECONDS): the design's own. */
            adSecondsDefault: config.adSeconds?.default ?? 6,
            adSecondsMin: config.adSeconds?.min ?? 6,
            adSecondsMax: config.adSeconds?.max ?? 300,

            /* ── Editor state (not part of the design) ─────────────────── */
            selectedIds: [],
            editingTextId: null,
            renamingId: null,
            zoom: 0.4,
            snapLines: { x: null, y: null },
            marquee: null,
            panelTab: 'style',
            history: null,

            /* ── Saving ────────────────────────────────────────────────── */
            dirty: false,
            saving: false,
            publishing: false,
            /* Where the ad stands with the screens — the industry's draft/publish model (docs/AD-BUILDER-SPEC.md §9):
             * on them or not; whether they lag behind saved changes; whether the version they show was kept
             * (Discard changes goes back to it). The server says, after every save and publish. */
            published: config.published ?? false,
            hasChanges: config.hasChanges ?? false,
            hasPublishedVersion: config.hasPublishedVersion ?? false,

            unpublishing: false,
            discarding: false,
            publishMenuOpen: false,
            // "More" (⋯): the rulers and the shortcuts, on a bar too narrow for their own buttons.
            moreOpen: false,
            autosave: readPreference('autosave', true),
            lastSavedAt: null,
            lastSaveWasAuto: false,
            /* What the last failed autosave said: a refusal that keeps coming is said once, not every half minute. */
            lastAutosaveError: null,
            /* Why the name was refused — its box goes red until it is changed. */
            nameError: '',
            /* Every change bumps this, so a save knows whether something changed while it was on its way. */
            revision: 0,
            /* Whether the poster no longer shows the design (Publish then saves a fresh one first). */
            posterStale: !config.hasPoster,

            /* ── The asset picker: a new element, a background layer's file, or a replacement ── */
            assetPickerOpen: false,
            assetPickerMode: 'element',
            assetPickerKind: null,
            /* How full the organization's shelf is ({used, limit}), as the last upload from the picker said: the uploader checks
             * a file against it before a byte is sent. Unknown until then — the server decides either way. */
            shelfStorage: null,

            /* ── Fonts ─────────────────────────────────────────────────── */
            fontPickerOpen: false,
            fontQuery: '',
            // The font list could not be fetched: the picker says so, with Try again.
            fontsFailed: false,
            fonts: [],                  // the whole catalogue: system faces first, then ours
            fontsLoaded: false,
            loadingFonts: false,        // the catalogue is on its way (init and the picker both ask)
            installingFamily: null,     // the one being fetched from Google right now
            fontKind: 'all',            // the picker's group: all, or one kind (FONT_KINDS)
            fontPreviews: {},           // family → loading | ready | failed: one not installed, drawn in its own letters
            showMoreType: false,        // Elementor's "Show more": the rare controls, one click away
            showFilters: false,

            /* The Layers panel's drag: which row is moving, and where it would land. */
            layerDrag: null,

            /* The live drag/resize/rotate, kept out of the history on purpose: it changes on every
             * pointer move, and only where it ends is a step worth undoing. */
            gesture: null,

            /* ── Lifecycle ─────────────────────────────────────────────── */
            init() {
                this.history = createHistory(this.doc);
                this.$nextTick(() => this.zoomToFit());

                // A saved design names its fonts; the catalogue says where they live, so it is fetched
                // straight away rather than when the picker happens to be opened.
                if (this.usedFamilies().length > 0) this.loadFonts();

                // The stage is scaled to the window, so it follows the window.
                this._onResize = () => this.zoomToFit();
                window.addEventListener('resize', this._onResize);

                // Leaving with unsaved work should cost a click, not a design.
                this._onBeforeUnload = (event) => {
                    if (!this.dirty) return;
                    event.preventDefault();
                    event.returnValue = '';
                };
                window.addEventListener('beforeunload', this._onBeforeUnload);

                // A click anywhere else closes the right-click menu (the menu stops its own clicks).
                this._onPointerDown = () => this.closeContextMenu();
                window.addEventListener('pointerdown', this._onPointerDown);

                // Another tab copying something is this tab's clipboard too — and clearing the storage
                // (a null key) empties it. Read the way every paste reads it, so a value that is not a
                // clipboard at all counts as an empty one instead of throwing.
                this._onStorage = (event) => {
                    if (event.key === null || event.key === CLIPBOARD_KEY) this.clipboardSize = readClipboard().length;
                };
                window.addEventListener('storage', this._onStorage);

                // The editor's own overlays (the font picker, the asset picker, the shortcuts) behave as the panel's
                // dialogs do (core/modal.js): the keyboard goes into one when it opens, and back to what opened it
                // when it closes.
                ['fontPickerOpen', 'assetPickerOpen', 'shortcutsOpen'].forEach((flag) => {
                    this.$watch(flag, (open) => (open ? this.overlayOpened(flag) : this.overlayClosed()));
                });

                this._autosaveTimer = setInterval(() => this.autosaveTick(), AUTOSAVE_MS);
            },

            /* ── The editor's overlays ─────────────────────────────────── */

            overlayOpened(flag) {
                overlay.returnTo = document.activeElement;

                const into = { fontPickerOpen: 'fontSearch', assetPickerOpen: 'assetPanel', shortcutsOpen: 'shortcutsPanel' }[flag];

                // x-show draws the overlay on the next frame; the keyboard goes in once it is there.
                setTimeout(() => this.$refs[into]?.focus({ preventScroll: true }), 50);
            },

            overlayClosed() {
                const back = overlay.returnTo;
                overlay.returnTo = null;

                if (back && back !== document.body && back.isConnected && typeof back.focus === 'function') {
                    back.focus({ preventScroll: true });
                }
            },

            /** Tab and Shift+Tab go round an open overlay's own controls, never to the editor behind it. */
            keepFocusIn(event, panel) {
                if (!panel) return;

                const items = [...panel.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea, [tabindex]:not([tabindex="-1"])')]
                    .filter((item) => item.getClientRects().length > 0);

                if (items.length === 0) {
                    event.preventDefault();

                    return;
                }

                const first = items[0];
                const last = items[items.length - 1];

                if (!panel.contains(document.activeElement) || document.activeElement === panel) {
                    event.preventDefault();
                    (event.shiftKey ? last : first).focus();
                } else if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            },

            destroy() {
                this.stopPreview();
                clearInterval(this._autosaveTimer);
                window.removeEventListener('resize', this._onResize);
                window.removeEventListener('beforeunload', this._onBeforeUnload);
                window.removeEventListener('pointerdown', this._onPointerDown);
                window.removeEventListener('storage', this._onStorage);
            },

            /* ── The document ──────────────────────────────────────────── */

            get stage() {
                return this.doc.stage;
            },

            /** Painted back to front, so the layers panel can show the opposite. */
            get elementsByDepth() {
                return [...this.doc.elements].sort((a, b) => (a.z ?? 0) - (b.z ?? 0));
            },

            get layers() {
                return [...this.doc.elements].sort((a, b) => (b.z ?? 0) - (a.z ?? 0));
            },

            /** The ONE selected element — null when nothing, or several, are selected. */
            get selected() {
                return this.selectedIds.length === 1
                    ? (this.doc.elements.find((element) => element.id === this.selectedIds[0]) ?? null)
                    : null;
            },


            get zoomPercent() {
                return Math.round(this.zoom * 100);
            },

            /** "1920 × 1080 — a television screen", or the portrait words, under the stage. */
            stageSizeNote() {
                const size = `${this.stage.width} × ${this.stage.height}`;

                return this.orientation === 'portrait'
                    ? `${size} — a portrait screen, a television mounted upright`
                    : `${size} — a television screen`;
            },

            /**
             * How long the ad is on screen, as its design says (owner, 2026-09-28 — the industry's way, Xibo's layout
             * duration and Canva's page duration): every playlist and channel plays it this long, and a video in it,
             * the background's or one on the stage, repeats when it is shorter and is cut when the ad ends.
             */
            adSeconds() {
                return Number.isInteger(this.doc.duration)
                    ? Math.min(this.adSecondsMax, Math.max(this.adSecondsMin, this.doc.duration))
                    : this.adSecondsDefault;
            },

            /**
             * Does the design say its own length? One made before designs had a length does not until it is given
             * one: until then every screen and channel keeps the seconds it gave it (BuilderAd::hasOwnLength).
             */
            hasOwnLength() {
                return Number.isInteger(this.doc.duration);
            },

            /**
             * Set the ad's length, held between the shortest and the longest the server takes. Emptied, the ad has
             * no length of its own again, and each screen keeps the seconds it gives it.
             */
            setAdSeconds(value) {
                if (String(value ?? '').trim() === '') {
                    if (this.hasOwnLength()) {
                        delete this.doc.duration;
                        this.commit('Length');
                    }

                    return '';
                }

                const seconds = Math.round(Number(value));

                if (! Number.isFinite(seconds)) return this.hasOwnLength() ? this.adSeconds() : '';

                this.doc.duration = Math.min(this.adSecondsMax, Math.max(this.adSecondsMin, seconds));
                this.commit('Length');

                return this.doc.duration;
            },

            /**
             * Remember the document and mark it unsaved. Every change comes through here — so this is also
             * where every group's box is made the box around what it holds, and a group left empty goes
             * (§13), before the step is remembered.
             */
            commit(label = null) {
                syncGroupBounds(this.doc);
                this.leaveMissingGroup();
                this.ensureSelectionExists();
                this.history.commit(this.doc, label);
                this.markChanged();
            },

            /** Something differs from what was saved — by a change, an undo, or a jump in the history. */
            markChanged() {
                this.dirty = true;
                this.revision += 1;
                this.posterStale = true;
            },

            /* ── Selection ─────────────────────────────────────────────── */

            /** The selected elements, back to front. */
            selection() {
                const chosen = new Set(this.selectedIds);

                return this.elementsByDepth.filter((element) => chosen.has(element.id));
            },

            /**
             * A click: Shift or Ctrl adds or removes, a plain click selects alone. Anything inside a group
             * selects the group, unless that group has been entered (§13).
             */
            select(element, event = null) {
                event?.stopPropagation();

                const level = this.editingGroupId;
                const target = this.resolveTarget(element);

                if (!target || this.isLocked(target)) return;

                // Shift or Ctrl adds to the selection only on the level being worked on: a click that stepped
                // out of a group starts a selection of its own there, never one mixing two levels (§13).
                if (event && (event.shiftKey || event.ctrlKey || event.metaKey) && this.editingGroupId === level) {
                    this.toggleInSelection(target);

                    return;
                }

                this.setSelection([target.id]);
            },

            setSelection(ids) {
                if (ids.join() !== this.selectedIds.join()) this.stopPreview();

                this.selectedIds = ids;
            },

            toggleInSelection(element) {
                this.setSelection(this.selectedIds.includes(element.id)
                    ? this.selectedIds.filter((id) => id !== element.id)
                    : [...this.selectedIds, element.id]);
            },

            clearSelection() {
                if (this.previewing === 'element') this.stopPreview();

                this.selectedIds = [];
                this.editingTextId = null;
            },

            /** Ctrl+A: everything at this level that can be selected — shown and unlocked. */
            selectAll() {
                this.setSelection(this.levelItems()
                    .filter((element) => this.isShown(element) && !this.isLocked(element))
                    .map((element) => element.id));
            },

            isSelected(element) {
                return this.selectedIds.includes(element.id);
            },

            ensureSelectionExists() {
                const live = new Set(this.doc.elements.filter((element) => !this.isLocked(element)).map((element) => element.id));

                this.selectedIds = this.selectedIds.filter((id) => live.has(id));

                if (this.selectedLayerId && !this.doc.stage.background.layers.some((layer) => layer.id === this.selectedLayerId)) {
                    this.selectedLayerId = null;
                }
            },

            /* ── Adding things ─────────────────────────────────────────── */

            addText() {
                const element = {
                    ...this.newBox(880, 200),
                    type: 'text',
                    name: 'Text',
                    text: 'Your text here',
                    style: {
                        fontFamily: 'Inter', fontWeight: 700, fontSize: 96, color: '#ffffff',
                        align: 'left', lineHeight: 1.2, letterSpacing: 0,
                    },
                    animations: {},
                };

                this.place(element, 'Add text');
            },

            addShape() {
                const element = {
                    ...this.newBox(480, 320),
                    type: 'shape',
                    name: 'Shape',
                    style: { shape: 'rect', fill: '#2563eb', radius: 16 },
                    animations: {},
                };

                this.place(element, 'Add shape');
            },

            /** A line (§14): a shape drawn as a stroke across its box — a rule, a divider, a dotted leader. */
            addLine() {
                const element = {
                    ...this.newBox(600, 40),
                    type: 'shape',
                    name: 'Line',
                    style: { shape: 'line', fill: '#ffffff', lineWidth: 6, lineStyle: 'solid' },
                    animations: {},
                };

                this.place(element, 'Add line');
            },

            /**
             * An asset from the shelf. Pictures keep their own proportions, so nothing arrives squashed — and
             * arrive whole: 1200 px wide at most, and never taller than the stage, so a portrait photograph
             * comes in by its height rather than hanging off the bottom of the frame.
             */
            addAsset(asset) {
                const ratio = asset.width && asset.height ? asset.width / asset.height : 16 / 9;
                const tallest = this.stage.height - 120;
                let width = Math.min(1200, this.stage.width - 200);
                let height = Math.round(width / ratio);

                if (height > tallest) {
                    height = tallest;
                    width = Math.round(height * ratio);
                }

                const type = asset.kind === 'video' ? 'video' : 'image';
                const element = {
                    ...this.newBox(Math.max(MIN_SIZE, width), Math.max(MIN_SIZE, height)),
                    type,
                    // A title may run to 255 characters; a layer's name is held to what a save accepts.
                    name: clampName(asset.title) || type,
                    assetId: asset.id,
                    style: { fit: 'cover', radius: 0 },
                    animations: {},
                };

                this.assetPickerOpen = false;
                this.place(element, 'Add ' + type);
            },

            /** Put a new element on top of the others, select it and remember the step. */
            place(element, label) {
                if (this.doc.elements.length >= this.maxElements) {
                    window.toast(`An ad may hold at most ${this.maxElements} elements.`);

                    return;
                }

                this.stopPreview();
                // Always on the stage itself, the way Canva adds one: placed at the stage's centre, a new
                // element inside the group being worked in would only stretch that group across the frame.
                element.parentId = null;
                this.exitGroups();
                this.doc.elements.push(element);
                renumberDepth(this.doc);
                this.selectedIds = [element.id];
                this.panelTab = 'style';
                this.commit(label);
            },

            /** A fresh box, centred, on top of everything else. */
            newBox(w, h) {
                return {
                    id: newId(),
                    x: Math.round((this.stage.width - w) / 2),
                    y: Math.round((this.stage.height - h) / 2),
                    w,
                    h,
                    rotation: 0,
                    opacity: 1,
                    z: this.doc.elements.length,
                    locked: false,
                    visible: true,
                };
            },

            /* ── The asset picker ──────────────────────────────────────── */

            /** `element` adds one to the stage, `layer` fills a background layer, `replace` swaps a picture. */
            openAssetPicker(mode = 'element', kind = null) {
                this.assetPickerMode = mode;
                this.assetPickerKind = kind;
                this.assetPickerOpen = true;
            },

            /** What the picker offers: the kind asked for, from this ad's shelf. */
            pickerAssets() {
                return this.assets.filter((asset) => (this.assetPickerKind === null || asset.kind === this.assetPickerKind)
                    && this.onThisShelf(asset));
            },

            /**
             * Whether an asset is on this ad's shelf: a file the platform shares with every organization always is (owner,
             * 2026-09-29). For the platform team building for an organization, only that organization's own besides, because the
             * compiler only ever uses an ad's own organization's files and the shared ones; an organization's own people are only
             * ever handed their own organization's and the shared. The picker and a paste both ask.
             */
            onThisShelf(asset) {
                if (!asset.organization_id) return true;
                // An ad for every organization uses the shared files alone: an organization's own would show in no other organization's copy.
                if (this.isShared()) return false;
                if (this.choosesOrganization && !this.organizationId) return false;

                return !this.organizationId || asset.organization_id === this.organizationId;
            },

            /** Made for every organization: a saved ad as the server says, a new one while All organizations is chosen. */
            isShared() {
                return this.adId ? this.shared : (this.choosesOrganization && !this.organizationId);
            },

            /**
             * A new ad's organization chosen again: the picker's storage meter was the other organization's, and a file already on the
             * stage that is not on the new shelf would show on no screen, so it is said at once.
             */
            organizationChanged() {
                this.shelfStorage = null;

                const ids = [
                    ...(this.doc.elements ?? []).map((element) => element.assetId),
                    ...(this.doc.stage?.background?.layers ?? []).map((layer) => layer?.assetId),
                ].filter((id) => id);
                const lost = this.assets.filter((asset) => ids.includes(asset.id) && !this.onThisShelf(asset)).length;

                if (lost > 0) {
                    window.toast(`${lost} ${lost === 1 ? 'file on the stage' : 'files on the stage'} will not show: `
                        + (this.isShared() ? 'an ad for every organization uses shared files only.' : 'they belong to another organization.'));
                }
            },

            /** A file uploaded from the picker is on the shelf at once, first in the grid, ready to pick. */
            onAssetUploaded(detail) {
                const asset = detail?.response?.asset;

                this.shelfStorage = detail?.response?.storage ?? this.shelfStorage;

                if (!asset?.id || this.assets.some((each) => each.id === asset.id)) return;

                const { id, organization_id, title, kind, disk, path, thumbnail_path, width, height, duration_seconds, url, thumbnail_url } = asset;
                this.assets = [{ id, organization_id, title, kind, disk, path, thumbnail_path, width, height, duration_seconds, url, thumbnail_url }, ...this.assets];
            },

            pickerTitle() {
                if (this.assetPickerMode === 'layer') {
                    return this.assetPickerKind === 'video' ? 'Choose a video for the background' : 'Choose a picture for the background';
                }

                return this.assetPickerMode === 'replace' ? 'Replace the picture' : 'Choose a picture or a video';
            },

            pickAsset(asset) {
                if (this.assetPickerMode === 'layer') return this.setLayerAsset(asset);
                if (this.assetPickerMode === 'replace') return this.replaceAsset(asset);

                return this.addAsset(asset);
            },

            /** Another file in the same box — its size, place, frame and animations all stay. */
            replaceAsset(asset) {
                const element = this.selected;

                this.assetPickerOpen = false;

                if (!element) return;

                element.assetId = asset.id;
                element.type = asset.kind === 'video' ? 'video' : 'image';
                this.commit('Replace picture');
            },

            /* ── Moving, resizing, rotating ────────────────────────────── */

            /**
             * Pointer down on an element. Shift or Ctrl adds it to (or takes it out of) the selection; a
             * plain press selects it — unless it is already part of a selection, which then moves as one.
             */
            startDrag(event, element) {
                // The innermost element under the pointer answers for the press, and only it: every group
                // around it has a handler of its own on the same press, and must not act on it again (§13) —
                // not select itself over a text being edited, not start a second pan.
                event.stopPropagation();
                this.closeContextMenu();

                if (event.button !== 0) return;
                if (this.spaceHeld) return this.startPan(event);
                // Words being edited where they stand keep the press: the caret goes where it was clicked.
                if (this.editingTextId === element.id) return;

                // The group being worked in, pressed where it holds nothing, is the empty stage of its level:
                // a drag from there draws a marquee over its children and a plain click is a click on empty
                // stage — never the group itself picked up and dragged away as one.
                if (element.id === this.editingGroupId) return this.startMarquee(event);

                // Anything inside a group is the group, unless the group has been entered (§13).
                const level = this.editingGroupId;
                const target = this.resolveTarget(element);

                if (!target || this.isLocked(target)) return;

                event.preventDefault();

                if (event.shiftKey || event.ctrlKey || event.metaKey) {
                    if (this.editingGroupId === level) this.toggleInSelection(target);
                    else this.setSelection([target.id]);

                    return;
                }

                if (!this.selectedIds.includes(target.id)) this.setSelection([target.id]);

                this.stopPreview();

                // A group moves with everything inside it: the subtree travels in the same gesture.
                const chosen = this.selection().filter((item) => !this.isLocked(item));
                const items = chosen.flatMap((item) => [item, ...this.descendantsOf(item)]);

                this.beginGesture(event, {
                    kind: 'move',
                    items: items.map((item) => ({ element: item, x: item.x, y: item.y })),
                    bounds: boundsOf(chosen),
                });
            },

            /**
             * Pointer down on one of the eight handles (one element selected). A group scales what it
             * holds about its box; from a corner the shape is kept, from a side the boxes stretch (§13).
             */
            startResize(event, element, handle) {
                event.preventDefault();
                event.stopPropagation();
                this.stopPreview();

                this.beginGesture(event, {
                    kind: 'resize',
                    handle,
                    start: { x: element.x, y: element.y, w: element.w, h: element.h, rotation: element.rotation ?? 0 },
                    element,
                    subtree: isGroup(element) ? this.subtreeStart(element) : null,
                    corner: handle.length === 2,
                });
            },

            /** Pointer down on the rotate handle. A group turns everything inside it about its centre (§13). */
            startRotate(event, element) {
                event.preventDefault();
                event.stopPropagation();
                this.stopPreview();

                const point = this.toStagePoint(event.clientX, event.clientY);
                const subtree = isGroup(element) ? this.subtreeStart(element) : null;
                // A group turns about the centroid of what it holds — a point the turn itself leaves where it
                // was, so turning back by the same angle puts everything back where it stood. (The centre of
                // its box would not do: the box around turned children is another box.)
                const pivot = subtree ? this.pivotOf(subtree) : { x: element.x + element.w / 2, y: element.y + element.h / 2 };
                const around = { x: pivot.x, y: pivot.y, w: 0, h: 0 };

                this.beginGesture(event, {
                    kind: 'rotate',
                    start: { x: element.x, y: element.y, w: element.w, h: element.h, rotation: element.rotation ?? 0 },
                    element,
                    subtree,
                    centre: pivot,
                    around,
                    startAngle: angleFromCentre(around, point.x, point.y),
                });
            },

            beginGesture(event, gesture) {
                this.gesture = { ...gesture, originX: event.clientX, originY: event.clientY };

                const move = (moveEvent) => this.onGestureMove(moveEvent);
                const up = () => {
                    window.removeEventListener('pointermove', move);
                    window.removeEventListener('pointerup', up);
                    this.endGesture();
                };

                window.addEventListener('pointermove', move);
                window.addEventListener('pointerup', up);
            },

            onGestureMove(event) {
                const gesture = this.gesture;

                if (!gesture) return;

                const dx = toStage(event.clientX - gesture.originX, this.zoom);
                const dy = toStage(event.clientY - gesture.originY, this.zoom);

                if (gesture.kind === 'move') {
                    // The group snaps as one box: its edges and middle to the stage, the guides and
                    // everything that is not moving with it.
                    // Never to the groups around what moves — their edges ARE its edges when the drag begins, so
                    // it would stick where it stood — and never to anything that cannot be seen.
                    const moving = new Set(gesture.items.map((item) => item.element.id));
                    const around = new Set(gesture.items.flatMap((item) => this.ancestorIds(item.element)));
                    const others = this.doc.elements.filter((other) => !moving.has(other.id) && !around.has(other.id) && this.isShown(other));
                    const moved = snapMove(
                        { ...gesture.bounds, x: gesture.bounds.x + dx, y: gesture.bounds.y + dy },
                        this.stage,
                        others,
                        undefined,
                        this.showRulers ? this.doc.guides : null,
                    );
                    const shiftX = moved.x - gesture.bounds.x;
                    const shiftY = moved.y - gesture.bounds.y;

                    gesture.items.forEach((item) => {
                        item.element.x = item.x + shiftX;
                        item.element.y = item.y + shiftY;
                    });

                    this.snapLines = moved.guides;
                }

                if (gesture.kind === 'resize') {
                    // The handles turn with the element, so a turned one is resized along its own sides.
                    // A group keeps its shape from a corner unless Shift asks to stretch it.
                    const uniform = gesture.subtree ? gesture.corner && !event.shiftKey : event.shiftKey;
                    const box = resizeRotated(gesture.start, gesture.handle, dx, dy, gesture.start.rotation, {
                        keepRatio: uniform,
                        fromCentre: event.altKey,
                    });

                    if (gesture.subtree) {
                        this.scaleGroupTo(gesture.element, box, gesture.subtree, uniform);
                    } else {
                        Object.assign(gesture.element, box);
                    }
                }

                if (gesture.kind === 'rotate') {
                    const point = this.toStagePoint(event.clientX, event.clientY);

                    if (gesture.subtree) {
                        const delta = normaliseAngle(angleFromCentre(gesture.around, point.x, point.y) - gesture.startAngle);

                        this.rotateGroupBy(gesture.element, gesture.subtree, gesture.centre, event.shiftKey ? stepAngle(delta) : delta);
                    } else {
                        const angle = angleFromCentre(gesture.element, point.x, point.y);

                        gesture.element.rotation = event.shiftKey ? stepAngle(angle) : angle;
                    }
                }
            },

            endGesture() {
                const gesture = this.gesture;

                if (!gesture) return;

                this.gesture = null;
                this.snapLines = { x: null, y: null };

                // A press let go without changing anything — a click on an element or a handle — is not a
                // step to undo, and nothing new to save.
                if (!gestureChangedSomething(gesture)) return;

                this.commit({ move: 'Move', resize: 'Resize', rotate: 'Rotate' }[gesture.kind]);
            },

            /**
             * A drag on empty stage (or around it) draws a marquee and selects what it touches; Shift keeps
             * what was already selected. A press that does not move simply clears the selection.
             */
            startMarquee(event) {
                if (event.button !== 0) return;
                if (this.spaceHeld) return this.startPan(event);
                if (this.previewing === 'all') return;

                event.preventDefault();

                const additive = event.shiftKey || event.ctrlKey || event.metaKey;
                const kept = additive ? [...this.selectedIds] : [];
                const origin = this.toStagePoint(event.clientX, event.clientY);

                if (!additive) this.clearSelection();

                let moved = false;

                const move = (moveEvent) => {
                    moved = true;

                    const point = this.toStagePoint(moveEvent.clientX, moveEvent.clientY);
                    const box = {
                        x: Math.min(origin.x, point.x),
                        y: Math.min(origin.y, point.y),
                        w: Math.abs(point.x - origin.x),
                        h: Math.abs(point.y - origin.y),
                    };

                    this.marquee = box;

                    // What is at this level — a group as one box, never the things inside it.
                    const touched = this.levelItems()
                        .filter((element) => this.isShown(element) && !this.isLocked(element) && intersects(box, element))
                        .map((element) => element.id);

                    this.selectedIds = [...new Set([...kept, ...touched])];
                };

                const up = () => {
                    window.removeEventListener('pointermove', move);
                    window.removeEventListener('pointerup', up);
                    this.marquee = null;

                    // A plain click on empty stage also steps out of the group being worked in (§13).
                    if (!moved && !additive) this.exitGroups();
                };

                window.addEventListener('pointermove', move);
                window.addEventListener('pointerup', up);
            },

            /* ── Editing one element ───────────────────────────────────── */

            /**
             * A box number from the panel, held inside AdCompiler::LIMITS and rounded. Returns what was
             * kept, so the input can show it (a typed 99999 becomes the limit, visibly).
             */
            setBox(key, value) {
                const element = this.selected;

                if (!element) return value;

                let kept = limit(this.limits, key, value, null);

                if (kept === null) return element[key];

                kept = Math.round(kept);

                if (key === 'w' || key === 'h') kept = Math.max(MIN_SIZE, kept);

                // A group's numbers are its children's: a new place moves them, a new size stretches them
                // (§13), and a group has no angle of its own.
                if (isGroup(element)) {
                    if (key === 'rotation') return 0;

                    if (key === 'x' || key === 'y') {
                        this.moveSubtree(element, key === 'x' ? kept - element.x : 0, key === 'y' ? kept - element.y : 0);
                    } else {
                        this.scaleGroupTo(element, { ...element, [key]: kept }, this.subtreeStart(element), false);
                    }

                    this.commit('Position');

                    return kept;
                }

                element[key] = kept;
                this.commit('Position');

                return kept;
            },

            setOpacity(value) {
                const element = this.selected;

                if (!element) return value;

                const kept = limit(this.limits, 'opacity', value, element.opacity ?? 1);

                element.opacity = kept;
                this.commit('Opacity');

                return kept;
            },

            /** Opacity as a person types it, 0 to 100 %; the design keeps 0 to 1. Answers the percent kept. */
            setOpacityPercent(percent) {
                return this.percentOf(this.setOpacity(fractionOf(percent)));
            },

            setSelectionOpacityPercent(percent) {
                return this.percentOf(this.setSelectionOpacity(fractionOf(percent)));
            },

            /** 0.5 → 50. Something that is not a number comes back as it was, for the field to show. */
            percentOf(fraction) {
                const number = Number(fraction);

                return String(fraction ?? '').trim() === '' || !Number.isFinite(number) ? fraction : Math.round(number * 100);
            },

            /** One opacity for every selected element. */
            setSelectionOpacity(value) {
                const items = this.selection().filter((element) => !this.isLocked(element));
                const kept = limit(this.limits, 'opacity', value, null);

                if (kept === null || items.length === 0) return value;

                items.forEach((element) => {
                    element.opacity = kept;
                });
                this.commit('Opacity');

                return kept;
            },

            setStyle(key, value) {
                const element = this.selected;

                if (!element) return;

                element.style = { ...(element.style ?? {}), [key]: value };
                this.commit('Style');
            },

            /** A style number, held inside its row of AdCompiler::LIMITS. Returns what was kept. */
            setStyleNumber(key, value) {
                const element = this.selected;

                if (!element) return value;

                const kept = limit(this.limits, key, value, null);

                if (kept === null) return element.style?.[key] ?? '';

                this.setStyle(key, kept);

                return kept;
            },

            setText(value) {
                const element = this.selected;

                if (!element || element.type !== 'text') return;

                // No more than the server takes (the box says so too, with maxlength), so a save is never refused over it.
                element.text = clampName(value, MAX_TEXT);
                this.commit('Text');
            },

            /** Double-click on a text element edits it where it stands. */
            startTextEdit(element) {
                if (element.type !== 'text' || this.isLocked(element)) return;

                this.stopPreview();
                this.selectedIds = [element.id];
                this.editingTextId = element.id;

                // The node is found rather than referenced: Alpine's x-ref is a static name, and these
                // elements are drawn by an x-for. It is found the way the preview finds an element — by its
                // animation id inside the stage, escaped — never through a test's selector.
                this.$nextTick(() => {
                    const node = this.$refs.stage?.querySelector(`[data-anim-id="${CSS.escape(element.id)}"] [contenteditable]`);

                    if (!node) return;

                    node.focus();
                    document.getSelection()?.selectAllChildren(node);
                });
            },

            /** Inline editing keeps the words only: a pasted page of HTML is not a design. */
            finishTextEdit(event, element) {
                this.editingTextId = null;

                // Typed on the stage there is no maxlength to stop at: cut to what the server takes instead.
                const text = clampName((event.target.innerText ?? '').replace(/ /g, ' ').trim(), MAX_TEXT);

                if (text !== element.text) {
                    element.text = text;
                    this.commit('Text');
                }
            },

            /* ── Pictures ──────────────────────────────────────────────── */

            toggleFlip(axis) {
                const key = axis === 'x' ? 'flipX' : 'flipY';

                this.setStyle(key, this.selected?.style?.[key] !== true);
            },

            /** A picture filter, held inside AdCompiler::LIMITS. Returns what was kept. */
            setFilter(key, value) {
                const element = this.selected;

                if (!element) return value;

                const kept = limit(this.limits, 'filters.' + key, value, null);

                if (kept === null) return element.style?.filters?.[key];

                element.style = {
                    ...(element.style ?? {}),
                    filters: { ...(element.style?.filters ?? {}), [key]: kept },
                };
                this.commit('Filter');

                return kept;
            },

            resetFilters() {
                this.setStyle('filters', null);
            },

            /* ── Layers ────────────────────────────────────────────────── */

            bringForward() {
                this.reorder('forward');
            },

            sendBackward() {
                this.reorder('backward');
            },

            toggleLock(element) {
                element.locked = !element.locked;

                if (element.locked) {
                    // Locking a group locks what is inside it too: nothing in there stays selected or open.
                    const inside = new Set(this.descendantsOf(element).map((item) => item.id));

                    this.selectedIds = this.selectedIds.filter((id) => id !== element.id && !inside.has(id));

                    if (this.editingGroupId === element.id || inside.has(this.editingGroupId)) this.editingGroupId = parentIdOf(element);
                }

                this.commit(element.locked ? 'Lock' : 'Unlock');
            },

            /** Ctrl+L: lock everything selected. A locked element is unlocked from its padlock in the Layers panel. */
            toggleLockSelection() {
                const items = this.selection();

                if (items.length === 0) return;

                items.forEach((element) => {
                    element.locked = true;
                });

                this.selectedIds = [];
                this.commit('Lock');
            },

            toggleVisible(element) {
                element.visible = element.visible === false;
                this.commit('Visibility');
            },

            hideSelection() {
                const items = this.selection();

                if (items.length === 0) return;

                items.forEach((element) => {
                    element.visible = false;
                });
                this.commit('Hide');
            },

            rename(element, value) {
                element.name = clampName((value ?? '').trim()) || element.type;
                this.commit('Rename');
            },

            /** Double-click a layer's name to rename it where it is. */
            startRename(element) {
                this.renamingId = element.id;
            },

            finishRename(element, value) {
                if (this.renamingId !== element.id) return;

                this.renamingId = null;

                if ((value ?? '').trim() !== (element.name ?? '')) this.rename(element, value);
            },

            /* The Layers panel is front first; a row dropped ABOVE another goes in front of it. */

            layerDragStart(event, element) {
                this.layerDrag = { id: element.id, overId: null, place: null };
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', element.id);
            },

            /**
             * Over a row: the top and bottom parts mean "beside this row", the middle of a group's row
             * means "inside this group" (§13).
             */
            layerDragOver(event, element) {
                if (!this.layerDrag || this.layerDrag.id === element.id) return;

                const rect = event.currentTarget.getBoundingClientRect();
                const at = (event.clientY - rect.top) / Math.max(1, rect.height);
                // Just under an OPEN group's row is the top of what it holds — its first child's row is the
                // next one down — so that is where the element goes, not behind everything inside the group.
                const open = isGroup(element) && !this.collapsedGroupIds.includes(element.id) && this.childrenOf(element.id).length > 0;
                const inside = isGroup(element) && at > 0.3 && (at < 0.7 || open);

                this.layerDrag = {
                    ...this.layerDrag,
                    overId: element.id,
                    place: inside ? 'inside' : (at < 0.5 ? 'above' : 'below'),
                };
            },

            /**
             * Dropped beside a row, the element (with everything inside it) joins that row's group at its
             * place; dropped onto a group, it goes in on top. Never into itself, and never deeper than
             * groups go.
             */
            layerDrop(event, target) {
                const drag = this.layerDrag;

                this.layerDrag = null;

                if (!drag || drag.id === target.id) return;

                const place = drag.place ?? 'above';
                const moving = this.doc.elements.find((element) => element.id === drag.id);

                if (!moving) return;

                if (this.descendantsOf(moving).some((element) => element.id === target.id)) return;

                const parentId = place === 'inside' ? target.id : parentIdOf(target);

                if (!this.fitsUnder(parentId, [moving])) {
                    window.toast('Groups can be three deep at most.');

                    return;
                }

                moving.parentId = parentId;
                // Back to front: above in the panel is AFTER in the stacking order.
                moving.z = place === 'inside'
                    ? Math.max(target.z ?? 0, ...this.childrenOf(target.id).map((child) => child.z ?? 0)) + 0.5
                    : (target.z ?? 0) + (place === 'above' ? 0.5 : -0.5);
                renumberDepth(this.doc);

                if (this.selectedIds.includes(moving.id) && parentId !== this.editingGroupId) this.editingGroupId = parentId;

                this.commit('Reorder');
            },

            layerDragEnd() {
                this.layerDrag = null;
            },

            layerDropClass(element) {
                if (this.layerDrag?.overId !== element.id) return '';

                return {
                    above: 'border-t-2 border-blue-500',
                    below: 'border-b-2 border-blue-500',
                    inside: 'ring-2 ring-inset ring-blue-500',
                }[this.layerDrag.place] ?? '';
            },

            /* ── Duplicate and delete ──────────────────────────────────── */

            /** Copies of everything selected, a little further on, on top — and selected. */
            duplicate() {
                const items = this.selection();

                if (items.length === 0) return;

                // A group is copied with everything inside it (§13), so everything inside it counts.
                const adding = items.reduce((count, element) => count + 1 + this.descendantsOf(element).length, 0);

                if (this.doc.elements.length + adding > this.maxElements) {
                    window.toast(`An ad may hold at most ${this.maxElements} elements.`);

                    return;
                }

                this.stopPreview();

                // The copies sit beside their originals, in the same group.
                const suffix = ' copy';
                const ids = [];

                this.subtreeClones(items, parentIdOf(items[0])).forEach(({ original, copy, root }) => {
                    copy.x += 32;
                    copy.y += 32;
                    copy.z = this.doc.elements.length + (root ? 0.5 : 0.75);

                    // Room is made for the suffix, so a long name's copy is still one a save accepts.
                    if (root) copy.name = clampName(original.name ?? original.type, MAX_NAME - suffix.length) + suffix;

                    this.doc.elements.push(copy);

                    if (root) ids.push(copy.id);
                });

                renumberDepth(this.doc);
                this.selectedIds = ids;
                this.commit('Duplicate');
            },

            /** The delete button: everything selected that is not locked. */
            remove() {
                this.removeSelection('Delete');
            },

            removeSelection(label = 'Delete') {
                const chosen = this.selection().filter((element) => !this.isLocked(element));
                // A group goes with everything inside it (§13).
                const going = new Set(chosen.flatMap((element) => [element, ...this.descendantsOf(element)]).map((element) => element.id));

                if (going.size === 0) return;

                this.stopPreview();
                this.doc.elements = this.doc.elements.filter((element) => !going.has(element.id));
                renumberDepth(this.doc);
                this.selectedIds = this.selectedIds.filter((id) => !going.has(id));
                this.commit(label);
            },

            /** Arrow keys move everything selected, a pixel at a time (ten with Shift). */
            nudge(dx, dy) {
                const items = this.selection().filter((element) => !this.isLocked(element));

                if (items.length === 0) return;

                items.forEach((element) => this.moveSubtree(element, dx, dy));
                this.commit('Move');
            },

            /* ── History ───────────────────────────────────────────────── */

            undo() {
                const document = this.history.undo();

                if (!document) return;

                this.stopPreview();
                this.doc = normaliseDocument(document);
                this.markChanged();
                this.leaveMissingGroup();
                this.ensureSelectionExists();
            },

            redo() {
                const document = this.history.redo();

                if (!document) return;

                this.stopPreview();
                this.doc = normaliseDocument(document);
                this.markChanged();
                this.leaveMissingGroup();
                this.ensureSelectionExists();
            },

            /* ── Saving ────────────────────────────────────────────────── */

            /**
             * Save the design. A save by hand also photographs the stage for the poster; autosave does not,
             * so it never makes the editor stutter every half minute (Publish then takes a fresh poster).
             * Returns whether the save went through.
             */
            async save({ auto = false } = {}) {
                if (this.saving) return false;

                // Changing a saved ad is Update Ads (the route's own lock). Somebody who may create ads but
                // not change them has created this one, and is told so here rather than refused by the server.
                if (this.adId && !this.canUpdate) {
                    if (!auto) window.toast('This ad is saved. Changing a saved ad needs the Update Ads permission, which you do not have.');

                    return false;
                }

                // An ad needs a name (BuilderAdRequest, in its words): said before anything is sent, on its box.
                if (String(this.name ?? '').trim() === '') {
                    this.nameError = 'Give the ad a name.';

                    if (!auto || this.lastAutosaveError !== this.nameError) window.toast(this.nameError);
                    if (auto) this.lastAutosaveError = this.nameError;

                    return false;
                }

                this.saving = true;

                try {
                    const sentRevision = this.revision;
                    const payload = { name: this.name, document: clone(this.doc) };

                    if (this.organizationId) payload.organization_id = this.organizationId;
                    // Said once, with the first save: a saved ad's orientation is its own (§12).
                    if (!this.adId) payload.orientation = this.orientation;

                    if (!auto) {
                        this.stopPreview();

                        const poster = await capturePoster(this.$refs.stage, this.stage.width, this.stage.height);

                        if (poster) payload.thumbnail = poster;
                    }

                    const { data } = this.adId
                        ? await axios.put(`/builder/${this.adId}`, payload)
                        : await axios.post('/builder', payload);

                    // A new ad gets its id and its address, without a reload: the person carries on drawing.
                    // That address is the editor's, which is Update Ads; somebody who may only create stays on
                    // Create, so a reload opens a fresh ad rather than a refusal.
                    if (!this.adId) {
                        this.adId = data.ad.id;
                        this.shared = data.ad.shared ?? false;

                        if (this.canUpdate) window.history.replaceState({}, '', `/builder/${this.adId}`);
                    }

                    // Only what was sent is saved: a change made while the request was on its way stays unsaved.
                    this.dirty = this.revision !== sentRevision;
                    // The server says where that leaves the ad: a published one keeps its published version on
                    // the screens, now with changes waiting to be published.
                    this.adopt(data.ad);
                    this.lastSavedAt = new Date();
                    this.lastSaveWasAuto = auto;

                    if (payload.thumbnail && !this.dirty) this.posterStale = false;
                    if (!auto) window.toast(data.message, 'success');
                    this.lastAutosaveError = null;

                    return true;
                } catch (error) {
                    const errors = error.response?.data?.errors ?? {};
                    const said = Object.values(errors)[0]?.[0] ?? error.response?.data?.message ?? 'Could not save this ad.';

                    if (errors.name) this.nameError = errors.name[0];

                    // A save by hand always says why; an autosave that keeps failing for the same reason says it once.
                    if (!auto || said !== this.lastAutosaveError) window.toast(said);
                    if (auto) this.lastAutosaveError = said;

                    return false;
                } finally {
                    this.saving = false;
                }
            },

            /**
             * Every half minute: save a saved ad that has changes — never mid-gesture, mid-word or while the
             * whole ad plays. Returns whether it saved (the browser tests call it directly).
             */
            autosaveTick() {
                if (!this.autosave || !this.adId || !this.dirty || !this.canUpdate) return false;
                if (this.saving || this.publishing || this.gesture || this.editingTextId || this.previewing === 'all') return false;

                this.save({ auto: true });

                return true;
            },

            toggleAutosave() {
                this.autosave = !this.autosave;
                writePreference('autosave', this.autosave);
            },

            /* ── Where the ad stands with the screens ────────────────────── */
            /* The industry's draft/publish model (owner, 2026-09-21 — Xibo, Contentful, Strapi; docs/AD-BUILDER-SPEC.md
             * §9): a save — by hand or by autosave — changes the draft only. The screens keep the published version
             * until Publish; Discard changes goes back to it; Unpublish takes the ad off every screen. */

            /** The server's word on the ad, after a save, a publish or an unpublish. */
            adopt(ad) {
                if (!ad) return;

                this.published = ad.is_published ?? false;
                this.hasChanges = ad.status === 'changed';
                this.hasPublishedVersion = ad.has_published_version ?? false;
                this.shared = ad.shared ?? this.shared;
            },

            /** Changes the screens do not show yet — saved ones, or ones still only here. */
            changesWaiting() {
                return this.published && (this.hasChanges || this.dirty);
            },

            /** Short, because the bar is narrow on a laptop; publicationHint() is the whole sentence. */
            publicationStatus() {
                if (!this.adId) return '';
                if (!this.published) return this.isShared() ? 'Draft · organizations do not see it' : 'Draft · not on screens';

                return this.changesWaiting() ? 'Changes not published' : 'Published';
            },

            publicationTone() {
                if (!this.published) return 'text-gray-500 dark:text-gray-400';

                return this.changesWaiting() ? 'text-amber-700 dark:text-amber-400' : 'text-green-700 dark:text-green-400';
            },

            publicationHint() {
                // An ad for every organization is published for the organizations to see and copy (owner, 2026-10-01).
                if (this.isShared()) {
                    if (!this.published) return 'No organization sees it until you publish it.';

                    return this.changesWaiting()
                        ? 'Organizations keep seeing the published version until you publish these changes.'
                        : 'Every organization sees it, exactly as it is here, and can copy it.';
                }

                if (!this.published) return 'Not on any screen until you publish it.';

                return this.changesWaiting()
                    ? 'The screens keep showing the published version until you publish these changes.'
                    : 'On every screen that carries it, exactly as it is here.';
            },

            publishLabel() {
                if (this.publishing) return 'Publishing…';
                if (!this.published) return 'Publish';

                return this.changesWaiting() ? 'Publish Changes' : 'Publish Again';
            },

            publishHint() {
                if (this.isShared()) {
                    return this.changesWaiting() ? 'Show these changes to every organization' : 'Let every organization see this ad and copy it';
                }

                return this.changesWaiting()
                    ? 'Put these changes on every screen that carries this ad'
                    : 'Compile this ad and add it to the media library';
            },

            /** Discard goes back to the kept published version: there has to be one, and something to leave. */
            mayDiscard() {
                return this.hasPublishedVersion && this.changesWaiting() && !this.discarding;
            },

            discardHint() {
                if (this.published && !this.hasPublishedVersion) {
                    return 'This ad was published before its published version was kept — publish it once to keep one.';
                }

                return this.changesWaiting() ? '' : 'There are no changes to discard.';
            },

            /** Throw the changes away: saved ones on the server, unsaved ones by opening the ad afresh. */
            async discardChanges() {
                if (this.discarding || !this.mayDiscard()) return;

                this.discarding = true;

                try {
                    if (this.hasChanges) await axios.post(`/builder/${this.adId}/discard`);

                    // The page reloads with the published design; nothing unsaved is left worth a warning.
                    this.dirty = false;
                    window.location.reload();
                } catch (error) {
                    window.toast(error.response?.data?.message ?? 'Could not discard the changes.');
                    this.discarding = false;
                }
            },

            async unpublish() {
                if (this.unpublishing || !this.published) return;

                this.unpublishing = true;

                try {
                    const { data } = await axios.post(`/builder/${this.adId}/unpublish`);
                    this.adopt(data.ad);
                    this.$dispatch('close-modal', 'confirm-unpublish');
                    window.toast(data.message, 'success');
                } catch (error) {
                    window.toast(error.response?.data?.message ?? 'Could not unpublish this ad.');
                } finally {
                    this.unpublishing = false;
                }
            },

            /** What the bar says about saving. */
            saveStatus() {
                if (this.saving) return 'Saving…';
                if (this.dirty) return 'Unsaved changes';
                if (!this.lastSavedAt) return '';

                const time = this.lastSavedAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

                return (this.lastSaveWasAuto ? 'Autosaved ' : 'Saved ') + time;
            },

            /**
             * Publish: save first if there is anything to save (or the poster is out of date), then ask the
             * server to compile the design into a page and put it in the media library — which is where a
             * playlist can reach it.
             */
            async publish() {
                if (this.publishing || this.saving) return;

                if (this.dirty || !this.adId || this.posterStale) {
                    if (!(await this.save())) return;
                }

                if (!this.adId || this.dirty) return;

                this.publishing = true;

                try {
                    const { data } = await axios.post('/builder/' + this.adId + '/publish');
                    this.adopt(data.ad);
                    window.toast(data.message, 'success');
                } catch (error) {
                    window.toast(error.response?.data?.message ?? 'Could not publish this ad.');
                } finally {
                    this.publishing = false;
                }
            },

            /**
             * The saved design in a tab of its own, exactly as a television would show it. Unsaved work is
             * saved first; the tab is opened at once, inside the click, so no popup blocker stops it.
             */
            async openPreview() {
                if (this.saving) return;

                if (this.adId && !this.dirty) {
                    window.open(`/builder/${this.adId}/preview`, '_blank', 'noopener');

                    return;
                }

                const tab = window.open('', '_blank');

                if (tab) tab.opener = null;

                if (!(await this.save()) || !this.adId) {
                    tab?.close();

                    return;
                }

                if (tab) tab.location.href = `/builder/${this.adId}/preview`;
                else window.open(`/builder/${this.adId}/preview`, '_blank', 'noopener');
            },

            /* ── Fonts ─────────────────────────────────────────────────── */

            /**
             * The catalogue, fetched once — as soon as the editor opens on a design that names a font (so
             * the stage shows it), or else when the picker is first opened. Both may ask at once; one request
             * goes out.
             */
            async loadFonts() {
                if (this.fontsLoaded || this.loadingFonts) return;

                this.loadingFonts = true;
                this.fontsFailed = false;

                try {
                    const { data } = await axios.get('/builder/fonts');
                    this.fonts = data.fonts ?? [];
                    this.fontsLoaded = true;

                    // Whatever this design already uses has to be visible on the stage, not just named.
                    this.fonts
                        .filter((font) => font.css_url && this.usedFamilies().includes(font.family))
                        .forEach((font) => this.attachFont(font));
                } catch {
                    // The picker says so, with Try again (fontsFailed); the toast is for a picker not open yet.
                    this.fontsFailed = true;
                    window.toast('Could not load the font list.');
                } finally {
                    this.loadingFonts = false;
                }
            },

            /** Try the font list again after it failed. */
            retryFonts() {
                this.fontsFailed = false;
                this.loadFonts();
            },

            /** Every family the document sets text in. */
            usedFamilies() {
                return this.doc.elements
                    .filter((element) => element.type === 'text')
                    .map((element) => element.style?.fontFamily)
                    .filter(Boolean);
            },

            openFontPicker() {
                this.fontPickerOpen = true;
                this.loadFonts();
            },

            get fontResults() {
                const query = this.fontQuery.trim().toLowerCase();

                return this.fonts.filter((font) => (this.fontKind === 'all' || font.kind === this.fontKind)
                    && (query === '' || font.family.toLowerCase().includes(query)));
            },

            fontKindLabel(kind) {
                return FONT_KINDS[kind] ?? kind;
            },

            /** A row's name, in its family once installed, in the family's preview once that is in, else in the panel's. */
            fontRowStyle(font) {
                if (font.installed) return { fontFamily: `'${font.family}', sans-serif` };

                return this.fontPreviews[font.family] === 'ready' ? { fontFamily: `'${previewFamily(font.family)}', sans-serif` } : {};
            },

            /**
             * A family not installed yet, drawn in its own letters (owner, 2026-10-06: "font per uski style dikhe"):
             * Google's stylesheet for the letters of its name alone — a few KB — loaded as a face of its own name,
             * so it never stands in for the family on the stage. Only rows the picker scrolls into sight ask
             * (watchFontRow); a preview that cannot be had leaves the row in the panel's font.
             */
            async previewFont(font) {
                if (font.installed || this.fontPreviews[font.family]) return;

                this.fontPreviews[font.family] = 'loading';
                const weight = (font.weights ?? []).includes(400) ? 400 : (font.weights?.[0] ?? 400);

                try {
                    const response = await fetch(`https://fonts.googleapis.com/css2?family=${encodeURIComponent(font.family)}:wght@${weight}&text=${encodeURIComponent(font.family)}`);
                    const url = (await response.text()).match(/url\((https:\/\/fonts\.gstatic\.com\/[^)]+)\)/)?.[1];

                    if (!response.ok || !url) throw new Error('No preview for this family.');

                    const face = new FontFace(previewFamily(font.family), `url(${url})`, { weight: String(weight) });
                    document.fonts.add(await face.load());
                    this.fontPreviews[font.family] = 'ready';
                } catch {
                    this.fontPreviews[font.family] = 'failed';
                }
            },

            /** Preview a row's family once the picker scrolls the row into sight, and only then. */
            watchFontRow(element, font) {
                if (font.installed) return;

                fontRows.observer ??= new IntersectionObserver((entries) => entries
                    .filter((entry) => entry.isIntersecting)
                    .forEach((entry) => {
                        fontRows.observer.unobserve(entry.target);
                        fontRows.preview.get(entry.target)?.();
                    }), { root: this.$refs.fontPanel, rootMargin: '200px 0px' });

                fontRows.preview.set(element, () => this.previewFont(font));
                fontRows.observer.observe(element);
            },

            /**
             * Use a family. One that has never been used here is downloaded from Google first — once, for
             * the whole installation — so the advert can be shown on a television with no internet.
             */
            async pickFont(font) {
                if (this.installingFamily) return;

                // Fetching a family is for whoever may create or change an ad; the picker says so on the row.
                if (!font.installed && !this.canInstallFonts) return;

                if (!font.installed) {
                    this.installingFamily = font.family;

                    try {
                        const { data } = await axios.post('/builder/fonts', { family: font.family });
                        Object.assign(font, data.font);
                        window.toast(data.message, 'success');
                    } catch (error) {
                        const message = error.response?.data?.errors?.family?.[0]
                            ?? error.response?.data?.message
                            ?? 'Could not install that font.';
                        window.toast(message);

                        return;
                    } finally {
                        this.installingFamily = null;
                    }
                }

                this.attachFont(font);
                this.setStyle('fontFamily', font.family);

                // A weight the family does not have would render as a fake bold, so the nearest real one wins.
                const weights = font.weights ?? [400];
                const current = this.selected?.style?.fontWeight ?? 400;

                if (!weights.includes(Number(current))) {
                    this.setStyle('fontWeight', weights.reduce(
                        (best, weight) => (Math.abs(weight - current) < Math.abs(best - current) ? weight : best),
                        weights[0],
                    ));
                }

                this.fontPickerOpen = false;
            },

            /** Put the family's stylesheet in the page, so the stage really shows it. */
            attachFont(font) {
                if (!font?.css_url || document.querySelector(`link[data-font="${CSS.escape(font.family)}"]`)) return;

                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = font.css_url;
                link.dataset.font = font.family;
                document.head.appendChild(link);
            },

            /** The weights this installation really has for the family in use. */
            get weightsForSelected() {
                const family = this.selected?.style?.fontFamily;
                const font = this.fonts.find((row) => row.family === family);

                return font?.weights ?? [300, 400, 500, 600, 700, 800, 900];
            },

            /** A key inside a nested style object (textShadow, border, shadow…), committed as one step. */
            setNestedStyle(group, key, value) {
                const element = this.selected;

                if (!element) return;

                const style = { ...(element.style ?? {}) };
                style[group] = { ...(style[group] ?? {}), [key]: value };
                element.style = style;
                this.commit('Style');
            },

            /** A nested number (`textShadow.blur`, `border.width`, `shadow.x`…) inside AdCompiler::LIMITS. */
            setNestedNumber(group, key, value) {
                const element = this.selected;

                if (!element) return value;

                const kept = limit(this.limits, `${group}.${key}`, value, null);

                if (kept === null) return element.style?.[group]?.[key] ?? '';

                this.setNestedStyle(group, key, kept);

                return kept;
            },

            /** Turn a nested effect on with sensible numbers, or off entirely. */
            toggleNestedStyle(group, defaults) {
                const element = this.selected;

                if (!element) return;

                const style = { ...(element.style ?? {}) };
                style[group] = style[group] ? null : defaults;
                element.style = style;
                this.commit('Style');
            },

            /* ── Keyboard ──────────────────────────────────────────────── */

            onKeydown(event) {
                // While typing into the panel or into a text element, the keyboard belongs to the text.
                const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName)
                    || event.target.isContentEditable;
                const control = event.ctrlKey || event.metaKey;
                const key = event.key.toLowerCase();

                if (control && key === 's') {
                    event.preventDefault();

                    // A field commits on `change` and a text element on `blur`, so what is being typed right
                    // now has not reached the design yet. Letting go of the field first is what puts it in
                    // this save rather than the next one.
                    if (typing) event.target.blur();

                    this.save();

                    return;
                }

                // An overlay of the editor's own (a picker, the shortcuts) takes Esc — even from its search box —
                // and keeps every other key to itself: nothing behind it is nudged, deleted or undone unseen.
                if (this.fontPickerOpen || this.assetPickerOpen || this.shortcutsOpen) {
                    if (event.key === 'Escape' || (this.shortcutsOpen && !typing && event.key === '?')) {
                        event.preventDefault();
                        this.fontPickerOpen = false;
                        this.assetPickerOpen = false;
                        this.shortcutsOpen = false;
                    }

                    return;
                }

                // A dialog of the panel answers its own keys.
                if (aDialogIsOpen()) return;

                // Esc closes an open menu — even from the checkbox inside it — back onto its button.
                if (event.key === 'Escape' && (this.historyOpen || this.moreOpen || this.publishMenuOpen)) {
                    event.preventDefault();

                    const button = this.historyOpen ? this.$refs.historyToggle
                        : (this.publishMenuOpen ? this.$refs.publishMenuToggle : this.$refs.moreToggle);

                    this.historyOpen = this.moreOpen = this.publishMenuOpen = false;
                    button?.focus({ preventScroll: true });

                    return;
                }

                if (typing) return;

                // A button, a link or a checkbox the KEYBOARD is on (focus-visible — not one just clicked with the
                // mouse): Space and Enter press it, as everywhere else, rather than panning or entering a group.
                if ((event.key === ' ' || event.key === 'Enter') && !control
                    && event.target instanceof Element && event.target.closest(PRESSABLE) && event.target.matches(':focus-visible')) {
                    return;
                }

                // While the whole ad plays the stage is a screen, not a canvas: nothing on it can be
                // changed unseen (the selection frame is hidden), and only Stop is listened to.
                if (this.previewing === 'all') {
                    if (event.key === 'Escape' || (control && key === 'p')) {
                        event.preventDefault();
                        this.stopPreview();
                    }

                    return;
                }

                if (event.key === 'Escape') {
                    if (this.contextMenu) this.closeContextMenu();
                    else if (this.previewing) this.stopPreview();
                    // Inside a group, Esc steps back out to the group itself (§13).
                    else if (this.editingGroupId !== null) this.exitGroup();
                    else this.clearSelection();

                    return;
                }

                // Enter goes into the selected group, the way Figma does it; Esc comes back out.
                if (event.key === 'Enter' && this.selected && isGroup(this.selected)) {
                    event.preventDefault();
                    this.enterGroup(this.selected);

                    return;
                }

                if (event.key === ' ') {
                    event.preventDefault();
                    this.spaceHeld = true;

                    return;
                }

                if (event.key === '?') {
                    event.preventDefault();
                    this.shortcutsOpen = !this.shortcutsOpen;

                    return;
                }

                if (control) {
                    const commands = {
                        z: () => (event.shiftKey ? this.redo() : this.undo()),
                        y: () => this.redo(),
                        d: () => this.duplicate(),
                        g: () => (event.shiftKey ? this.ungroupSelection() : this.groupSelection()),
                        a: () => this.selectAll(),
                        c: () => this.copySelection(),
                        x: () => this.cutSelection(),
                        v: () => (event.shiftKey ? this.pasteStyle() : (event.altKey ? this.pasteAnimation() : this.pasteClipboard())),
                        l: () => this.toggleLockSelection(),
                        p: () => this.togglePlay(),
                        0: () => this.zoomToFit(),
                        '=': () => this.zoomBy(0.1),
                        '+': () => this.zoomBy(0.1),
                        '-': () => this.zoomBy(-0.1),
                        '_': () => this.zoomBy(-0.1),
                        ']': () => this.reorder(event.shiftKey ? 'front' : 'forward'),
                        '}': () => this.reorder('front'),
                        '[': () => this.reorder(event.shiftKey ? 'back' : 'backward'),
                        '{': () => this.reorder('back'),
                    };
                    const command = commands[key] ?? commands[event.key];

                    if (command) {
                        event.preventDefault();
                        command();
                    }

                    return;
                }

                if (event.altKey) {
                    const edges = { a: 'left', h: 'center', d: 'right', w: 'top', v: 'middle', s: 'bottom' };

                    if (event.shiftKey && (key === 'h' || key === 'v')) {
                        event.preventDefault();
                        this.distributeSelection(key === 'h' ? 'x' : 'y');
                    } else if (edges[key]) {
                        event.preventDefault();
                        this.alignSelection(edges[key]);
                    }

                    return;
                }

                // Figma's: Shift+1 fits the stage, Shift+0 is 100 %. (Ctrl+1 is the browser's own first tab.)
                if (event.shiftKey && (event.code === 'Digit1' || event.code === 'Digit0')) {
                    event.preventDefault();

                    if (event.code === 'Digit1') this.zoomToFit();
                    else this.zoomTo(1);

                    return;
                }

                if (event.shiftKey && key === 'r') {
                    event.preventDefault();
                    this.toggleRulers();

                    return;
                }

                if (event.key === 'Delete' || event.key === 'Backspace') {
                    if (this.selectedIds.length === 0) return;

                    event.preventDefault();
                    this.removeSelection();

                    return;
                }

                const nudges = { ArrowUp: [0, -1], ArrowDown: [0, 1], ArrowLeft: [-1, 0], ArrowRight: [1, 0] };

                if (nudges[event.key] && this.selectedIds.length > 0) {
                    event.preventDefault();

                    const [dx, dy] = nudges[event.key];
                    const step = event.shiftKey ? 10 : 1;

                    this.nudge(dx * step, dy * step);
                }
            },

            onKeyup(event) {
                if (event.key === ' ') this.spaceHeld = false;
            },

            /* ── Drawing: the compiler's CSS, written the same way here ── */

            /**
             * An element's box as the stage paints it: against the stage, or against the group that holds
             * it — every element keeps stage coordinates, so a child is simply offset by its group's origin
             * (§13), exactly as the compiler writes it.
             */
            boxStyle(element, parent = null) {
                const css = boxCss(element, this.limits);

                if (parent) {
                    css.left = limit(this.limits, 'x', element.x, 0) - parent.x + 'px';
                    css.top = limit(this.limits, 'y', element.y, 0) - parent.y + 'px';
                }

                return css;
            },

            textStyle(element) {
                const css = textCss(element.style ?? {}, this.limits);

                // Words being edited where they stand are shown as they are stored. innerText — what an
                // edit reads back — applies text-transform, so an UPPERCASE heading edited as it looks
                // would have its words rewritten in capitals by an edit that changed nothing.
                if (this.editingTextId === element.id) css.textTransform = 'none';

                return css;
            },

            pictureStyle(element) {
                return pictureCss(element.style ?? {}, this.limits, this.filterTable);
            },

            shapeStyle(element) {
                return shapeCss(element.style ?? {}, this.limits, this.maxStops);
            },

            /** A selected element's frame: its box, outlined two screen pixels wide at any zoom. */
            frameStyle(element) {
                return {
                    left: element.x + 'px',
                    top: element.y + 'px',
                    width: element.w + 'px',
                    height: element.h + 'px',
                    transform: element.rotation ? `rotate(${element.rotation}deg)` : null,
                    outline: `${2 / this.zoom}px solid #3b82f6`,
                };
            },

            /** The dashed box around several selected elements. */
            groupFrameStyle() {
                const items = this.selection();

                if (items.length < 2) return { display: 'none' };

                const bounds = boundsOf(items);

                return {
                    left: bounds.x + 'px',
                    top: bounds.y + 'px',
                    width: bounds.w + 'px',
                    height: bounds.h + 'px',
                    outline: `${1 / this.zoom}px dashed #3b82f6`,
                };
            },

            /** A resize handle: twelve screen pixels at any zoom, on the frame's edge. */
            handleStyle(element, handle) {
                const size = 12 / this.zoom;

                return {
                    left: (handle.includes('w') ? 0 : handle.includes('e') ? element.w : element.w / 2) - size / 2 + 'px',
                    top: (handle.includes('n') ? 0 : handle.includes('s') ? element.h : element.h / 2) - size / 2 + 'px',
                    width: size + 'px',
                    height: size + 'px',
                    borderWidth: 1 / this.zoom + 'px',
                    cursor: handle + '-resize',
                };
            },

            rotateHandleStyle(element) {
                const size = 12 / this.zoom;

                return {
                    left: element.w / 2 - size / 2 + 'px',
                    top: -28 / this.zoom - size / 2 + 'px',
                    width: size + 'px',
                    height: size + 'px',
                    borderWidth: 1 / this.zoom + 'px',
                };
            },

            snapLineStyle(axis) {
                return axis === 'x'
                    ? { left: this.snapLines.x + 'px', top: '0', height: this.stage.height + 'px', width: 1 / this.zoom + 'px' }
                    : { top: this.snapLines.y + 'px', left: '0', width: this.stage.width + 'px', height: 1 / this.zoom + 'px' };
            },

            marqueeStyle() {
                if (!this.marquee) return { display: 'none' };

                return {
                    left: this.marquee.x + 'px',
                    top: this.marquee.y + 'px',
                    width: this.marquee.w + 'px',
                    height: this.marquee.h + 'px',
                    border: `${1 / this.zoom}px dashed #3b82f6`,
                };
            },

            /** Words over the stage, a steady size on screen whatever the zoom. */
            screenText(pixels) {
                return pixels / this.zoom + 'px';
            },

            assetFor(element) {
                return this.assets.find((asset) => asset.id === element.assetId) ?? null;
            },

            assetUrl(element) {
                return this.assetFor(element)?.url ?? null;
            },

            /** The picture a card shows for an asset — its thumbnail, or the file itself. */
            assetThumb(asset) {
                return asset.thumbnail_url ?? (asset.kind === 'video' ? null : asset.url);
            },
        };
    });
}

/** Whether a move, resize or rotate left anything different from where it began. */
function gestureChangedSomething(gesture) {
    if (gesture.kind === 'move') {
        return gesture.items.some((item) => item.element.x !== item.x || item.element.y !== item.y);
    }

    // A group's own box follows what is inside it, so what is inside is what is compared.
    if (gesture.subtree) {
        return gesture.subtree.some((start) => ['x', 'y', 'w', 'h', 'rotation'].some((key) => (start.element[key] ?? 0) !== (start[key] ?? 0)));
    }

    return ['x', 'y', 'w', 'h', 'rotation'].some((key) => (gesture.element[key] ?? 0) !== (gesture.start[key] ?? 0));
}
