<?php

namespace App\Console\Commands;

use App\Services\ConstitutionalDefaults;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Legislature Sizer — Cube Root Law
 *
 * Computes legislature type_a_seats using the Taagepera cube-root law:
 *   total_seats = max(5, round(SUM(direct_children.population) ^ (1/3)))
 *
 * For each parent jurisdiction this command:
 *   1. Sums direct children populations (level-local denominator)
 *   2. Computes total_seats from cube root of that sum
 *   3. Upserts a legislature record for the parent (type_a_seats only)
 *
 * It does NOT create districts. District drawing is the exclusive
 * responsibility of the district viewer's auto-composite tools
 * (DistrictingService::runAutoCompositeForScope), which seat each drawn
 * district by NEAREST rounding (operator law, 2026-07-13) and persist on
 * `legislature_districts.seats`. Per-jurisdiction apportionment columns
 * were removed in migration 2026_05_22_000002_apportionment_cleanup.php
 * — apportionment lives at the district level now.
 *
 * Usage:
 *   php artisan apportionment:seed
 *   php artisan apportionment:seed --fresh              # hard-delete and re-seed
 *   php artisan apportionment:seed --dry-run            # compute without writing
 *   php artisan apportionment:seed --adm-max=2          # only parents up to ADM2
 *   php artisan apportionment:seed --jurisdiction=slug  # one parent by slug or UUID
 */
class ApportionmentSeedCommand extends Command
{
    protected $signature = 'apportionment:seed
                            {--fresh : Hard-delete legislatures and re-seed from scratch}
                            {--dry-run : Compute apportionment but do not write to database}
                            {--adm-max=6 : Only process parent jurisdictions with adm_level <= N}
                            {--jurisdiction= : Seed only the direct children of this jurisdiction (slug or UUID)}
                            {--parents-only : Size only jurisdictions that have children (autoscale seeds childless leaves set-based)}
                            {--stamp-instance : Stamp instance_settings.apportionment_completed_at (setup-wizard runs only)}';

    protected $description = 'Compute cube-root legislature sizes and upsert legislature records (no districts)';

    private int $jurisdictionsProcessed = 0;
    private int $legislaturesCreated    = 0;

    public function handle(): int
    {
        $dryRun      = (bool)   $this->option('dry-run');
        $fresh       = (bool)   $this->option('fresh');
        $admMax      = (int)    $this->option('adm-max');
        $targetSlug  = (string) $this->option('jurisdiction');
        $parentsOnly = (bool)   $this->option('parents-only');

        $this->info('Apportionment seeder — legislature sizing (cube root law)' . ($dryRun ? ' [DRY RUN]' : ''));

        if ($fresh && ! $dryRun) {
            $this->resetData();
        }

        if ($targetSlug !== '') {
            $exitCode = $this->seedSingleJurisdiction($targetSlug, $dryRun);
        } else {
            for ($parentAdmLevel = 0; $parentAdmLevel <= $admMax; $parentAdmLevel++) {
                $this->processLevel($parentAdmLevel, $dryRun, $parentsOnly);
            }

            if (! $dryRun) {
                $this->newLine();
                $this->info(sprintf(
                    'Done. Legislatures: %d  Jurisdictions updated: %d',
                    $this->legislaturesCreated,
                    $this->jurisdictionsProcessed,
                ));
                $this->line('Use the district viewer auto-seed tools to create districts.');
            }

            $exitCode = self::SUCCESS;
        }

        // Stamp the canonical completion record on the singleton
        // instance_settings row so both invocation paths agree on when
        // apportionment finished:
        //   - queued (button) from JurisdictionController::acceptMaps
        //   - synchronous (wizard) from SetupController, if ever re-added
        //
        // The UI at /jurisdictions/earth-0-earth and the wizard's
        // Step-4 confirm page both watch apportionment_completed_at
        // to flip from "running…" → "completed".
        //
        // SETUP-SCOPED (WI-7): only when --stamp-instance is passed. The
        // activation engine (ActivationService) is now a second caller of
        // this command; dev activations must never rewrite setup-wizard
        // state, so the stamp is opt-in and only the setup path passes it.
        if (! $dryRun && $exitCode === self::SUCCESS && (bool) $this->option('stamp-instance')) {
            $logSummary = sprintf(
                'Apportionment %s — legislatures created/updated: %d, jurisdictions touched: %d. Scope: %s.',
                now()->toIso8601String(),
                $this->legislaturesCreated,
                $this->jurisdictionsProcessed,
                $targetSlug !== '' ? "single jurisdiction '{$targetSlug}'" : "all parents up to ADM{$admMax}",
            );

            DB::table('instance_settings')
                ->whereNull('deleted_at')
                ->update([
                    'apportionment_completed_at' => now(),
                    'apportionment_log'          => $logSummary,
                    'updated_at'                 => now(),
                ]);
        }

        return $exitCode;
    }

