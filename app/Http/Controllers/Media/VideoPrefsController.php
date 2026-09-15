<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\UserMediaPref;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * W-0432 — the signed-in viewer's video player preferences.
 *
 * GET  /api/me/video-prefs  returns the viewer's document (an empty object
 *                           when none is stored yet).
 * PUT  /api/me/video-prefs  merges the sent keys into the viewer's one
 *                           document and returns it.
 *
 * The route sits behind the `auth` middleware; the abort_unless here is defence
 * in depth so the controller is safe to call directly. Only the six known keys
 * are accepted; any other key is rejected. One row per user (the user id is the
 * primary key). Demo-mode sessions write here like any other viewer; the store
 * is not audit-chained.
 */
class VideoPrefsController extends Controller
{
    /** The only keys the player persists. Anything else is rejected. */
    private const ALLOWED = ['audio', 'cap', 'linked', 'captionsOn', 'volume', 'muted'];

    /**
     * The two page props the player seeds from: the viewer's stored document
     * (null for a guest, or a signed-in viewer with none yet) and the PUT
     * endpoint (null for a guest, so the player never writes to the server).
     * Table-guarded for the fresh-install and fixture walks.
     *
     * @return array{videoPrefs: array<string, mixed>|null, prefsEndpoint: string|null}
     */
    public static function pageProps(Request $request): array
    {
        $user = $request->user();

        if ($user === null || ! Schema::hasTable('user_media_prefs')) {
            return ['videoPrefs' => null, 'prefsEndpoint' => null];
        }

        return [
            'videoPrefs' => UserMediaPref::documentFor((string) $user->id),
            'prefsEndpoint' => route('api.me.video-prefs.update'),
        ];
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        return response()->json(UserMediaPref::documentFor((string) $user->id) ?? []);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        // Reject any key outside the known set before validating the rest.
        $unknown = array_diff(array_keys($request->all()), self::ALLOWED);
        abort_if($unknown !== [], 422, __('Unknown preference keys: :keys', ['keys' => implode(', ', $unknown)]));

        $validated = $request->validate([
            'audio' => ['sometimes', 'nullable', 'string', 'max:35'],
            'cap' => ['sometimes', 'nullable', 'string', 'max:35'],
            'linked' => ['sometimes', 'boolean'],
            'captionsOn' => ['sometimes', 'boolean'],
            'volume' => ['sometimes', 'numeric', 'between:0,1'],
            'muted' => ['sometimes', 'boolean'],
        ]);

        $pref = UserMediaPref::firstOrNew(['user_id' => (string) $user->id]);
        $pref->prefs = array_merge($pref->prefs ?? [], $validated);
        $pref->save();

        return response()->json($pref->prefs);
    }
}
