<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            // Nothing is served from this disk over HTTP (no temporaryUrl, no Storage::disk('local') download),
            // so the framework's /storage/{path} route is not registered.
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        /*
         * Uploaded media, served over HTTP.
         *
         * Dusk gets a root and a URL prefix OF ITS OWN. Browser tests run on the
         * same machine as the real application and upload real files with ULID
         * names, so before this they landed in the very same media/{store} folder
         * as the owner's own uploads and were indistinguishable from them — which
         * is how test litter once ended up beside real videos, and how a cleanup
         * aimed at that litter once destroyed three real ones.
         *
         * Both halves have to move together: the ROOT so writes are separated,
         * and the URL so the player can still fetch what a test uploaded (the
         * public/dusk-storage symlink in 'links' below points at it).
         */
        'public' => [
            'driver' => 'local',
            'root' => storage_path(env('APP_ENV') === 'dusk' ? 'app/dusk-public' : 'app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').(env('APP_ENV') === 'dusk' ? '/dusk-storage' : '/storage'),
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
        // Only ever used while APP_ENV=dusk; harmless the rest of the time, and
        // created by the same `php artisan storage:link` as the real one.
        public_path('dusk-storage') => storage_path('app/dusk-public'),
    ],

];
