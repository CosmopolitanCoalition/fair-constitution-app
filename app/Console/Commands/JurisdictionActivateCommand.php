<?php

namespace App\Console\Commands;

use App\Models\Jurisdiction;
use App\Models\JurisdictionActivation;
use App\Services\ActivationService;
use App\Services\SettingsResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * WI-7 — dev/operator entry into the activation engine (WF-JUR-01).
 *
 *   php artisan jurisdiction:activate smr-1-san-marino --force
 *   php artisan jurisdiction:activate smr-2-montegiardino --replan
 *
 * Without --force the CLK-06 gate applies: the jurisdiction must have at
 * least the resolved critical-population threshold of ACTIVE verified
 * residents. --force bypasses the population check (dev bootstrap), going
 * straight to the pipeline.
 *
 * --replan (WI-B7) re-enters activation step 3.5 for an ALREADY-activated
 * jurisdiction whose legislature is still memberless + forming: re-runs
 * the sizing posture (leaf ceiling clamp / initial district map), the
 * bootstrap election board, and first-election scheduling. Idempotent;
 * refuses seated chambers.
 */
class JurisdictionActivateCommand extends Command
{
    protected $signature = 'jurisdiction:activate
                            {slug : Jurisdiction slug or UUID}
                            {--force : Bypass the CLK-06 critical-population check}
                            {--replan : Re-run step 3.5 (sizing clamp / initial map / board / first election) on an already-activated jurisdiction}';

    protected $description = 'Run the WF-JUR-01 activation pipeline for a jurisdiction (legislature sizing + institution stubs + bootstrap elections)';

    public function handle(ActivationService $activation, SettingsResolver $settings): int
    {
        $slugOrUuid = (string) $this->argument('slug');
        $force      = (bool) $this->option('force');
        $replan     = (bool) $this->option('replan');

        $isUuid = (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $slugOrUuid
        );

        $jurisdiction = Jurisdiction::query()
            ->where(function ($q) use ($slugOrUuid, $isUuid) {
                $q->where('slug', $slugOrUuid);
                if ($isUuid) {
                    $q->orWhere('id', $slugOrUuid);
                }
            })
            ->first();

        if ($jurisdiction === null) {
            $this->error("Jurisdiction not found: {$slugOrUuid}");

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s %s (%s, ADM%d, population %s)%s',
            $replan ? 'Re-planning' : 'Activating',
            $jurisdiction->name,
            $jurisdiction->slug,
            (int) $jurisdiction->adm_level,
            number_format((int) ($jurisdiction->population ?? 0)),
            $force ? ' [FORCED — population check bypassed]' : ''
        ));

        if ($replan) {
            try {
                $row = $activation->replan($jurisdiction);
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            return $this->report($jurisdiction, $row);
        }

        $verifiedResidents = (int) DB::table('residency_confirmations')
            ->where('jurisdiction_id', $jurisdiction->id)
            ->where('is_active', true)
            ->count();

        $threshold = $settings->resolveInt(
            $jurisdiction->id,
            'critical_population_threshold',
            (int) config('cga.critical_population_default', 1)
        );

        if (! $force) {
            if ($verifiedResidents < $threshold) {
                $this->error(sprintf(
                    'CLK-06 not met: %d active verified resident(s), threshold %d. Use --force to bypass (dev only).',
                    $verifiedResidents,
                    $threshold
                ));

                return self::FAILURE;
            }

            $activation->onCriticalPopulation($jurisdiction->id, $verifiedResidents, $threshold);
        }

        $row = $activation->activate($jurisdiction);

        return $this->report($jurisdiction, $row);
    }

    // -------------------------------------------------------------------------

    private function report(Jurisdiction $jurisdiction, JurisdictionActivation $row): int
    {
        $legislature = DB::table('legislatures as l')
            ->where('l.jurisdiction_id', $jurisdiction->id)
            ->whereNull('l.deleted_at')
            ->first(['l.id', 'l.type_a_seats', 'l.type_b_seats', 'l.total_seats', 'l.status', 'l.quorum_required']);

        $board = $legislature === null ? null : DB::table('election_boards')
            ->where('jurisdiction_id', $jurisdiction->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first(['id', 'is_bootstrap']);

        $activeMap = $legislature === null ? null : DB::table('legislature_district_maps')
            ->where('legislature_id', $legislature->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first(['id', 'name']);

        $mapSummary = '—';
        if ($activeMap !== null) {
            $seats = DB::table('legislature_districts')
                ->where('map_id', $activeMap->id)
                ->whereNull('deleted_at')
                ->orderByDesc('seats')
                ->pluck('seats')
                ->all();
            $mapSummary = sprintf(
                '%s — %d districts [%s] = %d seats',
                $activeMap->name,
                count($seats),
                implode(', ', $seats),
                array_sum($seats)
            );
        }

        $election = $legislature === null ? null : DB::table('elections')
            ->where('legislature_id', $legislature->id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->first(['id', 'kind', 'status', 'approval_opens_at', 'finalist_cutoff_at', 'ranked_opens_at', 'ranked_closes_at']);

        $races = $election === null ? collect() : DB::table('election_races')
            ->where('election_id', $election->id)
            ->whereNull('deleted_at')
            ->orderBy('seat_kind')
            ->orderByDesc('seats')
            ->get(['seat_kind', 'district_id', 'seats', 'finalist_count']);

        $this->newLine();
        $this->table(
            ['field', 'value'],
            [
                ['activation state',         $row->state],
                ['activated_at',             (string) $row->activated_at],
                ['legislature_id',           $legislature->id ?? '—'],
                ['type_a_seats',             $legislature->type_a_seats ?? '—'],
                ['type_b_seats (Art. V §3)', $legislature->type_b_seats ?? '—'],
                ['total_seats',              $legislature->total_seats ?? '—'],
                ['quorum_required',          $legislature->quorum_required ?? '—'],
                ['legislature status',       $legislature->status ?? '—'],
                ['election board',           $board === null ? '—' : ($board->id . ($board->is_bootstrap ? ' (bootstrap — temporary · replacement queued)' : ''))],
                ['active district map',      $mapSummary],
                ['election',                 $election === null ? '—' : "{$election->id} ({$election->kind}, {$election->status})"],
                ['  approval_opens_at',      $election->approval_opens_at ?? '—'],
                ['  finalist_cutoff_at',     $election->finalist_cutoff_at ?? '—'],
                ['  ranked window',          $election === null ? '—' : "{$election->ranked_opens_at} → {$election->ranked_closes_at}"],
                ['races',                    $races->isEmpty() ? '—' : $races->map(fn ($r) => sprintf(
                    '%s %s: %d seats (X=%d)',
                    $r->seat_kind,
                    $r->district_id === null ? 'at-large' : 'district ' . substr((string) $r->district_id, 0, 8),
                    (int) $r->seats,
                    (int) $r->finalist_count
                ))->implode("\n")],
            ]
        );

        if (($row->notes['bootstrap_election_blocked'] ?? null) !== null) {
            $this->warn('Bootstrap election BLOCKED: ' . $row->notes['bootstrap_election_blocked']['reason']);
        }

        if ($row->state === JurisdictionActivation::STATE_SELF_GOVERNING && $legislature !== null) {
            $this->info("Self-governing. Browse: /legislatures/{$legislature->id}");

            return self::SUCCESS;
        }

        $this->warn('Activation did not reach self_governing — inspect jurisdiction_activations.notes and audit_log.');

        return self::FAILURE;
    }
}
