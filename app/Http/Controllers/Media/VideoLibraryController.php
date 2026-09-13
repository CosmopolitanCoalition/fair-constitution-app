<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Support\MediaMeta;
use App\Support\SurfaceMeta;
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
 * Registered surface metadata connects the library to its Learn guidance.
 */
class VideoLibraryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Learn/VideoLibrary', [
            'surface' => SurfaceMeta::for('learn/video-library'),
            'videos'  => MediaMeta::all(),
            'baseUrl' => MediaMeta::baseUrl(),
        ]);
    }
}
