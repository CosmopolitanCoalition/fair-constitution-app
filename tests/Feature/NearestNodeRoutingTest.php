<?php

namespace Tests\Feature;

use App\Models\DirectoryEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G8b / C5): public nearest-node routing. GET
 * /api/mesh/nearest answers "which mesh node should this client talk to?" from the
 * already-public G9 directory, by distance. The pins:
 *   1. it returns the CLOSEST serving node to a point (or a picked jurisdiction);
 *   2. PRIVACY — a supplied coordinate is NEVER persisted (no location_pings, nothing),
 *      and the answer is no-store so a location-derived route is never cached;
 *   3. it refuses (rather than guessing a location) when given neither input, and
 *      rejects an out-of-range coordinate.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class NearestNodeRoutingTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_mesh_nearest';

    public function test_nearest_returns_the_closest_node_and_persists_no_coordinate(): void
    {
        $this->onLivePg(function () {
            DB::table('directory_entries')->delete(); // clean slate within the rolled-back txn

            // The two LARGEST top-level jurisdictions: big, non-overlapping, far apart —
            // so a point ON one's surface is unambiguously nearest to it even after the
            // ~11km privacy rounding. Cheap (a planar area sort over ~ADM0 rows; NOT the
            // geodesic full-table scan a "farthest jurisdiction" query would force).
            $root = DB::selectOne('SELECT id FROM jurisdictions WHERE parent_id IS NULL AND deleted_at IS NULL LIMIT 1');
            if ($root === null) {
                $this->markTestSkipped('no root jurisdiction');
            }
            $bigs = DB::select('SELECT id, ST_Y(ST_PointOnSurface(geom)) AS lat, ST_X(ST_PointOnSurface(geom)) AS lng '
                .'FROM jurisdictions WHERE geom IS NOT NULL AND deleted_at IS NULL AND parent_id = ? '
                .'ORDER BY ST_Area(geom) DESC LIMIT 2', [$root->id]);
            if (count($bigs) < 2) {
                $this->markTestSkipped('need two top-level jurisdictions with geom');
            }
            [$j1, $j2] = $bigs;

            $near = (string) Str::uuid();
            $far = (string) Str::uuid();
            $this->directoryEntry((string) $j1->id, $near, 'https://near.test');
            $this->directoryEntry((string) $j2->id, $far, 'https://far.test');

            $pingsBefore = DB::table('location_pings')->count();

            $resp = $this->getJson('/api/mesh/nearest?lat='.$j1->lat.'&lng='.$j1->lng);

            $resp->assertOk()->assertJsonPath('nodes.0.server_id', $near);
            $this->assertStringContainsString('no-store', (string) $resp->headers->get('Cache-Control'));

            // PRIVACY: the coordinate is never written anywhere — no residency ping.
            $this->assertSame($pingsBefore, DB::table('location_pings')->count(), 'no location_pings written');
        });
    }

    public function test_nearest_by_jurisdiction_pick_needs_no_coordinate(): void
    {
        $this->onLivePg(function () {
            DB::table('directory_entries')->delete();

            $j1 = DB::selectOne('SELECT id FROM jurisdictions WHERE geom IS NOT NULL AND deleted_at IS NULL LIMIT 1');
            if ($j1 === null) {
                $this->markTestSkipped('no jurisdiction with geom in this DB');
            }
            $server = (string) Str::uuid();
            $this->directoryEntry((string) $j1->id, $server, 'https://pick.test');

            $resp = $this->getJson('/api/mesh/nearest?jurisdiction='.$j1->id);

            $resp->assertOk()->assertJsonPath('nodes.0.server_id', $server);
            $resp->assertJsonPath('nodes.0.transport', 'https');
        });
    }

    public function test_nearest_refuses_without_inputs_and_rejects_bad_coordinates(): void
    {
        $this->onLivePg(function () {
            $this->getJson('/api/mesh/nearest')->assertStatus(422);
            $this->getJson('/api/mesh/nearest?lat=999&lng=0')->assertStatus(422);
        });
    }

    private function directoryEntry(string $jurisdictionId, string $serverId, string $url): void
    {
        DirectoryEntry::create([
            'jurisdiction_id' => $jurisdictionId,
            'server_id' => $serverId,
            'endpoints' => [['transport' => 'https', 'url' => $url]],
            'priority' => 100,
            'signature' => 'test-sig',
            'published_at' => now(),
        ]);
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
