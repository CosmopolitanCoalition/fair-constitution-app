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
 * The deeper fault the operator then named ("Are you saying that Ukraine gets
 * turned into a Giant after the initial round of Giant Rounding?"): the
 * classification itself only ran ONCE. Apportionment law step 4 requires the
 * split to ITERATE — "if redistribution pushes a share past the ceiling, the
 * giant split repeats until no layer has an unsplit giant" — so Ukraine, at
 * 9.5244 of the redistributed quota, was owed a split it never got. The
 * sidebar's 10 seats were closer to the law than the guard's refusal.
 *
 * The fixture reproduces that arithmetic exactly:
 *   budget 30, children 10.4 / 9.4 / 5.1 / 5.1 of the full quota.
 *   Round 1: the 10.4 child is a giant and locks 10.
 *   Redistribute: (30.0 − 10.4) / (30 − 10) = 0.98 of the quota.
 *   Round 2: the 9.4 child is now 9.59 — past the ceiling — and is PROMOTED.
 *   Redistribute again: 10 seats for 10.2 units puts the smalls at 5.0, so the
 *   loop settles. It converges; it does not cascade.
 */
class GiantClassificationTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_giant_classification';

    public function test_redistribution_promotes_a_child_past_the_ceiling_to_a_giant(): void
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

                    // THE SPLIT ITERATES. Borderland is 9.4 of the FULL quota —
                    // not a giant in round one — but once Bigland locks 10 the
                    // remainder redistributes at 0.98 of the quota and
                    // Borderland is 9.59: past the ceiling, and owed a split.
                    // Law step 4: "if redistribution pushes a share past the
                    // ceiling, the giant split repeats until no layer has an
                    // unsplit giant."
                    $this->assertTrue(
                        (bool) $big['is_giant'],
                        'the round-one giant is a giant'
                    );
                    $this->assertSame(10, (int) $big['cascade_seats'],
                        'and locks its nearest whole');

                    $this->assertTrue(
                        (bool) $border['is_giant'],
                        'a child pushed past the ceiling BY THE REDISTRIBUTION must be promoted — '
                        . 'classifying once against the pre-redistribution quota is what left Ukraine '
                        . 'displaying 10 seats behind a drill arrow the server refused to open'
                    );
                    $this->assertSame(10, (int) $border['cascade_seats'],
                        'the promoted giant locks the nearest whole of its REDISTRIBUTED share (9.59 -> 10)');

                    // And the loop settles: with 10 seats left for 10.2 units of
                    // population the smalls sit at 5.0 — nobody else crosses.
                    foreach (['Smalland A', 'Smalland B'] as $name) {
                        $this->assertFalse(
                            (bool) $children[$name]['is_giant'],
                            "{$name} must not be swept up — the fixpoint converges, it does not cascade"
                        );
                    }
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
