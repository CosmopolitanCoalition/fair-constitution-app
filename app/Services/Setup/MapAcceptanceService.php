<?php

namespace App\Services\Setup;

use App\Models\AutoscaleRun;
use App\Models\InstanceSettings;
use App\Support\WorldBuildVerifier;
use Illuminate\Support\Facades\DB;

/**
 * THE ONE MAP-DATA ACCEPTANCE PATH. Accepting map data closes the repair
 * window, stamps the instance, records the activation mode, and (under eager)
 * starts the planet-wide autoscale run. Three doors reach it — the wizard
 * endpoint (JurisdictionController::acceptMaps), the `maps:accept` CLI command,
 * and a backup restore (MapDataImportService) — and they must leave identical
 * state or the setup wizard and the CLI drift.
 *
 * WHY A SERVICE, not three copies. The `maps:accept` command had drifted from
 * the endpoint: no verifier gate, no mode write, and a run created with the
 * wrong status. The restore path stamped map_accepted_at with no mode and no
 * run. Both are the same defect — acceptance logic copied and left to rot. It
 * lives here once now, and every door is a thin caller that renders the
 * result.
 *
 * The verifier report is injected so the pure state transitions unit-test on a
 * SQLite fixture; the default reads the live PostgreSQL world.
 */
class MapAcceptanceService
{
    /** @var callable(): array<string,mixed> */
    private $worldReport;

    /**
     * @param  ?callable(): array<string,mixed>  $worldReport  the world-build completeness report provider
     */
    public function __construct(?callable $worldReport = null)
    {
        $this->worldReport = $worldReport ?? static fn (): array => WorldBuildVerifier::report();
    }

    public function accept(MapAcceptanceOptions $opts): MapAcceptanceResult
    {
        // NULL-POP NORMALIZATION AT ACCEPTANCE (operator ruling 2026-09-09).
        // Ingestion should leave every population at 0, never NULL, but a
        // survivor NULL trips the districting's unassigned-constituents check
        // and parks the map in review. At acceptance, before any drawing,
        // normalize all remaining NULL populations to 0 in one pass.
        // Idempotent; touches only the anomalous NULL rows; no seat total
        // changes because COALESCE reads NULL as 0 everywhere else.
        DB::table('jurisdictions')->whereNull('population')
            ->update(['population' => 0, 'updated_at' => now()]);

        // THE ACCEPT GATE (operator plan 2026-08-31): a request that would
        // START the drawing verifies the world build FIRST, before anything
        // stamps. An incomplete build returns the live progress report — BUT
        // the operator can proceed anyway (forceIncompleteBuild, operator order
        // 2026-09-19: a hard block the operator could not pass is not a gate, it
        // is a wall; the escape-hatch law says the operator can always continue
        // with the state documented). Non-run modes (manual / population) and
        // the restore door skip the gate entirely.
        if ($opts->gateOnVerifier && ! $opts->forceIncompleteBuild) {
            $report = ($this->worldReport)();
            if (empty($report['complete'])) {
                return new MapAcceptanceResult(
                    MapAcceptanceResult::WORLD_BUILD_INCOMPLETE,
                    ['progress' => $report],
                );
            }
        }

        // Locked check-then-stamp: repairs take the same instance_settings row
        // lock as the first statement of their transactions, so acceptance
        // serializes against an in-flight repair instead of racing it.
        $gate = DB::transaction(function () use ($opts) {
            $instance = InstanceSettings::query()->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $instance) {
                return ['result' => new MapAcceptanceResult(MapAcceptanceResult::MISSING_INSTANCE)];
            }

            if ($instance->map_accepted_at) {
                // A re-accept RESUMES an unfinished run (the reboot/halt
                // recovery path); a live run is left alone.
                $unfinished = AutoscaleRun::unfinished();
                if ($unfinished !== null) {
                    if ($unfinished->status !== 'halted' && ! $unfinished->haltRequested()) {
                        return ['result' => new MapAcceptanceResult(
                            MapAcceptanceResult::ALREADY_LIVE,
                            ['run_status' => $unfinished->status],
                            $unfinished,
                        )];
                    }
                    $unfinished->forceFill(['halt_requested_at' => null])->save();

                    return ['result' => new MapAcceptanceResult(
                        MapAcceptanceResult::RESUMED,
                        [],
                        $unfinished,
                        true,
                    )];
                }

                // THE RE-HOOK (operator 2026-08-06): acceptance may have
                // DEFERRED the planet build; the start control re-posts with
                // startAutoscale and the run is created through the same path
                // as a fresh eager acceptance. A completed run is left alone.
                if ($opts->startAutoscale) {
                    return [
                        'instance' => $instance,
                        'open_flags' => ['critical' => 0, 'warning' => 0, 'info' => 0],
                        'mode' => 'eager',
                        'simulate' => false,
                        'start_run' => true,
                        'rehook' => true,
                    ];
                }

                return ['result' => new MapAcceptanceResult(
                    MapAcceptanceResult::ALREADY_ACCEPTED,
                    [
                        'map_accepted_at' => $instance->map_accepted_at->toIso8601String(),
                        'apportionment_completed_at' => $instance->apportionment_completed_at?->toIso8601String(),
                    ],
                )];
            }

            // Repair-plane acknowledgment gate: accepting CLOSES the repair
            // window, so open geodata flags must be surfaced first. Portable
            // conditional aggregation so the seam runs on both drivers.
            $openFlags = $this->openFlagCounts();
            if (array_sum($openFlags) > 0 && ! $opts->acknowledgeOpenFlags) {
                return ['result' => new MapAcceptanceResult(
                    MapAcceptanceResult::REQUIRES_ACKNOWLEDGMENT,
                    ['open_flags' => $openFlags],
                )];
            }

            // THE THREE ACTIVATION MODES (operator, 2026-08-08). The mode is
            // chosen at acceptance and stored; everything downstream reads it.
            //   eager      → the full-scale build starts below.
            //   population → nothing starts; CLK-06 boots each place as
            //                verified residents cross its threshold.
            //   manual     → nothing starts; the Activate controls build the
            //                world by hand.
            // simulate_at_scale is dev-only (game_mode sandbox), eager only.
            $mode = $opts->mode;
            $simulate = $mode === 'eager'
                && $opts->simulateAtScale
                && $instance->game_mode === 'sandbox';

            $instance->forceFill([
                'map_accepted_at' => now(),
                'setup_step_completed' => max((int) $instance->setup_step_completed, 2),
                'institution_scale_mode' => $mode,
                'simulate_at_scale' => $simulate,
            ])->save();

            return [
                'instance' => $instance,
                'open_flags' => $openFlags,
                'mode' => $mode,
                'simulate' => $simulate,
                'start_run' => $mode === 'eager',
                'rehook' => false,
            ];
        });

