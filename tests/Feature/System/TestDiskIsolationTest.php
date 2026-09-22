<?php

/*
|--------------------------------------------------------------------------
| The tests' public disk is never the real one
|--------------------------------------------------------------------------
|
| A test that forgot Storage::fake('public') once deleted the owner's own published ads: its fresh
| database numbered its store and ads from 1, exactly like the real one, so builder/1/ads/1 was the
| same folder on both. config/filesystems.php now gives the tests a throwaway root, as Dusk has its own.
|
*/

test('a test that forgets to fake the disk still writes nowhere near the real uploads', function () {
    $root = str_replace('\\', '/', (string) config('filesystems.disks.public.root'));

    expect($root)->not->toBe(str_replace('\\', '/', storage_path('app/public')))
        ->and($root)->toEndWith('storage/framework/testing/public');
});
