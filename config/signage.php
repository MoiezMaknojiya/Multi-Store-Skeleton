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

];
