<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * GIANTHOOD IS A CASCADE VERDICT, NOT A FLOAT COMPARISON (2026-08-09, the
 * Ukraine bounce).
 *
 * The mapper sidebar used to classify a child as a giant by comparing its
 * `fractional_seats` against the giant threshold. But that float is rescaled
 * for NON-giants (so their shares sum to the non-giant budget), and the
 * rescaling can carry a borderline child UP across the threshold. Live on
 * Earth: Ukraine sat at 9.4809 against the share base and 9.5244 once
 * rescaled. The sidebar drew it as a 10-seat giant with a drill-down arrow;
 * the server's own guard tested the unadjusted value, called it a non-giant,
 * and redirected every click back to the root scope. The page offered a door
 * the server would not open, and the operator lost an evening to it.
 *
 * The fixture below reproduces that arithmetic exactly:
 *   budget 30, children 10.4 / 9.4 / 5.1 / 5.1 of the full quota.
 *   The 10.4 child is a giant and locks 10 seats.
 *   ngQuota = (30.0 − 10.4) / (30 − 10) = 0.98 of the full quota,
 *   so the 9.4 child displays 9.4 / 0.98 = 9.59 — over the threshold,
 *   while the cascade still (correctly) says it is NOT a giant.
 *
 * The payload must therefore ship a fractional_seats at or above the
 * threshold AND is_giant false. Any future change that goes back to deriving
 * gianthood from the float fails here.
 */
class GiantClassificationTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_giant_classification';

    public function test_a_rescaled_child_over_the_threshold_is_still_not_a_giant(): void
    {
        $this->onLivePg(function () {
            $slug = 'giantclass-' . substr((string) Str::uuid(), 0, 8);
            $rootId = $this->jurisdiction('Giantclass Root', $slug, 0, null, 30_000_000, 0, 6);

            $legId = (string) Str::uuid();
            DB::table('legislatures')->insert([
                'id' => $legId, 'jurisdiction_id' => $rootId, 'status' => 'forming',
                'type_a_seats' => 30, 'type_b_seats' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // 10.4 → giant (locks 10). 9.4 → NOT a giant, but rescales to 9.59.
            $this->jurisdiction('Bigland', $slug . '-big', 1, $rootId, 10_400_000, 0, 2);
            $this->jurisdiction('Borderland', $slug . '-border', 1, $rootId, 9_400_000, 2, 4);
            $this->jurisdiction('Smalland A', $slug . '-a', 1, $rootId, 5_100_000, 4, 5);
            $this->jurisdiction('Smalland B', $slug . '-b', 1, $rootId, 5_100_000, 5, 6);

            $this->get("/legislatures/{$slug}/districts")
                ->assertOk()
                ->assertInertia(function (Assert $page) {
                    $children = collect($page->toArray()['props']['children'] ?? [])
                        ->keyBy('name');

                    $this->assertTrue($children->has('Borderland'), 'the borderline child is in the payload');
                    $border = $children['Borderland'];
                    $big    = $children['Bigland'];

                    $this->assertGreaterThanOrEqual(
                        9.5,
                        (float) $border['fractional_seats'],
                        'the fixture must actually reproduce the hazard: the rescaled share crosses the threshold'
                    );
                    $this->assertFalse(
                        (bool) $border['is_giant'],
                        'a child the CASCADE calls a non-giant must never be shipped as a giant, '
                        . 'however its display share rescales — the sidebar drills on this flag'
                    );
                    $this->assertTrue(
                        (bool) $big['is_giant'],
                        'and a real giant must still be marked as one'
                    );
                    $this->assertSame(
                        10,
                        (int) $big['cascade_seats'],
                        'the seats column reads the cascade lock, not a re-round of a display float'
                    );
                });
        });
    }

    private function jurisdiction(
        string $name,
        string $slug,
        int $admLevel,
        ?string $parentId,
        int $population,
        float $x0,
        float $x1
    ): string {
        $id  = (string) Str::uuid();
        $wkt = sprintf('MULTIPOLYGON(((%1$s 0, %2$s 0, %2$s 3, %1$s 3, %1$s 0)))', $x0, $x1);
        DB::statement("
            INSERT INTO jurisdictions (
                id, name, slug, adm_level, parent_id, population,
                source, parent_assigned_via, geom, centroid, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, 'geoboundaries', ?, ST_GeomFromText(?, 4326),
                      ST_Centroid(ST_GeomFromText(?, 4326)), NOW(), NOW())
        ", [$id, $name, $slug, $admLevel, $parentId, $population, $parentId ? 'direct' : null, $wkt, $wkt]);

        return $id;
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
