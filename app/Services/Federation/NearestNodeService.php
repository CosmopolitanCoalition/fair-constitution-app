<?php

namespace App\Services\Federation;

use Illuminate\Support\Facades\DB;

/**
 * Nearest-node routing (Phase G, G8b / C5) — answers "which mesh node should this
 * client talk to?" from the PUBLIC G9 directory, by distance to a point.
 *
 * PRIVACY IS THE DESIGN, not a footnote:
 *   • it reads only directory_entries (advisory, already-public, signed-by-origin
 *     routing hints) joined to jurisdictions.geom — it confers NO authority and
 *     touches nothing private (no location_pings, no residency, no user data);
 *   • a supplied coordinate is ROUNDED to a coarse grid and NEVER persisted — there
 *     is no table this writes to and no log of the point;
 *   • results are capped (anti-enumeration) and a secure posture floats the hardened
 *     (private) transports first.
 *
 * It is a HINT: the node it names re-checks authority on any write (AuthorityResolver),
 * so a stale/hostile entry can at worst mis-route to a node that rejects the request.
 */
class NearestNodeService
{
    /** round() decimal places for the opt-in coordinate grid (1 ⇒ ~0.1° ≈ 11 km). */
    private const GRID_PRECISION = 1;

    private const SECURE_TRANSPORTS = ['onion', 'yggdrasil'];

    /**
     * Nearest serving nodes to an OPT-IN user coordinate. The coordinate is ROUNDED to
     * the coarse grid (privacy defense in depth) and NEVER stored.
     *
     * @return list<array{server_id:string,jurisdiction_id:string,transport:string,url:string,distance_km:float}>
     */
    public function nearestToPoint(float $lat, float $lng, int $limit = 5): array
    {
        // Rounding belongs ONLY here — to the user-supplied coordinate (there is a caller
        // location to protect). The jurisdiction-pick path below is a public centroid, so
        // it must NOT be snapped (that would lose accuracy for no privacy gain).
        return $this->queryNearest(round($lat, self::GRID_PRECISION), round($lng, self::GRID_PRECISION), $limit);
    }

    /**
     * Nearest serving nodes to a named jurisdiction's representative interior point (the
     * no-coordinate path — a visitor picks their jurisdiction; nothing about them is
     * stored, so no rounding). Uses ST_PointOnSurface, which is GUARANTEED to lie within
     * the footprint — unlike ST_Centroid, whose area-weighted center can fall outside a
     * concave / multipolygon jurisdiction (a country with offshore islands, an
     * antimeridian span) and pick the wrong nearest node. Matches the codebase convention
     * (JurisdictionController / ResidencyService).
     *
     * @return list<array{server_id:string,jurisdiction_id:string,transport:string,url:string,distance_km:float}>
     */
    public function nearestToJurisdiction(string $jurisdictionId, int $limit = 5): array
    {
        $point = DB::selectOne(
            'SELECT ST_Y(ST_PointOnSurface(geom)) AS lat, ST_X(ST_PointOnSurface(geom)) AS lng '
            .'FROM jurisdictions WHERE id = ? AND geom IS NOT NULL AND deleted_at IS NULL',
            [$jurisdictionId],
        );

        if ($point === null) {
            return [];
        }

        return $this->queryNearest((float) $point->lat, (float) $point->lng, $limit);
    }

    /**
     * The shared nearest query (NO rounding — callers round when there is a coordinate to
     * protect). Reads ONLY the public, advisory directory entries joined to
     * jurisdictions.geom; persists nothing. Enumeration of served nodes via repeated calls
     * is an ACCEPTED property — directory entries are public-by-design routing hints
     * (signed-by-origin, meant to be relayed) and confer no authority.
     *
     * @return list<array{server_id:string,jurisdiction_id:string,transport:string,url:string,distance_km:float}>
     */
    private function queryNearest(float $lat, float $lng, int $limit): array
    {
        $limit = max(1, min(10, $limit));

        // Over-fetch a few directory entries (each may carry several endpoints), then
        // flatten + cap. Only non-expired entries for jurisdictions with a footprint.
        // ST_MakePoint takes (x=lng, y=lat); SRID 4326; ::geography → metres.
        $rows = DB::select(
            'SELECT de.server_id, de.jurisdiction_id, de.endpoints, '
            .'ST_Distance(j.geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS dist '
            .'FROM directory_entries de '
            .'JOIN jurisdictions j ON j.id = de.jurisdiction_id '
            .'WHERE de.deleted_at IS NULL AND j.deleted_at IS NULL AND j.geom IS NOT NULL '
            .'AND (de.expires_at IS NULL OR de.expires_at > now()) '
            .'ORDER BY dist ASC LIMIT ?',
            [$lng, $lat, $limit * 4],
        );

        return $this->flatten($rows, $limit);
    }

    /**
     * @param  array<int,object>  $rows
     * @return list<array{server_id:string,jurisdiction_id:string,transport:string,url:string,distance_km:float}>
     */
    private function flatten(array $rows, int $limit): array
    {
        $out = [];

        foreach ($rows as $r) {
            $endpoints = json_decode((string) ($r->endpoints ?? '[]'), true) ?: [];
            foreach ($endpoints as $ep) {
                $transport = (string) ($ep['transport'] ?? '');
                $url = (string) ($ep['url'] ?? '');
                if ($transport === '' || $url === '') {
                    continue;
                }
                $out[] = [
                    'server_id' => (string) $r->server_id,
                    'jurisdiction_id' => (string) $r->jurisdiction_id,
                    'transport' => $transport,
                    'url' => $url,
                    'distance_km' => round(((float) $r->dist) / 1000, 1),
                ];
            }
        }

        // Secure posture: a hardened transport is the first hop, so a blocked/surveilled
        // clearnet endpoint is never the visible front (stable within distance order).
        if ((bool) config('cga.federation_secure_transport_first', false)) {
            usort($out, fn (array $a, array $b) => $this->floorRank($a) <=> $this->floorRank($b));
        }

        return array_slice($out, 0, $limit);
    }

    private function floorRank(array $endpoint): int
    {
        return in_array($endpoint['transport'], self::SECURE_TRANSPORTS, true) ? 0 : 1;
    }
}
