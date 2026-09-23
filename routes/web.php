<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Advertising\CampaignController;
use App\Http\Controllers\Advertising\NetworkAdsController;
use App\Http\Controllers\Builder\BuilderAssetController;
use App\Http\Controllers\Builder\BuilderController;
use App\Http\Controllers\Builder\BuilderFontController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvitationResponseController;
use App\Http\Controllers\Platform\ImpersonateController;
use App\Http\Controllers\Platform\PermissionController;
use App\Http\Controllers\Platform\PlatformInvitationController;
use App\Http\Controllers\Platform\StoreController;
use App\Http\Controllers\Platform\UserController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\Signage\ChannelAdController;
use App\Http\Controllers\Signage\ChannelController;
use App\Http\Controllers\Signage\DaypartController;
use App\Http\Controllers\Signage\MediaController;
use App\Http\Controllers\Signage\PlaylistController;
use App\Http\Controllers\Signage\ScreenController;
use App\Http\Controllers\Store\InvitationController;
use App\Http\Controllers\Store\MemberController;
use App\Http\Controllers\Store\StoreSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

// -----------------------------------------------------------------------
// Player  (the page a TV loads - open on purpose: a screen cannot log in.
// It is an empty shell; every byte of real content is fetched from
// routes/device.php with the token this page was handed at pairing.)
// -----------------------------------------------------------------------
Route::view('/player', 'player.index')->name('player');

