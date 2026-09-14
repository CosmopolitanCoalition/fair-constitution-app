<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Support\MediaMeta;
use App\Support\SurfaceMeta;
use Illuminate\Http\Request;
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
    public function index(Request $request): Response
    {
        // LE-3: ?v=<id> preselects a film (the Learn flyout deep-links the
        // lesson's assigned video here). An unknown id is ignored — the page
        // opens on the first film, never errors.
        $requested = (string) $request->query('v', '');
        $preselect = in_array($requested, MediaMeta::ids(), true) ? $requested : null;

        return Inertia::render('Learn/VideoLibrary', [
            'surface'   => SurfaceMeta::for('learn/video-library'),
            'videos'    => MediaMeta::all(),
            'baseUrl'   => MediaMeta::baseUrl(),
            'preselect' => $preselect,
            // W-0432 — the signed-in viewer's saved player prefs + the PUT
            // endpoint. Null for a guest (localStorage-only).
            ...VideoPrefsController::pageProps($request),
        ]);
    }
}