        if (isset($gate['result'])) {
            return $gate['result'];
        }

        /** @var InstanceSettings $instance */
        $instance = $gate['instance'];
        $openFlags = $gate['open_flags'];
        $mode = $gate['mode'];

        // Non-run modes (manual / population): acceptance is stamped and the
        // repair window is closed, but the planet build does NOT start.
        if (! ($gate['start_run'] ?? false)) {
            return new MapAcceptanceResult(
                MapAcceptanceResult::ACCEPTED,
                [
                    'map_accepted_at' => $instance->map_accepted_at->toIso8601String(),
                    'open_flags' => $openFlags,
                    'mode' => $mode,
                    'simulate' => (bool) ($gate['simulate'] ?? false),
                    'deferred' => true,
                ],
            );
        }

        // AUTOSCALE (pull engine): create or resume the run, then signal the
        // caller to kick the pump. Run creation lives OUTSIDE the locked
        // transaction so the instance_settings row lock is never held through
        // the ledger count. An unfinished run (paused by a reopen halt)
        // resumes instead of minting a second; the pump's oldest-wins dedupe
        // backstops the ms-window against a racing CLI start.
        $run = AutoscaleRun::unfinished();
        if ($run === null) {
            // Born MAPPING: the sizing phase retired into the world build, so
            // the flip IS the benchmark clock. Ledger totals via portable
            // conditional aggregation.
            $totals = $this->ledgerKindTotals();
            $run = AutoscaleRun::create([
                'status' => 'mapping',
                'mapping_started_at' => now(),
                'adm_max' => (int) config('cga.autoscale_adm_max', 6),
                'initiator_user_id' => $opts->initiatorUserId,
                'template' => null, // constitutional default per legislature
                'singles_total' => $totals['singles'],
                'sweeps_total' => $totals['sweeps'],
            ]);
        } else {
            $run->forceFill(['halt_requested_at' => null])->save();
        }

        return new MapAcceptanceResult(
            MapAcceptanceResult::ACCEPTED,
            [
                'map_accepted_at' => $instance->map_accepted_at->toIso8601String(),
                'open_flags' => $openFlags,
                'mode' => $mode,
                'simulate' => (bool) ($gate['simulate'] ?? false),
                'rehook' => (bool) ($gate['rehook'] ?? false),
            ],
            $run,
            true,
        );
    }

    /**
     * Open geodata flag counts by severity. Portable conditional aggregation
     * (SUM(CASE …)) so the seam reads the same on PostgreSQL and the SQLite
     * fixture; the counts match the endpoint's FILTER form exactly.
     *
     * @return array{critical:int, warning:int, info:int}
     */
    private function openFlagCounts(): array
    {
        $row = DB::table('geodata_flags')
            ->whereNull('deleted_at')
            ->where('status', 'open')
            ->selectRaw("
                SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) AS critical,
                SUM(CASE WHEN severity = 'warning'  THEN 1 ELSE 0 END) AS warning,
                SUM(CASE WHEN severity = 'info'     THEN 1 ELSE 0 END) AS info
            ")
            ->first();

        return [
            'critical' => (int) ($row->critical ?? 0),
            'warning' => (int) ($row->warning ?? 0),
            'info' => (int) ($row->info ?? 0),
        ];
    }

    /**
     * Apportionment-ledger kind totals for a born-mapping run. Portable
     * conditional aggregation, matching the endpoint's FILTER form.
     *
     * @return array{singles:int, sweeps:int}
     */
    private function ledgerKindTotals(): array
    {
        $row = DB::table('apportionment_ledger')
            ->selectRaw("
                SUM(CASE WHEN kind = 'single' THEN 1 ELSE 0 END) AS singles,
                SUM(CASE WHEN kind IS NULL OR kind <> 'single' THEN 1 ELSE 0 END) AS sweeps
            ")
            ->first();

        return [
            'singles' => (int) ($row->singles ?? 0),
            'sweeps' => (int) ($row->sweeps ?? 0),
        ];
    }
}
