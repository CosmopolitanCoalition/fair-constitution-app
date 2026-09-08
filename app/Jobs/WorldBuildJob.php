<?php

namespace App\Jobs;

use App\Services\Autoscale\AdjacencyPrecompute;
use App\Support\AutoscaleClaims;
use App\Support\AutoscaleEnumeration;
use App\Support\HostCapacity;
use App\Support\WorldBuildVerifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PHASE 2 — THE WORLD BUILD (operator plan 2026-08-31). Runs at the ingest
 * tail and writes every fact the drawing phase reads, once per dataset:
 * all legislatures, the adjacency ledger, the apportionment ledger with
 * ordering + block keys, sweep-leaf self scopes, gate verdicts born on
 * review, founding-map containers, the root bootstrap board. Step-latched
 * in world_builds.steps, chunked, re-entrant; the pump's worldBuildTick
 * re-dispatches a stale build. Acceptance (phase 3) verifies this job's
 * result through WorldBuildVerifier and only then flips to mapping.
 */
class WorldBuildJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 0;

    public int $tries = 1;

    /**
     * UNIQUE-JOB LOCK (operator ruling 2026-09-08, the held #5 gone LIVE). The
     * pump and AutoscalePumpCommand::worldBuildTick re-dispatch this job to
     * resume a dead build, but WITHOUT a unique lock every dispatch ran
     * concurrently: 18 instances were observed contending on the same
     * apportionment_ledger stamp rows, freezing the build (maps/block_keys
     * stuck while backends fought tuple locks). ShouldBeUnique drops a
     * duplicate dispatch while one build holds the lock, so a re-dispatch
     * resumes a DEAD build (lock releases on finish/failure or after uniqueFor)
     * but never stacks on a live one. uniqueFor exceeds the longest
     * planet-scale build so a slow build is never mistaken for done.
     */
    public int $uniqueFor = 7200;

    public function uniqueId(): string
    {
        return 'world-build';
    }

    public function __construct(public ?string $geodataRunId = null)
    {
        $this->onQueue('autoscale');
    }

    public function handle(): void
    {
        // A truly ACTIVE run owns the work tables; a halted or finished run
        // never suppresses the build (the old guard's 'halted' hole).
        if (DB::table('autoscale_runs')->whereIn('status', ['queued', 'sizing', 'mapping'])->exists()) {
            Log::info('WorldBuild: an autoscale run is active — skipping');

            return;
        }

        $build = DB::table('world_builds')->where('status', 'building')->orderByDesc('created_at')->first();
        if ($build === null) {
            $id = (string) \Illuminate\Support\Str::uuid();
            DB::table('world_builds')->insert([
                'id' => $id, 'geodata_run_id' => $this->geodataRunId,
                'status' => 'building', 'lease_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $build = DB::table('world_builds')->where('id', $id)->first();
        }
        $bid = (string) $build->id;
        $beat = function () use ($bid) {
            DB::table('world_builds')->where('id', $bid)->update(['lease_at' => now(), 'updated_at' => now()]);
        };
        $done = function (string $step) use ($bid): bool {
            return isset(((array) json_decode((string) DB::table('world_builds')->where('id', $bid)->value('steps'), true))[$step]);
        };
        $mark = function (string $step) use ($bid) {
            DB::update("
                UPDATE world_builds
                   SET steps = COALESCE(steps, '{}'::jsonb) || jsonb_build_object(?::text, now()::text),
                       updated_at = now()
                 WHERE id = ?", [$step, $bid]);
        };

        // SINGLE-BUILD LOCK (operator ruling 2026-09-08, the WoS deadlock loop).
        // ShouldBeUnique above depends on the default CACHE lock, which on the
        // cloud box was not shared across workers (redis dbsize=0 while builds
        // ran), so it dropped nothing and 3 concurrent WorldBuildJobs ran the
        // same apportionment_ledger stamp UPDATEs and deadlocked (SQLSTATE 40P01)
        // in a fail-retry loop. A POSTGRES session advisory lock is the codebase
        // idiom (cga_giant_parse) and is cache-INDEPENDENT: postgres is always up
        // during a build. A second build that cannot take it returns at once, so
        // exactly one instance runs the stamps. Released in the finally on every
        // exit (the drain return, completion, the rethrow); a hard kill drops it
        // with the connection.
        if (! (bool) DB::selectOne("SELECT pg_try_advisory_lock(hashtext('cga_world_build')) AS got")->got) {
            Log::info('WorldBuild: another build holds the advisory lock — skipping', ['world_build_id' => $bid]);

            return;
        }

        try {
            if (! $done('parents')) {
                Artisan::call('apportionment:seed', ['--parents-only' => true]);
                $mark('parents');
            }
            $beat();

            if (! $done('leaves')) {
                $floor = \App\Services\ConstitutionalDefaults::floor();
                for ($lvl = 0; $lvl <= 6; $lvl++) {
                    AutoscaleEnumeration::seedLeafLegislatures($lvl, $floor);
                }
                $mark('leaves');
            }
            $beat();

            if (! $done('board')) {
                AutoscaleEnumeration::ensureRootBootstrapBoard();
                $mark('board');
            }

            // Headers + stale reopen every entry (idempotent, cheap when settled).
            AutoscaleEnumeration::seedApportionmentWorklist();
            $beat();

            if (! $done('adjacency_lanes')) {
                $pending = app(AdjacencyPrecompute::class)->seedWorklist();
                $lanes = max(2, min(HostCapacity::autoscaleWorkers(), max(1, $pending)));
                for ($i = 0; $i < $lanes; $i++) {
                    PrecomputeLaneJob::dispatch();
                }
                $mark('adjacency_lanes');
            }

            // The compute drain gates everything below: stamps and containers
            // need the heads. Dispatch lanes and return; a drained lane (and
            // the pump tick) re-enters this job.
            $open = (int) DB::table('apportionment_ledger')
                ->whereIn('compute_status', ['pending', 'running'])->count();
            if ($open > 0) {
                $lanes = max(2, min(HostCapacity::autoscaleWorkers(), $open));
                for ($i = 0; $i < $lanes; $i++) {
                    ApportionmentLaneJob::dispatch();
                }
                $beat();
                Log::info('WorldBuild: apportionment draining', ['open' => $open, 'lanes' => $lanes]);

                return;
            }

            AutoscaleEnumeration::deriveOrderingKeysOnLedger(
                \App\Services\ConstitutionalDefaults::ceiling(), $beat);
            AutoscaleEnumeration::seedSweepLeafSelfScopes();
            AutoscaleEnumeration::stampScopeClass($beat);
            AutoscaleEnumeration::stampReversePositions();
            // THE DISPATCH ORDER: the one global sequence claims pop from.
            AutoscaleEnumeration::stampDispatchOrder($beat);
            $beat();
            AutoscaleEnumeration::stampGateRefusals();
            AutoscaleEnumeration::mintFoundingMapsLedger(fn () => $beat());
            AutoscaleEnumeration::stampFoundingMapIdsLedger(fn () => $beat());
            $beat();

            $report = WorldBuildVerifier::report();
            // Adjacency drains on its own lanes; the build is complete for
            // acceptance purposes when every OTHER piece stands — the
            // verifier still gates acceptance on adjacency independently.
            $ready = $report['legislatures']['missing_headers'] === 0
                && $report['legislatures']['unsized_parents'] === 0
                && $report['legislatures']['unsized_leaves'] === 0
                && $report['apportionment']['open'] === 0
                && $report['apportionment']['failed'] === 0
                && $report['maps']['unstamped'] === 0
                && $report['block_keys_missing'] === 0
                && $report['board'];
            if ($ready) {
                DB::table('world_builds')->where('id', $bid)->update([
                    'status' => 'complete', 'last_error' => null, 'updated_at' => now(),
                ]);
                Log::info('WorldBuild: complete', ['world_build_id' => $bid, 'refusals' => $report['apportionment']['refusals']]);
            } else {
                DB::table('world_builds')->where('id', $bid)->update([
                    'last_error' => 'incomplete: '.json_encode($report), 'updated_at' => now(),
                ]);
                Log::warning('WorldBuild: pieces still open after full pass', ['report' => $report]);
            }
        } catch (\Throwable $e) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::table('world_builds')->where('id', $bid)->update([
                'last_error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now(),
            ]);
            Log::error('WorldBuild failed: '.$e->getMessage(), ['world_build_id' => $bid]);
            throw $e;
        } finally {
            // Release the single-build advisory lock on EVERY exit (the drain
            // return, completion, or the rethrow above). A deadlock leaves the
            // connection usable, so the unlock lands; a hard kill drops the lock
            // with the connection. Never let a release error mask the real
            // exception.
            try {
                DB::statement("SELECT pg_advisory_unlock(hashtext('cga_world_build'))");
            } catch (\Throwable) {
            }
        }
    }
}
