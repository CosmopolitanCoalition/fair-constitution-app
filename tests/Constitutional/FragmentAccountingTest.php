<?php

namespace Tests\Constitutional;

use App\Domain\Forms\Handlers\ManualDistrictDraw;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the Art. II §8 part census (LA-County islands ruling
 * 2026-07-17, generalized 2026-07-20 for the scattered-remainder class).
 *
 * Source multipolygons often store TOUCHING rings as separate components
 * (tiling noise — Madanpur Rampur's "108 parts" are a handful of connected
 * clusters). A blade side's union dissolves them, so a drawn part may
 * lawfully be a UNION of whole components — nothing was cut. The census must
 * count such parts as whole-composed; only parts containing genuinely CUT
 * territory are fragments, and at most ONE fragment per piece is admitted.
 * The one-fragment LAW itself is unchanged.
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
     * S1|S2 dissolve into one part when unioned; likewise S3|S4. G, the two
     * clusters, and D are all detached from each other.
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
                 ST_Centroid(ST_MakeEnvelope(40.0, 50.0, 40.38, 50.1, 4326)), ?, ?)",
            [$id, 'zz-2-cluster-isles-'.substr($id, 0, 8), $now, $now]
        );

        return ['scope_id' => $id];
    }

    public function test_a_union_of_whole_touching_components_is_not_a_fragment(): void
    {
        $this->onLivePg(function (array $ctx) {
            // The Madanpur shape: a cut of G + BOTH clusters riding whole.
            // Parts after dissolve: G-fragment, S1∪S2, S3∪S4 = 3 parts.
            // The census must see 2 whole-composed parts (the dissolved
            // clusters) and only 1 cut fragment — a filable piece.
            $piece = json_encode(['type' => 'GeometryCollection', 'geometries' => [
                ['type' => 'Polygon', 'coordinates' => [[[40.00, 50.00], [40.12, 50.00], [40.12, 50.10], [40.00, 50.10], [40.00, 50.00]]]],
                ['type' => 'Polygon', 'coordinates' => [[[40.30, 50.00], [40.38, 50.00], [40.38, 50.05], [40.30, 50.05], [40.30, 50.00]]]],
                ['type' => 'Polygon', 'coordinates' => [[[40.30, 50.06], [40.38, 50.06], [40.38, 50.10], [40.30, 50.10], [40.30, 50.06]]]],
            ]]);

            $geo = ManualDistrictDraw::partCensus($piece, $ctx['scope_id']);

            $this->assertSame(3, (int) $geo->parts);
            $this->assertSame(2, (int) $geo->whole_parts,
                'each dissolved cluster is a UNION of whole components — never a cut fragment');
            $this->assertSame(1, (int) $geo->parts - (int) $geo->whole_parts,
                'exactly the one lawful cut fragment (the blade piece of G) remains');
            $this->assertTrue((bool) $geo->within);
        });
    }

    public function test_a_single_whole_component_still_counts_whole(): void
    {
        $this->onLivePg(function (array $ctx) {
            // The original LA-islands identity (n=1 case): G-cut + the whole
            // detached isle D riding along.
            $piece = json_encode(['type' => 'GeometryCollection', 'geometries' => [
                ['type' => 'Polygon', 'coordinates' => [[[40.00, 50.00], [40.12, 50.00], [40.12, 50.10], [40.00, 50.10], [40.00, 50.00]]]],
                ['type' => 'Polygon', 'coordinates' => [[[40.00, 50.12], [40.06, 50.12], [40.06, 50.16], [40.00, 50.16], [40.00, 50.12]]]],
            ]]);

            $geo = ManualDistrictDraw::partCensus($piece, $ctx['scope_id']);

            $this->assertSame(2, (int) $geo->parts);
            $this->assertSame(1, (int) $geo->whole_parts, 'a whole detached component keeps its identity');
        });
    }

    public function test_half_of_a_dissolved_touching_cluster_is_a_cut_fragment(): void
    {
        $this->onLivePg(function (array $ctx) {
            // S1 alone: its twin S2 shares a full edge, so the two stored
            // rings ARE one landmass — taking S1 without S2 cuts it. With
            // the G-cut also aboard that makes TWO fragments: a violation.
            $piece = json_encode(['type' => 'GeometryCollection', 'geometries' => [
                ['type' => 'Polygon', 'coordinates' => [[[40.00, 50.00], [40.12, 50.00], [40.12, 50.10], [40.00, 50.10], [40.00, 50.00]]]],
                ['type' => 'Polygon', 'coordinates' => [[[40.30, 50.00], [40.34, 50.00], [40.34, 50.05], [40.30, 50.05], [40.30, 50.00]]]],
            ]]);

            $geo = ManualDistrictDraw::partCensus($piece, $ctx['scope_id']);

            $this->assertSame(2, (int) $geo->parts);
            $this->assertSame(0, (int) $geo->whole_parts,
                'half of one dissolved landmass is never whole — the union rule cannot be gamed by ring boundaries');
        });
    }

    public function test_two_genuine_cut_fragments_still_refuse(): void
    {
        $this->onLivePg(function (array $ctx) {
            // Two disconnected CUT chunks of G in one piece — the shape the
            // one-fragment law exists to refuse. Neither chunk swallows any
            // whole component, so both are fragments.
            $piece = json_encode(['type' => 'GeometryCollection', 'geometries' => [
                ['type' => 'Polygon', 'coordinates' => [[[40.00, 50.00], [40.05, 50.00], [40.05, 50.10], [40.00, 50.10], [40.00, 50.00]]]],
                ['type' => 'Polygon', 'coordinates' => [[[40.15, 50.00], [40.20, 50.00], [40.20, 50.10], [40.15, 50.10], [40.15, 50.00]]]],
            ]]);

            $geo = ManualDistrictDraw::partCensus($piece, $ctx['scope_id']);

            $this->assertSame(2, (int) $geo->parts);
            $this->assertSame(0, (int) $geo->whole_parts);
            $this->assertGreaterThan(1, (int) $geo->parts - (int) $geo->whole_parts,
                'two blade-created fragments of one landmass remain a violation — the LAW is unchanged');
        });
    }
}
