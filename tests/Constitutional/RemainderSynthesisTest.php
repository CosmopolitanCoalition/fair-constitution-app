<?php

namespace Tests\Constitutional;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * PIN — REMAINDER SYNTHESIS (the coverage-gap repair, 2026-07-18): a parent
 * whose children cover only a sliver gets ONE synthesized child holding the
 * uncovered territory + population, making the children-sum law's input
 * truthful. The GEOMETRIC discriminator is constitutional: parents whose
 * children tile the territory but whose populations disagree are the
 * SETTLED noise class — never harmonized, never synthesized (Delhi-class
 * drift stays informational forever).
 */
class RemainderSynthesisTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_remainder_pin';

    public function test_synthesizes_only_where_territory_is_actually_uncovered(): void
    {
        $this->onLivePg(function (array $ctx) {
            Artisan::call('geodata:synthesize-remainders', ['--min-pop' => 1000]);

            // The gapped parent gets its remainder: pop = parent − children,
            // geometry = the uncovered ~99% of the envelope, lineage marked.
            $rem = DB::table('jurisdictions')
                ->where('parent_id', $ctx['gapland_id'])
                ->where('source', 'synthesized-remainder')
                ->first();
            $this->assertNotNull($rem, 'the coverage-gapped parent must receive a remainder child');
            $this->assertSame(9700000, (int) $rem->population, 'remainder pop = parent − children-sum');
            $this->assertSame(3, (int) $rem->adm_level);
            $this->assertSame('remainder_synthesis', $rem->parent_assigned_via);
            $areaFrac = (float) DB::scalar('
                SELECT ST_Area(r.geom) / ST_Area(p.geom)
                  FROM jurisdictions r, jurisdictions p
                 WHERE r.id = ? AND p.id = ?', [$rem->id, $ctx['gapland_id']]);
            $this->assertGreaterThan(0.9, $areaFrac, 'the remainder holds the uncovered territory');

            // Ledger: replayable, revertible.
            $this->assertSame(1, (int) DB::table('geodata_repairs')
                ->where('action', 'synthesize_remainder')
                ->where('target_slug', $ctx['gapland_slug'])
                ->count(), 'every synthesis writes its repair-ledger row');

            // The NOISE parent (children tile the area, populations disagree)
            // is never touched — the settled class stays settled.
            $this->assertSame(0, (int) DB::table('jurisdictions')
                ->where('parent_id', $ctx['noiseland_id'])
                ->where('source', 'synthesized-remainder')
                ->count(), 'population-noise parents are NEVER synthesized (area discriminator)');

            // Idempotence: a second pass creates nothing new.
            Artisan::call('geodata:synthesize-remainders', ['--min-pop' => 1000]);
            $this->assertSame(1, (int) DB::table('jurisdictions')
                ->where('source', 'synthesized-remainder')
                ->whereIn('parent_id', [$ctx['gapland_id'], $ctx['noiseland_id']])
                ->count(), 'a parent with a remainder is no longer a candidate');
        });
    }

    public function test_manifest_replay_reproduces_renames_and_remainders(): void
    {
        $this->onLivePg(function (array $ctx) {
            // The reproducibility contract (operator ruling 2026-07-18): a
            // fresh import + this manifest must land on the same names and
            // the same synthesized remainders. Both actions replay through
            // geodata:repairs-apply and SKIP when state already matches.
            $manifest = [
                'repairs' => [
                    ['action' => 'rename', 'target_slug' => $ctx['gapland_slug'],
                     'params' => ['new_name' => 'Gapland Proper']],
                    ['action' => 'synthesize_remainder', 'target_slug' => $ctx['gapland_slug'],
                     'params' => []],
                ],
            ];
            $path = storage_path('app/pin-replay-manifest.json');
            file_put_contents($path, json_encode($manifest));

            try {
                $exit = Artisan::call('geodata:repairs-apply', ['file' => $path]);
                $this->assertSame(0, $exit, Artisan::output());

                $this->assertSame('Gapland Proper', DB::table('jurisdictions')
                    ->where('slug', $ctx['gapland_slug'])->value('name'), 'rename replays by slug');
                $rem = DB::table('jurisdictions')
                    ->where('parent_id', $ctx['gapland_id'])
                    ->where('source', 'synthesized-remainder')->first();
                $this->assertNotNull($rem, 'remainder re-derives from LOCAL data on replay');
                $this->assertSame(9700000, (int) $rem->population);

                // Second replay: everything SKIPs — nothing duplicated.
                $exit = Artisan::call('geodata:repairs-apply', ['file' => $path]);
                $out  = Artisan::output();
                $this->assertSame(0, $exit);
                $this->assertSame(2, substr_count($out, 'SKIP'), $out);
                $this->assertSame(1, (int) DB::table('jurisdictions')
                    ->where('parent_id', $ctx['gapland_id'])
                    ->where('source', 'synthesized-remainder')->count());
            } finally {
                @unlink($path);
            }
        });
    }

    public function test_refuses_while_map_data_is_accepted(): void
    {
        $this->onLivePg(function (array $ctx) {
            $has = DB::table('instance_settings')->whereNull('deleted_at')->exists();
            if (! $has) {
                $this->markTestSkipped('no instance_settings row on this box — the window gate has nothing to gate');
            }
            DB::table('instance_settings')->whereNull('deleted_at')->update(['map_accepted_at' => now()]);

            $exit = Artisan::call('geodata:synthesize-remainders', ['--min-pop' => 1000]);

            $this->assertSame(1, $exit, 'repairs and acceptance never overlap — the window gate refuses');
            $this->assertSame(0, (int) DB::table('jurisdictions')->where('source', 'synthesized-remainder')
                ->where('parent_id', $ctx['gapland_id'])->count());
        });
    }

    // ── fixture ─────────────────────────────────────────────────────────────

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            // The command gates on the acceptance window — open it in-tx.
            DB::table('instance_settings')->whereNull('deleted_at')->update(['map_accepted_at' => null]);
            $body($this->buildFixture());
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }

    private function buildFixture(): array
    {
        $mk = function (string $name, ?string $parentId, int $lvl, int $pop, array $r): array {
            $id = (string) Str::uuid();
            $slug = 'zzr-'.$lvl.'-'.Str::slug($name).'-'.substr($id, 0, 8);
            DB::insert(
                "INSERT INTO jurisdictions
                    (id, name, slug, iso_code, adm_level, parent_id, population, is_active, is_civic_active,
                     source, official_languages, timezone, geom, centroid, created_at, updated_at)
                 VALUES (?, ?, ?, 'ZZR', ?, ?, ?, true, true, 'pin-fixture', '[]', 'UTC',
                         ST_Multi(ST_MakeEnvelope(?, ?, ?, ?, 4326)),
                         ST_Centroid(ST_MakeEnvelope(?, ?, ?, ?, 4326)), now(), now())",
                [$id, $name, $slug, $lvl, $parentId, $pop, ...$r, ...$r]
            );

            return [$id, $slug];
        };

        // Gapland: 10M, one sliver child (300k, 1% of the area) — the Moscow shape.
        [$gapId, $gapSlug] = $mk('Gapland', null, 2, 10000000, [30.0, 50.0, 31.0, 51.0]);
        $mk('Gap Sliver', $gapId, 3, 300000, [30.0, 50.0, 30.1, 50.1]);

        // Noiseland: children tile the WHOLE envelope but sum to 60% of the
        // stored population — the Delhi/settled-noise shape.
        [$noiseId] = $mk('Noiseland', null, 2, 1000000, [40.0, 50.0, 41.0, 51.0]);
        $mk('Noise West', $noiseId, 3, 300000, [40.0, 50.0, 40.5, 51.0]);
        $mk('Noise East', $noiseId, 3, 300000, [40.5, 50.0, 41.0, 51.0]);

        return [
            'gapland_id'   => $gapId,
            'gapland_slug' => $gapSlug,
            'noiseland_id' => $noiseId,
        ];
    }
}
