<?php

namespace Tests\Constitutional;

use App\Domain\Forms\Handlers\ManualDistrictDraw;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the Art. II §8 COMPONENT census (LA-County islands
 * ruling 2026-07-17; merge-proof component bookkeeping 2026-07-20, the
 * scattered-remainder class).
 *
 * Source data stores loosely-touching rings as separate components, and a
 * blade side's union merges a cut chunk with whole islands it touches — so
 * PART counting cannot express the one-fragment law. The census counts
 * against the components themselves: a piece may cut at most ONE landmass,
 * and the cut territory must be ONE connected chunk; whole components ride
 * in any number, merged or not. The one-fragment LAW is unchanged.
 *
 * If an edit breaks these, the edit is the constitutional violation — fix the
 * edit, not the test.
 */
class FragmentAccountingTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_fragment_pin';

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $conn->beginTransaction();

        try {
            $body($this->buildClusterIsles());
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }

    /**
     * Cluster Isles — 6 stored rings, 4 true landmasses:
     *   G  (the giant):     [40.00–40.20] × [50.00–50.10]
     *   S1 + S2 (touching): [40.30–40.34] + [40.34–40.38] × [50.00–50.05]
     *   S3 + S4 (touching): [40.30–40.34] + [40.34–40.38] × [50.06–50.10]
     *   D  (detached isle): [40.00–40.06] × [50.12–50.16]
     * S1|S2 dissolve into one landmass under ST_MakeValid (full shared
     * edge); likewise S3|S4. G, the clusters, and D are detached.
     */
    private function buildClusterIsles(): array
    {
        $now = now();
        $id = (string) Str::uuid();
        DB::insert(
            "INSERT INTO jurisdictions
                (id, name, slug, iso_code, adm_level, parent_id, population, is_active, is_civic_active,
                 source, official_languages, timezone, geom, centroid, created_at, updated_at)
             VALUES
                (?, 'Cluster Isles', ?, 'ZZD', 2, NULL, 1000, true, true, 'pin-fixture', '[]', 'UTC',
                 ST_Collect(ARRAY[
                     ST_MakeEnvelope(40.00, 50.00, 40.20, 50.10, 4326),
                     ST_MakeEnvelope(40.30, 50.00, 40.34, 50.05, 4326),
                     ST_MakeEnvelope(40.34, 50.00, 40.38, 50.05, 4326),
                     ST_MakeEnvelope(40.30, 50.06, 40.34, 50.10, 4326),
                     ST_MakeEnvelope(40.34, 50.06, 40.38, 50.10, 4326),
                     ST_MakeEnvelope(40.00, 50.12, 40.06, 50.16, 4326)
                 ]),
                 ST_Centroid(ST_MakeEnvelope(40.0, 50.0, 40.38, 50.16, 4326)), ?, ?)",
            [$id, 'zz-2-cluster-isles-'.substr($id, 0, 8), $now, $now]
        );

        return ['scope_id' => $id];
    }

    public function test_one_cut_plus_whole_components_files_regardless_of_merging(): void
    {
        $this->onLivePg(function (array $ctx) {
            // The Madanpur shape: one cut of G plus BOTH whole clusters and
            // the whole isle D riding along. However the union arranges the
            // parts, the component census sees exactly one cut landmass in
            // one connected chunk — a filable piece.
            $piece = json_encode(['type' => 'GeometryCollection', 'geometries' => [
                ['type' => 'Polygon', 'coordinates' => [[[40.00, 50.00], [40.12, 50.00], [40.12, 50.10], [40.00, 50.10], [40.00, 50.00]]]],
                ['type' => 'Polygon', 'coordinates' => [[[40.30, 50.00], [40.38, 50.00], [40.38, 50.05], [40.30, 50.05], [40.30, 50.00]]]],
                ['type' => 'Polygon', 'coordinates' => [[[40.30, 50.06], [40.38, 50.06], [40.38, 50.10], [40.30, 50.10], [40.30, 50.06]]]],
                ['type' => 'Polygon', 'coordinates' => [[[40.00, 50.12], [40.06, 50.12], [40.06, 50.16], [40.00, 50.16], [40.00, 50.12]]]],
            ]]);

            $geo = ManualDistrictDraw::partCensus($piece, $ctx['scope_id']);

            $this->assertSame(1, (int) $geo->cut_components, 'only G is partially present — one cut landmass');
            $this->assertSame(1, (int) $geo->fragment_pieces, 'the cut territory is one connected chunk');
            $this->assertTrue((bool) $geo->within);
        });
    }

    public function test_whole_components_only_is_no_fragment_at_all(): void
    {
        $this->onLivePg(function (array $ctx) {
            // The components template's own shape: whole landmasses, nothing
            // cut anywhere.
            $piece = json_encode(['type' => 'GeometryCollection', 'geometries' => [
                ['type' => 'Polygon', 'coordinates' => [[[40.30, 50.00], [40.38, 50.00], [40.38, 50.05], [40.30, 50.05], [40.30, 50.00]]]],
                ['type' => 'Polygon', 'coordinates' => [[[40.00, 50.12], [40.06, 50.12], [40.06, 50.16], [40.00, 50.16], [40.00, 50.12]]]],
            ]]);

            $geo = ManualDistrictDraw::partCensus($piece, $ctx['scope_id']);

            $this->assertSame(0, (int) $geo->cut_components);
            $this->assertSame(0, (int) $geo->fragment_pieces);
        });
    }

    public function test_two_chunks_of_one_landmass_still_refuse(): void
    {
        $this->onLivePg(function (array $ctx) {
            // Two disconnected CUT chunks of G in one piece — one landmass
            // cut, but carried in two chunks: the shape the law refuses.
            $piece = json_encode(['type' => 'GeometryCollection', 'geometries' => [
                ['type' => 'Polygon', 'coordinates' => [[[40.00, 50.00], [40.05, 50.00], [40.05, 50.10], [40.00, 50.10], [40.00, 50.00]]]],
                ['type' => 'Polygon', 'coordinates' => [[[40.15, 50.00], [40.20, 50.00], [40.20, 50.10], [40.15, 50.10], [40.15, 50.00]]]],
            ]]);

            $geo = ManualDistrictDraw::partCensus($piece, $ctx['scope_id']);

            $this->assertSame(1, (int) $geo->cut_components);
            $this->assertSame(2, (int) $geo->fragment_pieces,
                'two disconnected chunks of one cut landmass remain a violation — the LAW is unchanged');
        });
    }

    public function test_cutting_two_different_landmasses_still_refuses(): void
    {
        $this->onLivePg(function (array $ctx) {
            // A chunk of G plus HALF of the dissolved S1|S2 cluster: two
            // different landmasses cut into one piece — a violation even
            // though each cut is itself connected.
            $piece = json_encode(['type' => 'GeometryCollection', 'geometries' => [
                ['type' => 'Polygon', 'coordinates' => [[[40.00, 50.00], [40.12, 50.00], [40.12, 50.10], [40.00, 50.10], [40.00, 50.00]]]],
                ['type' => 'Polygon', 'coordinates' => [[[40.30, 50.00], [40.34, 50.00], [40.34, 50.05], [40.30, 50.05], [40.30, 50.00]]]],
            ]]);

            $geo = ManualDistrictDraw::partCensus($piece, $ctx['scope_id']);

            $this->assertSame(2, (int) $geo->cut_components,
                'half of a dissolved touching cluster is a cut of its landmass — two landmasses cut refuses');
        });
    }
}
