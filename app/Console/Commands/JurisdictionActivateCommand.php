<?php

namespace App\Console\Commands;

use App\Models\Jurisdiction;
use App\Models\JurisdictionActivation;
use App\Services\ActivationService;
use App\Services\SettingsResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * WI-7 — dev/operator entry into the activation engine (WF-JUR-01).
 *
 *   php artisan jurisdiction:activate smr-1-san-marino --force
 *
 * Without --force the CLK-06 gate applies: the jurisdiction must have at
 * least the resolved critical-population threshold of ACTIVE verified
 * residents. --force bypasses the population check (dev bootstrap), going
 * straight to the pipeline.
 */
class JurisdictionActivateCommand extends Command
{
    protected $signature = 'jurisdiction:activate
                            {slug : Jurisdiction slug or UUID}
                            {--force : Bypass the CLK-06 critical-population check}';

    protected $description = 'Run the WF-JUR-01 activation pipeline for a jurisdiction (legislature sizing + institution stubs)';

    public function handle(ActivationService $activation, SettingsResolver $settings): int
    {
        $slugOrUuid = (string) $this->argument('slug');
        $force      = (bool) $this->option('force');

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
            'Activating %s (%s, ADM%d, population %s)%s',
            $jurisdiction->name,
            $jurisdiction->slug,
            (int) $jurisdiction->adm_level,
            number_format((int) ($jurisdiction->population ?? 0)),
            $force ? ' [FORCED — population check bypassed]' : ''
        ));

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

        $legislature = DB::table('legislatures as l')
            ->where('l.jurisdiction_id', $jurisdiction->id)
            ->whereNull('l.deleted_at')
            ->first(['l.id', 'l.type_a_seats', 'l.type_b_seats', 'l.total_seats', 'l.status', 'l.quorum_required']);

        $this->newLine();
        $this->table(
            ['field', 'value'],
            [
                ['activation state',       $row->state],
                ['activated_at',           (string) $row->activated_at],
                ['legislature_id',         $legislature->id ?? '—'],
                ['type_a_seats',           $legislature->type_a_seats ?? '—'],
                ['type_b_seats (Art. V §3)', $legislature->type_b_seats ?? '—'],
                ['total_seats',            $legislature->total_seats ?? '—'],
                ['quorum_required',        $legislature->quorum_required ?? '—'],
                ['legislature status',     $legislature->status ?? '—'],
            ]
        );

        if ($row->state === JurisdictionActivation::STATE_SELF_GOVERNING && $legislature !== null) {
            $this->info("Self-governing. Browse: /legislatures/{$legislature->id}");

            return self::SUCCESS;
        }

        $this->warn('Activation did not reach self_governing — inspect jurisdiction_activations.notes and audit_log.');

        return self::FAILURE;
    }
}
