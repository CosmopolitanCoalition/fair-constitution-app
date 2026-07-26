<?php

namespace App\Http\Controllers;

use App\Services\ActivationService;
use App\Services\ActivationTierService;
use App\Services\LegitimacyService;
use App\Services\SettingsResolver;
use App\Support\SurfaceMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Phase I — the Reach surface (`social/legitimacy`).
 *
 * Renders the nightly enrolment gauge and the activation-tier console for one
 * place. Read-only: there is no action on this page, by design. Reach is a
 * gauge and never a lever (CI-1), so a surface that could DO something with it
 * would be the first step toward the thing the charter forbids.
 *
 * ⚑ Every number this controller emits already passed through the nightly
 * suppression decision. It never counts residents live — a live COUNT(*) here
 * would hand an observer sub-minute resolution on a number the snapshot
 * deliberately publishes once a day, and would let them defeat k-anonymity by
 * differencing far faster than the table allows. The gauge reads
 * `legitimacy_snapshots` or it shows nothing.
 */
class ReachController extends Controller
{
    public function index(Request $request, SettingsResolver $settings, ActivationService $activation)
    {
        $jurisdictionId = $request->query('jurisdiction');

        $jurisdictions = DB::table('jurisdictions')
            ->whereNull('deleted_at')
            ->orderBy('adm_level')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'adm_level'])
            ->map(fn ($j) => [
                'id'        => (string) $j->id,
                'name'      => $j->name,
                'adm_level' => (int) $j->adm_level,
            ])
            ->values()
            ->all();

        $jurisdictionId ??= $jurisdictions[0]['id'] ?? null;

        return Inertia::render('Social/Reach', [
            'surface'       => SurfaceMeta::for('social/legitimacy'),
            'jurisdictions' => $jurisdictions,
            'selected'      => $jurisdictionId,
            'gauge'         => $jurisdictionId ? $this->gauge($jurisdictionId) : null,
            'series'        => $jurisdictionId ? $this->series($jurisdictionId) : [],
            'tier'          => $jurisdictionId ? $this->tier($jurisdictionId, $settings, $activation) : null,
        ]);
    }

    /**
     * The latest published snapshot, exactly as stored. `state` is the
     * contract — the page switches on it and never infers from the numbers.
     */
    private function gauge(string $jurisdictionId): ?array
    {
        $row = DB::table('legitimacy_snapshots')
            ->where('jurisdiction_id', $jurisdictionId)
            ->orderByDesc('as_of_date')
            ->first();

        if ($row === null) {
            // No snapshot yet is NOT zero reach — it is "not measured yet".
            return ['state' => LegitimacyService::STATE_UNMEASURABLE, 'as_of_date' => null,
                    'verified_residents' => null, 'population_estimate' => null,
                    'ratio_micro' => null, 'population_provenance' => null, 'population_year' => null];
        }

        return [
            'state'                 => $row->state,
            'as_of_date'            => $row->as_of_date,
            'verified_residents'    => $row->verified_residents !== null ? (int) $row->verified_residents : null,
            'population_estimate'   => $row->population_estimate !== null ? (int) $row->population_estimate : null,
            'ratio_micro'           => $row->ratio_micro !== null ? (int) $row->ratio_micro : null,
            'population_provenance' => $row->population_provenance,
            'population_year'       => $row->population_year !== null ? (int) $row->population_year : null,
        ];
    }

    /**
     * The nightly curve. Suppressed nights carry no point at all rather than a
     * zero — a gap is honest, a zero is a lie about the place.
     *
     * @return list<array{as_of_date:string, ratio_micro:?int, suppressed:bool}>
     */
    private function series(string $jurisdictionId): array
    {
        return DB::table('legitimacy_snapshots')
            ->where('jurisdiction_id', $jurisdictionId)
            ->orderBy('as_of_date')
            ->limit(90)
            ->get(['as_of_date', 'ratio_micro', 'suppressed'])
            ->map(fn ($r) => [
                'as_of_date'  => $r->as_of_date,
                'ratio_micro' => $r->ratio_micro !== null ? (int) $r->ratio_micro : null,
                'suppressed'  => (bool) $r->suppressed,
            ])
            ->values()
            ->all();
    }

    /**
     * The activation-tier console: what this place needs before its government
     * may BOOT.
     *
     * ⚑ The "how many more" line is derived from the SUPPRESSED snapshot count,
     * never from a live headcount. Where the gauge is suppressed the page shows
     * the threshold and says the count is hidden — it must not leak by the back
     * door what the gauge just refused to publish.
     */
    private function tier(string $jurisdictionId, SettingsResolver $settings, ActivationService $activation): array
    {
        $j = DB::table('jurisdictions')
            ->where('id', $jurisdictionId)
            ->first(['population', 'name']);

        $population = $j?->population !== null ? (int) $j->population : null;
        $threshold  = $activation->thresholdFor($jurisdictionId, $population, $settings);

        $snapshot = DB::table('legitimacy_snapshots')
            ->where('jurisdiction_id', $jurisdictionId)
            ->orderByDesc('as_of_date')
            ->first(['verified_residents', 'suppressed']);

        $verified = $snapshot?->verified_residents !== null ? (int) $snapshot->verified_residents : null;
        $hidden   = $snapshot === null || (bool) ($snapshot->suppressed ?? true);

        $activationState = DB::table('jurisdiction_activations')
            ->where('jurisdiction_id', $jurisdictionId)
            ->whereNull('deleted_at')
            ->value('state');

        return [
            'enabled'      => (bool) config('cga.activation_tier.enabled', false),
            'threshold'    => $threshold,
            'verified'     => $hidden ? null : $verified,
            'remaining'    => ($hidden || $verified === null) ? null : max(0, $threshold - $verified),
            'count_hidden' => $hidden,
            'state'        => $activationState ?? 'boundary_loaded',
            'params'       => ActivationTierService::clampParams([
                'enabled'  => (bool) config('cga.activation_tier.enabled', false),
                'k'        => (int) config('cga.activation_tier.k', 1),
                'exponent' => (int) config('cga.activation_tier.exponent', 3),
                'floor'    => (int) config('cga.activation_tier.floor', 5),
                'cap'      => (int) config('cga.activation_tier.cap', 9),
            ]),
        ];
    }
}
