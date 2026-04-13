<?php

namespace App\Console\Commands;

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
 * It does NOT create districts and does NOT write type_a_apportioned.
 * Both are the exclusive responsibility of the district viewer's auto-composite
 * tools (LegislatureController::runAutoCompositeForScope), which compute the
 * exact level-local Webster result and write it atomically with district creation.
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
                            {--jurisdiction= : Seed only the direct children of this jurisdiction (slug or UUID)}';

    protected $description = 'Compute cube-root legislature sizes and upsert legislature records (no districts)';

    private int $jurisdictionsProcessed = 0;
    private int $legislaturesCreated    = 0;

    public function handle(): int
    {
        $dryRun     = (bool)   $this->option('dry-run');
        $fresh      = (bool)   $this->option('fresh');
        $admMax     = (int)    $this->option('adm-max');
        $targetSlug = (string) $this->option('jurisdiction');

        $this->info('Apportionment seeder — legislature sizing (cube root law)' . ($dryRun ? ' [DRY RUN]' : ''));

        if ($fresh && ! $dryRun) {
            $this->resetData();
        }

        if ($targetSlug !== '') {
            return $this->seedSingleJurisdiction($targetSlug, $dryRun);
        }

        for ($parentAdmLevel = 0; $parentAdmLevel <= $admMax; $parentAdmLevel++) {
            $this->processLevel($parentAdmLevel, $dryRun);
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

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Level sweep
    // -------------------------------------------------------------------------

    private function processLevel(int $parentAdmLevel, bool $dryRun): void
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

        if ($parentCount === 0 && $parentAdmLevel > 0) {
            return;
        }

        $this->line("ADM{$parentAdmLevel} parents ({$parentCount} with children)…");
        $bar = $this->output->createProgressBar($parentCount);
        $bar->start();

        DB::table('jurisdictions')
            ->where('adm_level', $parentAdmLevel)
            ->whereNull('deleted_at')
            ->orderBy('id')
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
            // Leaf jurisdiction: size a legislature of 5–9 seats, no districts.
            $leafSeats = max(5, min(9, (int) round(pow(max((float) $parent->population, 1), 1.0 / 3.0))));

            if ($dryRun) {
                $this->line("  {$parent->name}: leaf, seats={$leafSeats}");
                return;
            }

            $this->upsertLegislature($parent->id, $leafSeats, 0);
            $this->jurisdictionsProcessed++;
            return;
        }

        // Cube-root law using SUM(children pops) as denominator — level-local.
        $sumChildrenPop = (float) $children->sum('population');
        $totalSeats     = max(5, (int) round(pow(max($sumChildrenPop, 1), 1.0 / 3.0)));
        $quota          = $sumChildrenPop > 0 ? $sumChildrenPop / $totalSeats : 1.0;

        // Equal-house seats per child (type_b) from constitutional settings
        $typeB      = (int) (DB::table('constitutional_settings')
            ->where('jurisdiction_id', $parent->id)
            ->value('type_b_seats_per_child') ?? 5);
        $totalTypeB = $typeB * $children->count();

        if ($dryRun) {
            foreach ($children as $child) {
                $entitlement = $quota > 0 ? ((float) $child->population / $quota) : 5.0;
                $this->line(sprintf(
                    '  %s: pop=%d ent=%.2f (%s)',
                    $child->name, $child->population, $entitlement, $this->classify($entitlement)
                ));
            }
            return;
        }

        $this->upsertLegislature($parent->id, $totalSeats, $totalTypeB);

        // type_a_apportioned is written exclusively by the viewer's auto-composite tools
        // (runAutoCompositeForScope), which compute the exact Webster result at each scope.
        // This command only sizes legislatures — it never writes apportionment columns.
        $this->jurisdictionsProcessed += $children->count();
    }

    // -------------------------------------------------------------------------
    // Classification helper
    // -------------------------------------------------------------------------

    private function classify(float $entitlement): string
    {
        if ($entitlement < 5.0) return 'below_floor';
        if ($entitlement <= 9.0) return 'mid';
        return 'giant';
    }

    // -------------------------------------------------------------------------
    // Legislature upsert
    // -------------------------------------------------------------------------

    private function upsertLegislature(string $jurisdictionId, int $typeASeats, int $typeBSeats): string
    {
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
                    'total_seats'     => $totalSeats,
                    'type_a_seats'    => $typeASeats,
                    'type_b_seats'    => $typeBSeats,
                    'quorum_required' => $quorum,
                    'updated_at'      => now(),
                ]);
            return $existing->id;
        }

        $id = (string) Str::uuid();
        DB::table('legislatures')->insert([
            'id'              => $id,
            'jurisdiction_id' => $jurisdictionId,
            'term_number'     => 1,
            'status'          => 'forming',
            'total_seats'     => $totalSeats,
            'type_a_seats'    => $typeASeats,
            'type_b_seats'    => $typeBSeats,
            'quorum_required' => $quorum,
            'created_at'      => now(),
            'updated_at'      => now(),
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
        $this->info('Reset complete. type_a_apportioned is managed by the viewer tools — use "Clear — entire legislature" in the district browser to wipe apportionment data.');
    }
}
