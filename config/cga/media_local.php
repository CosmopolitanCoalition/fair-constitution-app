<?php

/*
|--------------------------------------------------------------------------
| CGA local media library (W-0448)
|--------------------------------------------------------------------------
|
| config/cga/media.php is GENERATED (the catalog registry). These are the
| HAND-KEPT operator settings for the self-hosted library, split into their
| own file so a media-registry regeneration never touches them. Laravel loads
| config/cga/<file>.php as cga.<file>, so these read back as
| config('cga.media_local.local_root') and config('cga.media_local.website_base_url').
|
|   - local_root         override the library root (default public_path('media/Subjects'));
|                        set CGA_MEDIA_LOCAL_ROOT to serve E:\Subjects with no copy.
|   - website_base_url   the Cloudflare origin the website-download pull reads from.
*/

return [

    'local_root' => env('CGA_MEDIA_LOCAL_ROOT'),

    'website_base_url' => env(
        'CGA_MEDIA_WEBSITE_URL',
        'https://cosmopolitancoalition.org/wp-content/uploads'
    ),

];
