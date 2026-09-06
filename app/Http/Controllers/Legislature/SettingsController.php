<?php

namespace App\Http\Controllers\Legislature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Legislature\Concerns\ResolvesChamber;
use App\Models\ConstitutionalSettings;
use App\Models\Legislature;
use App\Models\SettingChange;
use App\Services\ConstitutionalValidator;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
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

    public function __construct(private readonly ConstitutionalEngine $engine)
    {
    }

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
        // Wave 6 (item 7) — the structural keys the engine reads but no act
        // could set: the Type B seats per constituent, the activation curve
        // and the critical-population threshold (CLK-06).
        'type_b_seats_per_child',
        'critical_population_threshold',
        'activation_tier_enabled',
        'activation_tier_k',
        'activation_tier_exponent',
        'activation_tier_floor',
        'activation_tier_cap',
        // Phase L (slice L-1) — the monetary levers. Until now the register
        // showed 17 keys and NONE of the economy dials, so a founder set a
        // currency and a stipend at setup and no screen ever showed them
        // again. They are amendable settings like any other and belong here;
        // BillController also builds the F-LEG-031 key dropdown from this
        // list, so omitting them made them undraftable as well as invisible.
        'stipend_enabled',
        'stipend_funding_source',
        'civic_stipend_floor',
        'stipend_bump_cap',
        'pay_node_operator',
        'pay_social_moderator',
        'pay_office_holder',
        'stipend_interval',
        'stipend_period_days',
        'issuance_rate_bps',
        'inflation_target_bps',
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
        'type_b_seats_per_child'            => 'equal seats per constituent · the Type B ladder starts here',
        'critical_population_threshold'     => 'residents that boot a place · CLK-06',
        'activation_tier_enabled'           => 'activation curve on (1) or off (0)',
        'activation_tier_k'                 => 'activation curve k · threshold = clamp(ceil(k · P^(1/exponent)), floor, cap)',
        'activation_tier_exponent'          => 'activation curve exponent',
        'activation_tier_floor'             => 'activation curve resident floor',
        'activation_tier_cap'               => 'activation curve resident cap',
        // Phase L — monetary levers. All dual-door: a chamber cannot vote
        // itself a raise without its constituents' consent.
        'stipend_enabled'                   => 'the civic stipend runs · dual-door',
        'stipend_funding_source'            => 'minted, or drawn from the treasury · dual-door',
        'civic_stipend_floor'               => 'everyone with active residency receives this · dual-door',
        'stipend_bump_cap'                  => 'ceiling on the SUM of role differentials · dual-door',
        'pay_node_operator'                 => 'role differential · dual-door',
        'pay_social_moderator'              => 'role differential · dual-door',
        'pay_office_holder'                 => 'role differential · dual-door',
        'stipend_interval'                  => 'payout cadence · dual-door',
        'stipend_period_days'               => 'sweep period · dual-door',
        'issuance_rate_bps'                 => 'basis points · Art. V §5 · dual-door',
        'inflation_target_bps'              => 'basis points · Art. V §5 · dual-door',
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

    /**
     * F-LEG-031 — propose a constitutional-setting amendment directly from the
     * register (R-C, the walkable amendment door): the register is where you SEE
     * a setting, so it is where you propose changing it. This files the dedicated
     * Amendable Setting Change form through the engine, which INTRODUCES a
     * pre-targeted setting bill (act_type setting_change). The value takes effect
     * only at ENACTMENT — a peg-quorum floor vote, after which EnactmentService
     * writes the setting_changes ledger row, mutates constitutional_settings, and
     * re-derives dependent clocks; the change then appears on /system/amendments.
     * Bounds are enforced pre-vote by the PROTECTED validator, so an out-of-range
     * value refuses here with citation and a rejected=true chain entry — never a
     * silent write. (The per-row "draft the full bill" deep-link into the Bills
     * intro, F-LEG-003 with custom law text, stays as the long-form path.)
     */
    public function amend(Request $request, Legislature $legislature): RedirectResponse
    {
        $validated = $request->validate([
            'setting_key' => ['required', 'string'],
            'value'       => ['required'],
            'title'       => ['nullable', 'string'],
        ]);

        $value = $validated['value'];

        try {
            $this->engine->file('F-LEG-031', $request->user(), [
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'setting_key'     => $validated['setting_key'],
                // Same coercion the Bills path uses: a numeric string becomes a
                // number so the bounds check compares like with like.
                'value'           => is_numeric($value) ? $value + 0 : $value,
                'title'           => $validated['title'] ?? "Amend {$validated['setting_key']}",
            ]);
        } catch (ConstitutionalViolation $violation) {
            return back()->withErrors([
                'constitution' => $violation->getMessage().' ('.$violation->citation.')',
            ]);
        }

        return back()->with(
            'status',
            "Amendment bill introduced (F-LEG-031) for {$validated['setting_key']} — it takes effect only when the chamber enacts it at a peg-quorum floor vote. Track it on the amendments ledger.",
        );
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
