<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Support\MediaMeta;
use Inertia\Inertia;
use Inertia\Response;

/**
 * VideoLibraryController — the Learn · video library surface (the app port of
 * the operator's Coalition multi-track player; design contract
 * mockups/v3/shared/video-player.html).
 *
 * Public, read-only: the library is teaching content, browsable by anyone. The
 * catalog is resolved from MediaMeta (config/cga/media.php); the media host is
 * env-driven and passed to the client so the player renders the labelled poster
 * placeholder until a host is configured, then lights up with real playback.
 *
 * The surface is inline (not a config/cga/surfaces.php entry) — this is a new
 * lane-5 surface and needs no registry row for the scaffold, footer citation,
 * or Learn drawer to work.
 */
class VideoLibraryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Learn/VideoLibrary', [
            'surface' => [
                'id'        => 'learn/video-library',
                'title'     => 'Video library',
                'module'    => 'learn',
                'nav'       => 'video-library',
                'roles'     => [],
                'workflows' => [],
                'forms'     => [],
                'clocks'    => [],
                'citation'  => 'One film, narrated and captioned in many languages · from one silent master',
            ],
            'videos'  => MediaMeta::all(),
            'baseUrl' => MediaMeta::baseUrl(),
        ]);
    }
}