// The player's web-app manifest (docs/AD-BUILDER-SPEC.md §15): a route rather than a file in public/, so it
// carries the app's own name. Its icons and its service worker (public/player-sw.js) are plain files.
Route::get('/player.webmanifest', fn () => response()->json([
    'name' => config('app.name').' Player',
    'short_name' => 'Player',
    'description' => 'What a television shows: paired once, then playing its playlist — with or without a line.',
    'start_url' => '/player',
    'scope' => '/player',
    'display' => 'fullscreen',
    'orientation' => 'any',
    'background_color' => '#000000',
    'theme_color' => '#000000',
    'icons' => [
        ['src' => '/player-icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/player-icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
])->header('Content-Type', 'application/manifest+json'))->name('player.manifest');

// -----------------------------------------------------------------------
// Invitations  (the link in the email — open to guests on purpose: the
// person may not have an account yet. The token is the only key; the
// database holds just its hash. See InvitationResponseController.)
// -----------------------------------------------------------------------
Route::prefix('invitations/{token}')
    ->where(['token' => '[A-Za-z0-9]{64}'])
    ->middleware('throttle:invitation-response')
    ->group(function () {
        Route::get('/', [InvitationResponseController::class, 'show'])->name('invitations.show');
        Route::post('/accept', [InvitationResponseController::class, 'accept'])->middleware('auth')->name('invitations.accept');
        Route::post('/register', [InvitationResponseController::class, 'register'])->middleware('guest')->name('invitations.register');
        Route::post('/decline', [InvitationResponseController::class, 'decline'])->name('invitations.decline');
    });

// -----------------------------------------------------------------------
// Dashboard
// -----------------------------------------------------------------------
Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('auth')->name('dashboard');
// Store selection page (auth only — it lists the person's OWN memberships, not the
// stores module, so it is not gated by store-view).
Route::get('/select-store', [DashboardController::class, 'selectStore'])->middleware('auth')->name('stores.select');

// -----------------------------------------------------------------------
// Profile  (all authenticated users)
// -----------------------------------------------------------------------
Route::middleware('auth')->group(function () {
    // Show Profile Edit Form
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    // Update Profile Information
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    // Delete User Account
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    // Leave one of your stores — any member, whatever their role (rule 10)
    Route::delete('/profile/stores/{store}', [ProfileController::class, 'leaveStore'])->whereNumber('store')->name('profile.stores.leave');
});

// -----------------------------------------------------------------------
// Admin Routes  (auth required on every group below; throttled per user)
// -----------------------------------------------------------------------
Route::middleware(['auth', 'throttle:admin'])->group(function () {

    // -------------------------------------------------------------------
    // Members  (the current store's team — docs/STORE-ORGANIZATION-SPEC.md)
    // Whether an action is allowed ON a particular member (hierarchy, the
    // last Owner) is decided by StoreTeam; the gates here say only that the
    // person may do this kind of thing in this store at all.
    // -------------------------------------------------------------------
    Route::prefix('members')->group(function () {
        Route::get('/', [MemberController::class, 'index'])->middleware('can:member-view')->name('members.view');
        Route::get('/data', [MemberController::class, 'data'])->middleware('can:member-view')->name('members.data');
        // Anyone may leave a store they are in — except its last Owner.
        Route::post('/leave', [MemberController::class, 'leave'])->name('members.leave');
        Route::post('/invitations', [InvitationController::class, 'store'])->middleware(['can:member-invite', 'throttle:invitations'])->name('members.invitations.store');
        Route::post('/invitations/{invitation}/resend', [InvitationController::class, 'resend'])->whereNumber('invitation')->middleware(['can:member-invite', 'throttle:invitations'])->name('members.invitations.resend');
        Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy'])->whereNumber('invitation')->middleware('can:member-invite')->name('members.invitations.destroy');
        Route::put('/{user}', [MemberController::class, 'update'])->whereNumber('user')->middleware('can:member-update')->name('members.update');
        Route::delete('/{user}', [MemberController::class, 'destroy'])->whereNumber('user')->middleware('can:member-remove')->name('members.destroy');
    });

    // -------------------------------------------------------------------
    // Settings → Stores  (owner's rules, 2026-09-17): the store the person
    // works in, and the stores they belong to. Plain forms, like Profile.
    // A role does not matter, its permissions do: the tab opens with
    // store-view, and every action asks its own permission.
    // -------------------------------------------------------------------
    Route::prefix('settings/store')->group(function () {
        Route::get('/', [StoreSettingsController::class, 'edit'])->middleware('can:store-view')->name('store-settings.edit');
        Route::put('/', [StoreSettingsController::class, 'update'])->middleware('can:store-update')->name('store-settings.update');

        Route::post('/open', [StoreSettingsController::class, 'openStore'])->middleware('can:store-store')->name('store-settings.open');
        Route::delete('/', [StoreSettingsController::class, 'destroy'])->middleware('can:store-destroy')->name('store-settings.destroy');
    });

    // -------------------------------------------------------------------
    // Users  (every account, from the platform's side — owner's rule,
    // 2026-09-17: a store's people are its Members page)
    // -------------------------------------------------------------------
    // Nobody is created or edited here — people join by invitation and manage
    // their own details. The whole page sits behind the `global-tier` lock; the
    // platform team itself is super-admin business.
    Route::prefix('users')->middleware('can:global-tier')->group(function () {
        Route::get('/', [UserController::class, 'index'])->middleware('can:user-view')->name('users.view');
        Route::get('/data', [UserController::class, 'data'])->middleware('can:user-view')->name('users.data');
        Route::delete('/{user}', [UserController::class, 'destroy'])->whereNumber('user')->middleware('can:user-destroy')->name('users.destroy');

        Route::delete('/{user}/platform-role', [UserController::class, 'removePlatformRole'])->whereNumber('user')->middleware('can:super-admin-tier')->name('users.platform-role.destroy');
        // Log in as this person — Super Admin only, checked in the controller.
        Route::post('/{user}/impersonate', [ImpersonateController::class, 'start'])->whereNumber('user')->name('users.impersonate');
        // Manage stores: put a person in a store with a role, change it, take it away — super admins
        Route::middleware('can:super-admin-tier')->group(function () {
            Route::get('/{user}/stores', [UserController::class, 'storeAccess'])->whereNumber('user')->name('users.stores');
            Route::post('/{user}/stores', [UserController::class, 'assignToStore'])->whereNumber('user')->name('users.stores.store');
            Route::put('/{user}/stores/{store}/role', [UserController::class, 'changeStoreRole'])->whereNumber(['user', 'store'])->name('users.store-role.update');
            Route::delete('/{user}/stores/{store}', [UserController::class, 'removeFromStore'])->whereNumber(['user', 'store'])->name('users.stores.destroy');
        });

        // Invitations to the platform team
        Route::prefix('invitations')->middleware('can:super-admin-tier')->group(function () {
            Route::get('/', [PlatformInvitationController::class, 'index'])->name('users.invitations.index');
            Route::post('/', [PlatformInvitationController::class, 'store'])->middleware('throttle:invitations')->name('users.invitations.store');
            Route::post('/{invitation}/resend', [PlatformInvitationController::class, 'resend'])->whereNumber('invitation')->middleware('throttle:invitations')->name('users.invitations.resend');
            Route::delete('/{invitation}', [PlatformInvitationController::class, 'destroy'])->whereNumber('invitation')->name('users.invitations.destroy');
        });
    });

    // -------------------------------------------------------------------
    // Stores
    // -------------------------------------------------------------------
    // Switching between the stores a member belongs to — any member, no permission.
    Route::post('/stores/switch', [StoreController::class, 'switch'])->name('store.switch');

    // Every store, from the platform's side. A store's own people change the
    // store they work in from Settings → Stores instead (owner's rule,
    // 2026-09-17), so the page and its writes stay above the stores.
    Route::prefix('stores')->middleware('can:global-tier')->group(function () {
        Route::get('/', [StoreController::class, 'index'])->middleware('can:store-view')->name('stores.view');
        Route::get('/data', [StoreController::class, 'data'])->middleware('can:store-view')->name('stores.data');
        // A new store for a customer, with an Owner invitation
        Route::post('/', [StoreController::class, 'store'])->middleware(['can:store-store', 'throttle:invitations'])->name('stores.store');
        Route::put('/{store}', [StoreController::class, 'update'])->whereNumber('store')->middleware('can:store-update')->name('stores.update');
        Route::delete('/{store}', [StoreController::class, 'destroy'])->whereNumber('store')->middleware('can:store-destroy')->name('stores.destroy');
        // An Owner for a store that has none: a member of it is made Owner at once, anybody else is invited
        Route::post('/{store}/owner-invitation', [StoreController::class, 'inviteOwner'])->whereNumber('store')->middleware(['can:store-store', 'throttle:invitations'])->name('stores.owner-invitation');
    });

    // -------------------------------------------------------------------
    // Screens  (the TVs themselves)
    // -------------------------------------------------------------------
    Route::prefix('screens')->group(function () {
        // Show Screens Page
        Route::get('/', [ScreenController::class, 'index'])->middleware('can:screen-view')->name('screens.view');
        // Get Paginated Screens Data (AJAX)
        Route::get('/data', [ScreenController::class, 'data'])->middleware('can:screen-view')->name('screens.data');
        // Playlist builder for one screen
        Route::get('/{screen}', [ScreenController::class, 'show'])->whereNumber('screen')->middleware('can:screen-view')->name('screens.show');
        Route::get('/{screen}/playlist', [PlaylistController::class, 'index'])->middleware('can:screen-view')->name('screens.playlist.view');
        // Replace the whole playlist in one call (reorder, retime, add, remove, and
        // each item's schedule rules, which travel with the save)
        Route::put('/{screen}/playlist', [PlaylistController::class, 'update'])->middleware('can:screen-playlist')->name('screens.playlist.update');
        // "When would this rule actually play?" — resolved server-side through the
        // same code the device is answered with, so the preview cannot drift
        Route::post('/{screen}/playlist/preview', [PlaylistController::class, 'preview'])->middleware('can:screen-playlist')->name('screens.playlist.preview');
        // Put this whole playlist (schedules included) onto other screens
        Route::get('/{screen}/playlist/copy-targets', [PlaylistController::class, 'copyTargets'])->middleware('can:screen-playlist')->name('screens.playlist.copy-targets');
        Route::post('/{screen}/playlist/copy', [PlaylistController::class, 'copy'])->middleware('can:screen-playlist')->name('screens.playlist.copy');
        // The picker's options - gated by the playlist permission alone, so it is
        // self-sufficient and does not drag in media-view
        Route::get('/{screen}/available-media', [PlaylistController::class, 'availableMedia'])->middleware('can:screen-playlist')->name('screens.available-media');
        // Every channel this screen can carry — the platform's and its own store's, never
        // another store's. Gated by the playlist permission alone for the same reason as
        // the media picker above
        Route::get('/{screen}/available-channels', [PlaylistController::class, 'availableChannels'])->middleware('can:screen-playlist')->name('screens.available-channels');
        // The default-media picker's options, gated by the screen permission that
        // needs them for the same reason
        Route::get('/{screen}/media-options', [ScreenController::class, 'mediaOptions'])->middleware('can:screen-update')->name('screens.media-options');
        // Pair A Waiting TV (new screen, or re-pair an existing one)
        Route::post('/pair', [ScreenController::class, 'pair'])->middleware('can:screen-store')->name('screens.pair');
        // Rename / Re-orient
        Route::put('/{screen}', [ScreenController::class, 'update'])->middleware('can:screen-update')->name('screens.update');
        // Delete A Screen
        Route::delete('/{screen}', [ScreenController::class, 'destroy'])->middleware('can:screen-destroy')->name('screens.destroy');
    });

    // -------------------------------------------------------------------
    // Media library  (the content that ends up on a screen)
    // -------------------------------------------------------------------
    Route::prefix('media')->group(function () {
        // Show Media Library Page
        Route::get('/', [MediaController::class, 'index'])->middleware('can:media-view')->name('media.view');
        // Get Paginated Media Data (AJAX)
        Route::get('/data', [MediaController::class, 'data'])->middleware('can:media-view')->name('media.data');
        // Upload A New File (multipart)
        Route::post('/', [MediaController::class, 'store'])->middleware('can:media-store')->name('media.store');
        // Update Title / Description / Schedule
        Route::put('/{media}', [MediaController::class, 'update'])->middleware('can:media-update')->name('media.update');
        // Delete A File
        Route::delete('/{media}', [MediaController::class, 'destroy'])->middleware('can:media-destroy')->name('media.destroy');
    });

    // -------------------------------------------------------------------
    // Network advertising  (the PLATFORM's own content, sold to a brand)
    //
    // `campaign-manage` is a hand-written gate, not a grantable permission row:
    // a campaign has no store, so a store user holding it would see every
    // brand's contract across the whole network. See AppServiceProvider.
    // -------------------------------------------------------------------
    Route::prefix('campaigns')->middleware('can:campaign-manage')->group(function () {
        Route::get('/', [CampaignController::class, 'index'])->name('campaigns.view');
        Route::get('/data', [CampaignController::class, 'data'])->name('campaigns.data');
        // Every screen a campaign could be pointed at, grouped by shop
        Route::get('/screens', [CampaignController::class, 'screens'])->name('campaigns.screens');
        Route::post('/', [CampaignController::class, 'store'])->name('campaigns.store');
        // POST, not PUT: an edit may carry a replacement file, and PHP does not
        // parse multipart bodies on PUT. Method spoofing is no help either — it
        // would turn the request INTO a PUT and miss this route.
        Route::post('/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
        Route::delete('/{campaign}', [CampaignController::class, 'destroy'])->name('campaigns.destroy');
    });

    // Whether a shop and its screens carry advertising — the platform owner's deal, not the shopkeeper's:
    // one shop at a time from inside it ("Log in as"), or whole shops from the Stores listing below.
    Route::prefix('network-ads')->group(function () {
        // Inside one shop, reached by "Log in as" — the only way a super admin gets in.
        Route::middleware('can:network-ads-toggle')->group(function () {
            Route::put('/store', [NetworkAdsController::class, 'store'])->name('network-ads.store');
            Route::put('/screens', [NetworkAdsController::class, 'screens'])->name('network-ads.screens');
        });

        // Whole shops at once, from the stores listing. That page belongs to the
        // platform owner and is reached WITHOUT impersonating anybody, so it answers
        // to the plain super-admin ability instead.
        Route::put('/stores', [NetworkAdsController::class, 'stores'])
            ->middleware('can:campaign-manage')->name('network-ads.stores');
    });

    // -------------------------------------------------------------------
    // Channels  (ads a shop may add to its screens — a wholesaler's
    // promotions, a season, a notice)
    //
    // Above the stores: every channel, and one made there is offered to
    // every shop. Inside a store, for a role carrying the channel
    // permissions: the store's own channels, for its own screens alone —
    // and the platform's, listed and opened to READ only. Every change
    // finds a channel only within reach (Channel::visibleTo), every look
    // within Channel::listableIn — anything else is 404.
    // -------------------------------------------------------------------
    Route::prefix('channels')->group(function () {
        Route::get('/', [ChannelController::class, 'index'])->middleware('can:channel-view')->name('channels.view');
        Route::get('/data', [ChannelController::class, 'data'])->middleware('can:channel-view')->name('channels.data');
        Route::post('/', [ChannelController::class, 'store'])->middleware('can:channel-store')->name('channels.store');
        Route::get('/{channel}', [ChannelController::class, 'show'])->whereNumber('channel')->middleware('can:channel-view')->name('channels.show');
        Route::put('/{channel}', [ChannelController::class, 'update'])->whereNumber('channel')->middleware('can:channel-update')->name('channels.update');
        Route::delete('/{channel}', [ChannelController::class, 'destroy'])->whereNumber('channel')->middleware('can:channel-destroy')->name('channels.destroy');

        // The ads inside one channel. Adding, editing, reordering and removing an ad
        // are all edits of the channel, so all of them answer to channel-update
        Route::get('/{channel}/ads', [ChannelAdController::class, 'index'])->whereNumber('channel')->middleware('can:channel-view')->name('channels.ads.index');
        // The library rows an ad may be chosen from (the Add-ad pickers): the channel's own library, and the
        // Ad Builder's published ads in it (docs/CHANNEL-CONTENT-SPEC.md). Adding an ad edits the channel
        Route::get('/{channel}/library', [ChannelAdController::class, 'library'])->whereNumber('channel')->middleware('can:channel-update')->name('channels.library');
        Route::post('/{channel}/ads', [ChannelAdController::class, 'store'])->whereNumber('channel')->middleware('can:channel-update')->name('channels.ads.store');
        Route::put('/{channel}/ads/order', [ChannelAdController::class, 'reorder'])->whereNumber('channel')->middleware('can:channel-update')->name('channels.ads.order');
        // POST, not PUT: an edit may carry a replacement file, and PHP does not parse
        // multipart bodies on PUT (the same as campaigns)
        Route::post('/{channel}/ads/{ad}', [ChannelAdController::class, 'update'])->whereNumber(['channel', 'ad'])->scopeBindings()->middleware('can:channel-update')->name('channels.ads.update');
        Route::delete('/{channel}/ads/{ad}', [ChannelAdController::class, 'destroy'])->whereNumber(['channel', 'ad'])->scopeBindings()->middleware('can:channel-update')->name('channels.ads.destroy');
    });

    // -------------------------------------------------------------------
    // Dayparts  (named windows of time, reused by the schedule rules on playlists)
    // -------------------------------------------------------------------
    Route::prefix('dayparts')->group(function () {
        // Show Dayparts Page
        Route::get('/', [DaypartController::class, 'index'])->middleware('can:daypart-view')->name('dayparts.view');
        // Get Paginated Dayparts Data (AJAX)
        Route::get('/data', [DaypartController::class, 'data'])->middleware('can:daypart-view')->name('dayparts.data');
        // Create A New Daypart (with its exceptions)
        Route::post('/', [DaypartController::class, 'store'])->middleware('can:daypart-store')->name('dayparts.store');
        // Rename / Re-time / Retire
        Route::put('/{daypart}', [DaypartController::class, 'update'])->middleware('can:daypart-update')->name('dayparts.update');
        // Delete A Daypart
        Route::delete('/{daypart}', [DaypartController::class, 'destroy'])->middleware('can:daypart-destroy')->name('dayparts.destroy');
    });

    // -------------------------------------------------------------------
    // Ad Builder  (docs/AD-BUILDER-SPEC.md)
    //
    // Three tabs around one editor: Create draws a 1920×1080 advert, Ads
    // lists what has been saved, Assets holds the pictures and videos the
    // designs are made of — the Builder's own shelf, not the store's media
    // library. Publishing an ad writes an HTML file and a `media` row of
    // type `html`, which is how it reaches a playlist and a television.
    //
    // A store's people work on their own store's ads; the platform team
    // works above them all (BuilderAd::visibleTo) — anything out of reach
    // is a 404, checked in the controller as well as here.
    // -------------------------------------------------------------------
    Route::prefix('builder')->group(function () {
        // The Ads tab (the section's home) and its data
        Route::get('/', [BuilderController::class, 'index'])->middleware('can:ad-view')->name('builder.index');
        Route::get('/data', [BuilderController::class, 'data'])->middleware('can:ad-view')->name('builder.data');

        // The Assets tab. Declared before /{ad} so the word is never read as an id
        Route::get('/assets', [BuilderAssetController::class, 'index'])->middleware('can:ad-view')->name('builder.assets');
        Route::get('/assets/data', [BuilderAssetController::class, 'data'])->middleware('can:ad-view')->name('builder.assets.data');
        Route::post('/assets', [BuilderAssetController::class, 'store'])->middleware('can:ad-store')->name('builder.assets.store');
        Route::delete('/assets/{asset}', [BuilderAssetController::class, 'destroy'])->whereNumber('asset')->middleware('can:ad-destroy')->name('builder.assets.destroy');

        // The fonts the editor may set text in. Installing one fetches it from Google ONCE and
        // keeps it here, so a television with no internet still shows the right typeface.
        // The editor opens with Create Ads or Update Ads, and neither needs View Ads, so these
        // two ask for "any of" — which a `can:` here cannot say — in BuilderFontController:
        // the list for ad-view, ad-store or ad-update, installing for ad-store or ad-update
        Route::get('/fonts', [BuilderFontController::class, 'index'])->name('builder.fonts');
        Route::post('/fonts', [BuilderFontController::class, 'store'])->middleware('throttle:font-install')->name('builder.fonts.store');

        // The Create tab: the editor with an empty stage
        Route::get('/create', [BuilderController::class, 'create'])->middleware('can:ad-store')->name('builder.create');

        Route::post('/', [BuilderController::class, 'store'])->middleware('can:ad-store')->name('builder.store');
        Route::get('/{ad}', [BuilderController::class, 'edit'])->whereNumber('ad')->middleware('can:ad-update')->name('builder.edit');
        Route::put('/{ad}', [BuilderController::class, 'update'])->whereNumber('ad')->middleware('can:ad-update')->name('builder.update');
        Route::post('/{ad}/duplicate', [BuilderController::class, 'duplicate'])->whereNumber('ad')->middleware('can:ad-store')->name('builder.duplicate');
        // Compile the design into a page and put it in the library, where a playlist can reach it
        Route::post('/{ad}/publish', [BuilderController::class, 'publish'])->whereNumber('ad')->middleware('can:ad-update')->name('builder.publish');
        // The rest of the draft/publish model (docs/AD-BUILDER-SPEC.md §9): take a published ad off the screens,
        // or throw away the changes the screens do not show yet — both changes to a saved ad, like Publish.
        Route::post('/{ad}/unpublish', [BuilderController::class, 'unpublish'])->whereNumber('ad')->middleware('can:ad-update')->name('builder.unpublish');
        Route::post('/{ad}/discard', [BuilderController::class, 'discard'])->whereNumber('ad')->middleware('can:ad-update')->name('builder.discard');
        // May a shop's own playlist play this ad, or is it for channels only (owner's rule, 2026-09-22)?
        Route::post('/{ad}/in-playlists', [BuilderController::class, 'showInPlaylists'])->whereNumber('ad')->middleware('can:ad-update')->name('builder.in-playlists');
        // The saved design as a television would show it, full screen, before it reaches one (§10a).
        // For ad-view or ad-update — "any of", so checked in BuilderController::preview
        Route::get('/{ad}/preview', [BuilderController::class, 'preview'])->whereNumber('ad')->name('builder.preview');
        // A big delete: the published copy goes with the design, so it asks for the password
        Route::delete('/{ad}', [BuilderController::class, 'destroy'])->whereNumber('ad')->middleware('can:ad-destroy')->name('builder.destroy');
    });

    // -------------------------------------------------------------------
    // Roles
    // -------------------------------------------------------------------
    Route::prefix('roles')->group(function () {
        // Show Roles List Page
        Route::get('/', [RoleController::class, 'index'])->middleware('can:role-view')->name('roles.view');
        // Get Every Role In Reach, With What May Be Done To Each (AJAX)
        Route::get('/data', [RoleController::class, 'data'])->middleware('can:role-view')->name('roles.data');
        // Create New Role
        Route::post('/', [RoleController::class, 'store'])->middleware('can:role-store')->name('roles.store');
        // Update Existing Role
        Route::put('/{role}', [RoleController::class, 'update'])->whereNumber('role')->middleware('can:role-update')->name('roles.update');
        // Delete Role
        Route::delete('/{role}', [RoleController::class, 'destroy'])->whereNumber('role')->middleware('can:role-destroy')->name('roles.destroy');
        // Get The Permissions The Role In The Form Can Hold
        Route::get('/assignable', [RoleController::class, 'assignable'])->middleware('can:role-view')->name('roles.assignable');
    });

    // -------------------------------------------------------------------
    // Activity Log  (read-only audit trail)
    // -------------------------------------------------------------------
    // Above the stores every store's history; inside a store, for a role carrying
    // activity-view, that store's own entries (ActivityLogController). Yearly
    // maintenance drops a whole year for every store at once, so it — and the
    // storage panel's row counts — keep the `global-tier` lock: a permission of its
    // own (activity-destroy) that a super admin may delegate to a platform role.
    Route::prefix('activity')->group(function () {
        Route::get('/', [ActivityLogController::class, 'index'])->middleware('can:activity-view')->name('activity.view');
        Route::get('/data', [ActivityLogController::class, 'data'])->middleware('can:activity-view')->name('activity.data');

        Route::middleware('can:global-tier')->group(function () {
            // Yearly partition lifecycle (status for the storage panel; maintenance deletes)
            Route::get('/partitions', [ActivityLogController::class, 'partitions'])->middleware('can:activity-view')->name('activity.partitions');
            Route::post('/partitions/maintain', [ActivityLogController::class, 'maintainPartitions'])->middleware('can:activity-destroy')->name('activity.partitions.maintain');
        });
    });

    // -------------------------------------------------------------------
    // Permissions  (/permissions/*)
    // -------------------------------------------------------------------
    // Super admins only (owner's rule): every route in this file names its permission
    // in code, so the catalogue is never delegated — not even to a global role. The
    // `super-admin-tier` lock holds even if a permission-* row reached another role.
    Route::prefix('permissions')->middleware('can:super-admin-tier')->group(function () {
        // Show Permissions List Page
        Route::get('/', [PermissionController::class, 'index'])->middleware('can:permission-view')->name('permissions.view');
        // Get Paginated Permissions Data (AJAX)
        Route::get('/data', [PermissionController::class, 'data'])->middleware('can:permission-view')->name('permissions.data');
        // Create New Permission
        Route::post('/', [PermissionController::class, 'store'])->middleware('can:permission-store')->name('permissions.store');
        // Update Existing Permission
        Route::put('/{permission}', [PermissionController::class, 'update'])->middleware('can:permission-update')->name('permissions.update');
        // Delete Permission
        Route::delete('/{permission}', [PermissionController::class, 'destroy'])->middleware('can:permission-destroy')->name('permissions.destroy');
    });

    // -------------------------------------------------------------------
    // Impersonation — "stop" must stay reachable by the impersonated user,
    // who is not a Super Admin, so it cannot sit behind a permission gate.
    // -------------------------------------------------------------------
    Route::post('/impersonate/stop', [ImpersonateController::class, 'stop'])->name('impersonate.stop');

});

require __DIR__.'/auth.php';