    // -------------------------------------------------------------------------
    // Level sweep
    // -------------------------------------------------------------------------

    private function processLevel(int $parentAdmLevel, bool $dryRun, bool $parentsOnly = false): void
    {
        $parentCount = (int) (DB::selectOne("
            SELECT COUNT(DISTINCT parent_id) AS cnt
            FROM jurisdictions
            WHERE deleted_at IS NULL
              AND parent_id IN (
                SELECT id FROM jurisdictions
                WHERE adm_level = ? AND deleted_at IS NULL
              )
        ", [$parentAdmLevel])?->cnt ?? 0);

        // TRUE ALL SCALE (operator revision 2026-07-18) lives in the
        // autoscale orchestrator: childless leaves are seeded SET-BASED
        // there, so this per-row command keeps the historical skip for
        // uniformly-childless levels (a bare invocation walking 700k adm6
        // villages per-row would take days for nothing). --parents-only is
        // the orchestrator's Phase-A entry.
        if ($parentCount === 0 && $parentAdmLevel > 0) {
            return;
        }

        $this->line("ADM{$parentAdmLevel} parents ({$parentCount} with children)…");
        $bar = $this->output->createProgressBar($parentCount);
        $bar->start();

        $query = DB::table('jurisdictions')
            ->where('adm_level', $parentAdmLevel)
            ->whereNull('deleted_at');

        if ($parentsOnly) {
            // Childless rows are the autoscale orchestrator's job (one
            // set-based INSERT…SELECT seeds all ~903k leaf councils); a
            // per-row loop over them here would take days.
            $query->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('jurisdictions as c')
                    ->whereColumn('c.parent_id', 'jurisdictions.id')
                    ->whereNull('c.deleted_at');
            });
        }

        $query->orderBy('id')
            ->chunkById(200, function ($parents) use ($dryRun, $bar) {
                foreach ($parents as $parent) {
                    $this->processParent($parent, $dryRun);
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine();
    }

    // -------------------------------------------------------------------------
    // Single-jurisdiction mode
    // -------------------------------------------------------------------------

    private function seedSingleJurisdiction(string $slugOrUuid, bool $dryRun): int
    {
        $isUuid = (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $slugOrUuid
        );

        $parent = DB::table('jurisdictions')
            ->where(function ($q) use ($slugOrUuid, $isUuid) {
                $q->where('slug', $slugOrUuid);
                if ($isUuid) {
                    $q->orWhere('id', $slugOrUuid);
                }
            })
            ->whereNull('deleted_at')
            ->first();

        if (! $parent) {
            $this->error("Jurisdiction not found: {$slugOrUuid}");
            return self::FAILURE;
        }

        $this->info("Targeting: {$parent->name} (ADM{$parent->adm_level})");
        $this->processParent($parent, $dryRun);

        if (! $dryRun) {
            $this->info(sprintf(
                'Done. Legislatures: %d  Jurisdictions updated: %d',
                $this->legislaturesCreated,
                $this->jurisdictionsProcessed,
            ));
        }

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Core per-parent processing — legislature sizing only, no district creation
    // -------------------------------------------------------------------------

    private function processParent(object $parent, bool $dryRun): void
    {
        $children = DB::table('jurisdictions')
            ->where('parent_id', $parent->id)
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'population']);

        if ($children->isEmpty()) {
            // Leaf jurisdiction: same law as parents (cycle-2 ruling
            // 2026-07-19 — floor clamp only; a too-large leaf legislature
            // gets line-split districts, never truncation). No districts here.
            $leafSeats = ConstitutionalDefaults::sizeFromPopulation((float) $parent->population, $parent->id);

            if ($dryRun) {
                $this->line("  {$parent->name}: leaf, seats={$leafSeats}");
                return;
            }

            $this->upsertLegislature($parent->id, $leafSeats, 0);
            $this->jurisdictionsProcessed++;
            return;
        }

        // Level-local sizing: sum(children pops) → ConstitutionalDefaults (cube_root law in v1).
        $sumChildrenPop = (float) $children->sum('population');
        $totalSeats     = ConstitutionalDefaults::sizeFromPopulation($sumChildrenPop, $parent->id);
        $quota          = $sumChildrenPop > 0 ? $sumChildrenPop / $totalSeats : 1.0;

        // THE TYPE B LADDER (cycle-2 ruling 2026-07-19): Type B — equal
        // representation of the direct constituents — is bound by the same
        // sizing rule as Type A: its total may not exceed the lawful
        // legislature size (Type A). Each constituent contributes rep_floor
        // seats (tiny constituents, pop ≤ 5, contribute min(pop, rep_floor);
        // a zero-population space seats nobody), starting from the
        // constitutional type_b_seats_per_child and descending 4 → 3 → 2
        // until the bound holds. Still over at 2 → keep the floor-2 value,
        // FLAG for the deferred Type B districting. ActivationService calls
        // the same ladder — the two mass paths mint identical chambers.
        $startingRep = (int) (DB::table('constitutional_settings')
            ->where('jurisdiction_id', $parent->id)
            ->value('type_b_seats_per_child') ?? 5);
        $ladder = \App\Services\Legislature\TypeBSeatLadder::apportion(
            $totalSeats,
            $children->pluck('population')->map(fn ($p) => (int) $p)->all(),
            $startingRep,
        );
        $totalTypeB = $ladder['seats'];

        if ($dryRun) {
            foreach ($children as $child) {
                $entitlement = $quota > 0 ? ((float) $child->population / $quota) : 5.0;
                $this->line(sprintf(
                    '  %s: pop=%d ent=%.2f (%s)',
                    $child->name, $child->population, $entitlement, $this->classify($entitlement, $parent->id)
                ));
            }
            return;
        }

        $this->upsertLegislature($parent->id, $totalSeats, $totalTypeB, $ladder['rep_floor'], $ladder['needs_districting']);

        // Seat allocations to individual districts (and the per-district members
        // they contain) are written exclusively by the viewer's auto-composite
        // tools (runAutoCompositeForScope), which seat each drawn district by
        // NEAREST rounding (operator seating law, 2026-07-13) and persist on
        // `legislature_districts.seats`. This command only sizes the
        // legislature as a whole.
        $this->jurisdictionsProcessed += $children->count();
    }

    // -------------------------------------------------------------------------
    // Classification helper
    // -------------------------------------------------------------------------

    private function classify(float $entitlement, ?string $jurisdictionId = null): string
    {
        $floor   = ConstitutionalDefaults::floor($jurisdictionId);
        $ceiling = ConstitutionalDefaults::ceiling($jurisdictionId);
        if ($entitlement < (float) $floor) return 'below_floor';
        if ($entitlement <= (float) $ceiling) return 'mid';
        return 'giant';
    }

    // -------------------------------------------------------------------------
    // Legislature upsert
    // -------------------------------------------------------------------------

    private function upsertLegislature(
        string $jurisdictionId,
        int $typeASeats,
        int $typeBSeats,
        ?int $typeBRepFloor = null,
        bool $typeBNeedsDistricting = false,
    ): string {
        $totalSeats = $typeASeats + $typeBSeats;
        $quorum     = max(3, (int) ceil($totalSeats / 2));

        $existing = DB::table('legislatures')
            ->where('jurisdiction_id', $jurisdictionId)
            ->whereNull('deleted_at')
            ->first(['id']);

        if ($existing) {
            DB::table('legislatures')
                ->where('id', $existing->id)
                ->update([
                    'total_seats'              => $totalSeats,
                    'type_a_seats'             => $typeASeats,
                    'type_b_seats'             => $typeBSeats,
                    'type_b_rep_floor'         => $typeBRepFloor,
                    'type_b_needs_districting' => $typeBNeedsDistricting,
                    'quorum_required'          => $quorum,
                    'updated_at'               => now(),
                ]);
            return $existing->id;
        }

        $id = (string) Str::uuid();
        DB::table('legislatures')->insert([
            'id'                       => $id,
            'jurisdiction_id'          => $jurisdictionId,
            'term_number'              => 1,
            'status'                   => 'forming',
            'total_seats'              => $totalSeats,
            'type_a_seats'             => $typeASeats,
            'type_b_seats'             => $typeBSeats,
            'type_b_rep_floor'         => $typeBRepFloor,
            'type_b_needs_districting' => $typeBNeedsDistricting,
            'quorum_required'          => $quorum,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);
        $this->legislaturesCreated++;
        return $id;
    }

    // -------------------------------------------------------------------------
    // Reset — hard-delete legislatures (not jurisdictions or districts)
    // -------------------------------------------------------------------------

    private function resetData(): void
    {
        $this->warn('Resetting legislatures…');
        DB::table('legislatures')->delete();
        $this->info('Reset complete. District-level seat allocations live on legislature_districts.seats — use "Clear — entire legislature" in the district browser to wipe districts.');
    }
}
