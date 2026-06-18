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
 *   • results are capped (anti-enumeration) and a censored posture floats
 *     censorship-resistant transports first.
 *
 * It is a HINT: the node it names re-checks authority on any write (AuthorityResolver),
 * so a stale/hostile entry can at worst mis-route to a node that rejects the request.
 */
class NearestNodeService
{
    /** Coarse grid for an opt-in coordinate (~0.1° ≈ 11 km) — defense in depth. */
    private const GRID_DEGREES = 1; // round() precision in decimal places

    private const RESISTANT = ['onion', 'yggdrasil'];

    /**
     * Nearest serving nodes to a (rounded) point. The coordinate is never stored.
     *
     * @return list<array{server_id:string,jurisdiction_id:string,transport:string,url:string,distance_km:float}>
     */
    public function nearestToPoint(float $lat, float $lng, int $limit = 5): array
    {
        $lat = round($lat, self::GRID_DEGREES);
        $lng = round($lng, self::GRID_DEGREES);
        $limit = max(1, min(10, $limit));

        // Over-fetch a few directory entries (each may carry several endpoints), then
        // flatten + cap. Only non-expired entries for jurisdictions with a footprint.
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
     * Nearest serving nodes to a named jurisdiction's centroid (the no-coordinate
     * path — a visitor picks their jurisdiction; nothing about them is stored).
     *
     * @return list<array{server_id:string,jurisdiction_id:string,transport:string,url:string,distance_km:float}>
     */
    public function nearestToJurisdiction(string $jurisdictionId, int $limit = 5): array
    {
        $centroid = DB::selectOne(
            'SELECT ST_Y(ST_Centroid(geom)) AS lat, ST_X(ST_Centroid(geom)) AS lng '
            .'FROM jurisdictions WHERE id = ? AND geom IS NOT NULL AND deleted_at IS NULL',
            [$jurisdictionId],
        );

        if ($centroid === null) {
            return [];
        }

        return $this->nearestToPoint((float) $centroid->lat, (float) $centroid->lng, $limit);
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

        // Censored posture: a resistant transport is the first hop, so a blocked
        // clearnet endpoint is never the visible front (stable within distance order).
        if ((bool) config('cga.federation_censorship_floor_first', false)) {
            usort($out, fn (array $a, array $b) => $this->floorRank($a) <=> $this->floorRank($b));
        }

        return array_slice($out, 0, $limit);
    }

    private function floorRank(array $endpoint): int
    {
        return in_array($endpoint['transport'], self::RESISTANT, true) ? 0 : 1;
    }
}
