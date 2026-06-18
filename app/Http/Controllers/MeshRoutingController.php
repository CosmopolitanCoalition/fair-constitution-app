<?php

namespace App\Http\Controllers;

use App\Services\Federation\NearestNodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Public nearest-node routing (Phase G, G8b / C5). GET /api/mesh/nearest — a browser,
 * CDN edge, or a travelling client asks which mesh node to talk to. PUBLIC + stateless
 * (mounted OUTSIDE the web group: no session, no CSRF, no cookie) and rate-limited.
 *
 * Privacy rails: a caller supplies EITHER a jurisdiction id they pick OR an opt-in
 * coordinate (rounded server-side, NEVER persisted). With neither, it refuses rather
 * than guessing a location (no server-side GeoIP — the strictest posture). The answer
 * is `no-store` so a location-derived route is never cached, and it exposes only the
 * already-public directory routing hints — never authority, never any private data.
 */
class MeshRoutingController extends Controller
{
    public function __construct(private readonly NearestNodeService $nearest) {}

    public function nearest(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', '5');
        $jurisdiction = (string) $request->query('jurisdiction', '');
        $lat = $request->query('lat');
        $lng = $request->query('lng');

        if ($jurisdiction !== '' && Str::isUuid($jurisdiction)) {
            $nodes = $this->nearest->nearestToJurisdiction($jurisdiction, $limit);
        } elseif (is_numeric($lat) && is_numeric($lng)) {
            $latF = (float) $lat;
            $lngF = (float) $lng;
            if ($latF < -90 || $latF > 90 || $lngF < -180 || $lngF > 180) {
                return response()->json(['error' => 'coordinates out of range'], 422);
            }
            $nodes = $this->nearest->nearestToPoint($latF, $lngF, $limit);
        } else {
            return response()->json(
                ['error' => 'provide a jurisdiction id, or an opt-in lat & lng'],
                422,
            );
        }

        // Never cache a routing answer tied to a location.
        return response()->json(['nodes' => $nodes])->header('Cache-Control', 'no-store');
    }
}
