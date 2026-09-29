<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Network advertising
    |--------------------------------------------------------------------------
    */

    /**
     * How often a television breaks for advertising, in seconds, counted from the
     * moment the player started rather than from the clock — so two shops that
     * booted at different times do not cut to advertising at the same instant.
     *
     * In seconds rather than minutes so it can be turned right down for a browser
     * test: waiting an hour to watch one break is not a test anybody runs. The dusk
     * default follows the same pattern config/filesystems.php already uses for the
     * throwaway disk.
     */
    'ad_break_seconds' => (int) env(
        'SIGNAGE_AD_BREAK_SECONDS',
        env('APP_ENV') === 'dusk' ? 6 : 3600
    ),

    /*
    |--------------------------------------------------------------------------
    | The server's own disk
    |--------------------------------------------------------------------------
    */

    /**
     * How much of the server's disk is always kept free (App\Services\DiskGuard, owner's decision 2026-09-29): an
     * upload that would leave less is refused, whatever a shop's own 512 MB says, and the super admins are told.
     * In megabytes in the environment; 5 GB unless it says. The test suites keep none unless told, like the ad
     * break above, so a suite never fails because the machine running it is fuller than a server may be — the
     * guard's own test sets its reserve itself.
     */
    'upload_reserve_bytes' => (int) env(
        'SIGNAGE_UPLOAD_RESERVE_MB',
        in_array(env('APP_ENV'), ['testing', 'dusk'], true) ? 0 : 5120
    ) * 1024 * 1024,

];
