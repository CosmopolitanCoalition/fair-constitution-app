<?php

namespace App\Http\Controllers\Legislature;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Legislature\Concerns\ResolvesChamber;
use App\Models\ConstitutionalSettings;
use App\Models\Legislature;
use App\Models\SettingChange;
use App\Services\ConstitutionalValidator;
use App\Support\SurfaceMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-C5 — Settings register (PHASE_C_DESIGN_frontend.md §B.11; surface
 * legislature/settings). The 17-key amendable register: resolved value
 * (parent-chain inheritance surfaced honestly), hardened bounds from the
 * PROTECTED validator's SETTING_BOUNDS, enacting-act provenance from
 * setting_changes, per-row propose-change deep-link into the Bills intro
 * (pre-targeted F-LEG-031 path), and the changes-history table — the
 * Phase C exit-criterion receipt ("60 → 48 · Act … · dependent clocks
 * re-derived").
 *
 * Public read; zero writes on this surface — proposals are BILLS.
 */
class SettingsController extends Controller
{
    use ResolvesChamber;

    /**
     * The 17 amendable keys, in the register's display order (mockup
     * legislature/settings.html SETTINGS[]). Values resolve through the
     * jurisdiction chain; bounds come from ConstitutionalValidator.
     */
    public const REGISTER_KEYS = [
        'election_interval_months',
        'voting_method',
        'legislature_min_seats',
        'legislature_max_seats',
        'special_election_min_days',
        'special_election_max_days',
        'supermajority_numerator',
        'supermajority_denominator',
        'max_days_between_meetings',
        'emergency_powers_max_days',
        'civil_appointment_years',
        'judicial_appointment_years',
        'residency_confirmation_days',
        'initiative_petition_threshold_pct',
        'judiciary_is_elected',
        'worker_rep_min_employees',
        'worker_rep_parity_employees',
    ];

    /** Display meta per key (clock annotations — mockup grammar). */
    private const META = [
        'election_interval_months'          => '5-year default · CLK-01',
        'voting_method'                     => 'PR-STV with Droop quota',
        'legislature_min_seats'             => 'floor 5 · CLK-08',
        'legislature_max_seats'             => 'ceiling 9 — mandatory subdivision above · CLK-07',
        'special_election_min_days'         => 'CLK-04',
        'special_election_max_days'         => 'CLK-04',
        'supermajority_numerator'           => 'with denominator: the supermajority fraction',
        'supermajority_denominator'         => 'ceil(serving × n/d) of all serving',
        'max_days_between_meetings'         => 'CLK-02',
        'emergency_powers_max_days'         => 'CLK-03',
        'civil_appointment_years'           => 'CLK-09',
        'judicial_appointment_years'        => 'CLK-09 · lockstep',
        'residency_confirmation_days'       => 'CLK-05 threshold',
        'initiative_petition_threshold_pct' => '% of jurisdiction population · CLK-17',
        'judiciary_is_elected'              => 'appointed is the default',
        'worker_rep_min_employees'          => 'first worker board seat · CLK-13',
        'worker_rep_parity_employees'       => 'worker/shareholder parity · CLK-14',
    ];

    /** The civil/judicial lockstep pair renders as one joined row. */
    public const LOCKSTEP_KEYS = ['civil_appointment_years', 'judicial_appointment_years'];

    public function show(Request $request, Legislature $legislature): Response
    {
        $legislature->loadMissing('jurisdiction');

        $viewer = $this->viewerMember($legislature, $request->user());
        $jid    = (string) $legislature->jurisdiction_id;

        return Inertia::render('Legislature/Settings', [
            'surface'       => SurfaceMeta::for('legislature/settings'),
            'legislature'   => $this->legislatureProps($legislature),
            'settings'      => $this->register($jid),
            'lockstepKeys'  => self::LOCKSTEP_KEYS,
            'hardenedFloor' => [
                'supermajority_floor'     => 'majority + 1',
                'proportionality_ratchet' => true,
                'note'                    => 'No UI, admin panel, or legislative act can carry an out-of-range value — '
                    . 'the engine rejects pre-vote with citation, and the rejection itself is chained.',
            ],
            'changes'       => $this->changesHistory($jid),
            'can'           => ['propose' => $viewer !== null],
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * One register row per key: resolved value + the chain row that
     * provided it (inherited_from when an ancestor's), hardened bounds,
     * and enacting-act provenance from setting_changes.
     *
     * @return list<array<string, mixed>>
     */
    private function register(string $jurisdictionId): array
    {
        // The jurisdiction chain, self-first, with each level's settings row.
        $chain = DB::select(
            'WITH RECURSIVE chain AS (
                SELECT j.id, j.name, j.parent_id, 0 AS depth
                FROM jurisdictions j
                WHERE j.id = ? AND j.deleted_at IS NULL

                UNION ALL

                SELECT p.id, p.name, p.parent_id, c.depth + 1
                FROM chain c
                JOIN jurisdictions p ON p.id = c.parent_id AND p.deleted_at IS NULL
                WHERE c.depth < 32
            )
            SELECT c.id, c.name, c.depth
            FROM chain c
            ORDER BY c.depth',
            [$jurisdictionId]
        );

        $rowsByJurisdiction = ConstitutionalSettings::query()
            ->whereIn('jurisdiction_id', array_column($chain, 'id'))
            ->get()
            ->keyBy('jurisdiction_id');

        // Latest enacting change per key, anywhere in the chain (nearest
        // level wins, same as resolution).
        $changes = SettingChange::query()
            ->whereIn('jurisdiction_id', array_column($chain, 'id'))
            ->with('law:id,act_number,enacting_bill_id,effective_at')
            ->orderByDesc('applied_at')
            ->get()
            ->groupBy(fn (SettingChange $change) => $change->jurisdiction_id . ':' . $change->setting_key);

        $bounds = ConstitutionalValidator::SETTING_BOUNDS;

        $register = [];

        foreach (self::REGISTER_KEYS as $key) {
            $value     = null;
            $provider  = null;

            foreach ($chain as $level) {
                $row = $rowsByJurisdiction->get((string) $level->id);

                if ($row !== null && $row->{$key} !== null) {
                    $value    = $row->{$key};
                    $provider = $level;
                    break;
                }
            }

            $change = $provider !== null
                ? $changes->get(((string) $provider->id) . ':' . $key)?->first()
                : null;

            $keyBounds = $bounds[$key] ?? null;

            $register[] = [
                'key'            => $key,
                'value'          => $value,
                'meta'           => self::META[$key] ?? null,
                'bounds'         => $keyBounds !== null
                    ? array_intersect_key($keyBounds, array_flip(['min', 'max', 'allowed']))
                    : null,
                'basis'          => $keyBounds['citation'] ?? 'Art. VII',
                'enacted_by'     => $change !== null && $change->law !== null ? [
                    'act_number'   => $change->law->act_number,
                    'href'         => $change->law->enacting_bill_id !== null
                        ? "/bills/{$change->law->enacting_bill_id}"
                        : '/system/public-records',
                    'effective_at' => $change->applied_at?->toIso8601String(),
                ] : null,
                'inherited_from' => $provider !== null && (int) $provider->depth > 0
                    ? ['jurisdiction_name' => $provider->name]
                    : null,
            ];
        }

        return $register;
    }

    /**
     * The exit-criterion receipt: every setting_changes row for this
     * jurisdiction with act + applied date + the TermSync cross-link
     * (where the re-derived CLK-01 timer's real due_at renders).
     *
     * @return list<array<string, mixed>>
     */
    private function changesHistory(string $jurisdictionId): array
    {
        return SettingChange::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->with('law:id,act_number,enacting_bill_id')
            ->orderByDesc('applied_at')
            ->limit(50)
            ->get()
            ->map(fn (SettingChange $change) => [
                'setting_key' => $change->setting_key,
                'old_value'   => $change->old_value,
                'new_value'   => $change->new_value,
                'act_number'  => $change->law?->act_number,
                'bill_href'   => $change->law?->enacting_bill_id !== null
                    ? "/bills/{$change->law->enacting_bill_id}"
                    : null,
                'applied_at'  => $change->applied_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
