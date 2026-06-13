<?php

namespace App\Http\Controllers\Judiciary;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ChamberVotePresenter;
use App\Models\Appointment;
use App\Models\ChamberVote;
use App\Models\ConstituentConsent;
use App\Models\JudicialNomination;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\Law;
use App\Models\LawVersion;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Support\SurfaceMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-E2 — Judiciary/Home (PHASE_E_DESIGN_frontend.md §B.1; surface
 * judiciary/judiciary-home).
 *
 *   GET /judiciaries/{judiciary} — the LIVE model of ONE judiciary by type
 *   (appointed default · Art. IV §1) + ESM-18 status (forming → creating →
 *   appointed → conversion_voted → elected | reverted | dissolved). The page
 *   composes: the severity→panel rule table (CLK-16 hard constraint), the
 *   F-LEG-017 creation act + its supermajority VoteTally, the F-LEG-021
 *   confirmation record (consent votes + 10-yr CLK-09 terms), and the
 *   F-LEG-018 conversion to an elected court rendered through the SAME
 *   ConstituentConsentPanel Phase D built for executive conversion (the
 *   Art. IV §3 dual supermajority).
 *
 * PURE READER of engine snapshots — every threshold/required number is read
 * from the chamber_votes / multi_jurisdiction_votes rows through the
 * ChamberVotePresenter; the panel-size table cites the CLK-16 rule, never
 * recomputed here. PUBLIC READ (Art. II §2 — judicial structure is public
 * record); the only "actions" are R-09 deep-links into the bill flow — this
 * page never originates a vote.
 *
 * Sibling of Executive\ExecutiveController; the conversion machinery
 * (processProps / consentVoteSummaries / voteForLaw) is the EXACT executive
 * pattern, reused so the dual-supermajority renders identically.
 */
class JudiciaryController extends Controller
{
    public function __construct(private readonly ChamberVotePresenter $votes) {}

    public function show(Request $request, Judiciary $judiciary): Response
    {
        $judiciary->loadMissing(['jurisdiction', 'sourceLegislature', 'creationLaw', 'conversionLaw']);

        return Inertia::render('Judiciary/Home', [
            'surface' => SurfaceMeta::for('judiciary/judiciary-home'),
            'judiciary' => $this->judiciaryHeader($judiciary),
            'machine' => $this->machine(),
            'panelRule' => $this->panelRule(),
            'creation' => $this->creationProps($judiciary),
            'nominations' => $this->nominationRows($judiciary),
            'conversion' => $this->conversionProps($judiciary),
            'term' => $this->termProps($judiciary),
            'can' => $this->can($request->user(), $judiciary),
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * The judiciary header: type (appointed default · Art. IV §1), ESM-18
     * status, the seated bench count (a row count, never recomputed), the
     * source legislature + jurisdiction links, and the CLK-15 min-judges
     * setting.
     *
     * @return array<string, mixed>
     */
    private function judiciaryHeader(Judiciary $judiciary): array
    {
        $jurisdiction = $judiciary->jurisdiction;
        $legislature = $judiciary->sourceLegislature;

        $judgesOnBench = JudicialSeat::query()
            ->where('judiciary_id', $judiciary->id)
            ->where('status', JudicialSeat::STATUS_SEATED)
            ->count();

        return [
            'id' => (string) $judiciary->id,
            'name' => $judiciary->court_name
                ?? ($jurisdiction !== null ? "{$jurisdiction->name} judiciary" : 'Judiciary'),
            'type' => $judiciary->type,
            'status' => $judiciary->status,
            'judges_on_bench' => $judgesOnBench,
            'min_judges_per_race' => (int) $judiciary->min_judges,
            'jurisdiction' => $jurisdiction !== null ? [
                'id' => (string) $jurisdiction->id,
                'name' => $jurisdiction->name,
                'href' => '/jurisdictions/'.($jurisdiction->slug ?? $jurisdiction->id),
            ] : null,
            'legislature' => $legislature !== null ? [
                'id' => (string) $legislature->id,
                'name' => $legislature->jurisdiction?->name
                    ? "{$legislature->jurisdiction->name} legislature"
                    : 'Source legislature',
                'chamber_href' => "/legislatures/{$legislature->id}/chamber",
            ] : null,
        ];
    }

    /**
     * ESM-18 (the Judiciary lifecycle). The state-machine config carries
     * `case`/`constitutional_challenge` (the per-case ESMs); the judiciary
     * lifecycle is the model's own status enum, emitted here verbatim so the
     * StateStrip highlights the live `status` without recomputation.
     *
     * @return list<string>
     */
    private function machine(): array
    {
        return [
            Judiciary::STATUS_FORMING,
            Judiciary::STATUS_CREATING,
            Judiciary::STATUS_APPOINTED,
            Judiciary::STATUS_CONVERSION_VOTED,
            Judiciary::STATUS_ELECTED,
            // terminal branches
            Judiciary::STATUS_REVERTED,
            Judiciary::STATUS_DISSOLVED,
        ];
    }

    /**
     * The severity→panel rule table (CLK-16 · Art. IV §4). These are the
     * hard-constraint panel-sizing rows — server citations, NOT computed
     * panel sizes (a live case's panel size is the PanelService engine
     * snapshot, rendered on the case surfaces). The min-judges floor is
     * unused here: panel sizing follows severity, not the bench size.
     *
     * @return array{rows: list<array{severity: string, panel: string, rule: string}>}
     */
    private function panelRule(): array
    {
        return [
            'rows' => [
                ['severity' => 'Minor', 'panel' => '3 judges', 'rule' => 'never below 3, always odd · CLK-16'],
                ['severity' => 'Moderate', 'panel' => '3 judges', 'rule' => 'CLK-16'],
                ['severity' => 'Serious', 'panel' => '3–5 judges (+ jury where the accused is entitled)', 'rule' => 'severity-scaled · CLK-16 · Art. IV §4'],
                ['severity' => 'Major constitutional question', 'panel' => 'Full court — all judges', 'rule' => 'CLK-16 · hardened · Art. IV §4'],
            ],
        ];
    }

    /**
     * F-LEG-017 creation act record + the adopting chamber vote rendered as a
     * VoteTally (the supermajority that chartered the court). Null when no
     * creation law is on record yet (a `forming` stub — the page shows the
     * F-LEG-017 reference instead).
     *
     * @return array<string, mixed>|null
     */
    private function creationProps(Judiciary $judiciary): ?array
    {
        $law = $judiciary->creationLaw;

        if ($law === null) {
            return null;
        }

        return [
            'act' => $this->lawChip($law),
            'nomination_mode' => $judiciary->nomination_mode,
            'judge_count' => (int) $judiciary->judge_count,
            'vote' => $this->voteForLaw($law),
        ];
    }

    /**
     * F-LEG-021 confirmation record — one row per nomination, carrying the
     * nominee, who nominated (constituent jurisdiction, or "judicial
     * committee fallback"), the consent-vote summary ("{yes} of {serving}
     * serving" read straight off the closed chamber_vote_tallies — engine
     * snapshot, never recomputed), the consented/not-consented outcome, and
     * the 10-yr CLK-09 term dates off the seated seat.
     *
     * @return list<array<string, mixed>>
     */
    private function nominationRows(Judiciary $judiciary): array
    {
        $nominations = JudicialNomination::query()
            ->where('judiciary_id', $judiciary->id)
            ->with(['nominee:id,name,display_name', 'nominatingJurisdiction:id,name', 'seat', 'appointment'])
            ->get();

        if ($nominations->isEmpty()) {
            return [];
        }

        $voteIds = $nominations
            ->map(fn (JudicialNomination $n) => $n->appointment?->consent_vote_id)
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->all();

        $summaries = $this->consentVoteSummaries($voteIds);

        return $nominations->map(function (JudicialNomination $nomination) use ($summaries) {
            $seat = $nomination->seat;
            $appointment = $nomination->appointment;

            $voteId = $appointment?->consent_vote_id !== null ? (string) $appointment->consent_vote_id : null;

            $nominatedBy = $nomination->mode === JudicialNomination::MODE_COMMITTEE
                ? 'Judicial committee fallback'
                : ($nomination->nominatingJurisdiction?->name ?? '—');

            $consented = $nomination->status === JudicialNomination::STATUS_CONSENTED;

            return [
                'nominee' => [
                    'name' => $nomination->nominee?->display_name
                        ?: ($nomination->nominee?->name ?? 'Unnamed nominee'),
                ],
                'nominated_by' => $nominatedBy,
                'mode' => $nomination->mode,
                'consent' => [
                    'summary' => $voteId !== null ? ($summaries[$voteId] ?? 'consent vote') : '—',
                    'outcome' => $consented ? 'confirmed' : 'not_confirmed',
                ],
                'term' => ($consented && $seat?->term_starts_on !== null) ? [
                    'starts_on' => $seat->term_starts_on?->toDateString(),
                    'ends_on' => $seat->term_ends_on?->toDateString(),
                ] : null,
            ];
        })->values()->all();
    }

    /**
     * F-LEG-018 conversion. When a constituent process exists (or ran), the
     * ConstituentConsentPanel renders it (the chartering chamber's own
     * supermajority PAIRED with the constituent-jurisdiction supermajority —
     * Art. IV §3). When no process exists, the page falls back to the
     * F-LEG-018 reference + deep-link. This controller emits the
     * live/historical process rows verbatim and never originates a vote.
     *
     * @return array<string, mixed>|null
     */
    private function conversionProps(Judiciary $judiciary): ?array
    {
        $law = $judiciary->conversionLaw;

        if ($law === null) {
            return null;
        }

        $process = $judiciary->conversion_process_id !== null
            ? $judiciary->conversionProcess()->with('consents')->first()
            : null;

        return [
            'subjectLabel' => 'Conversion to an elected judiciary',
            'act' => $this->lawChip($law),
            'legislatureVote' => $this->voteForLaw($law),
            'process' => $process !== null ? $this->processProps($process) : null,
        ];
    }

    /**
     * ConstituentConsentPanel `process` contract — every number is the
     * multi_jurisdiction_votes engine snapshot (`required` = the engine's
     * ceil(total × 2/3), NEVER recomputed here). Byte-for-byte the executive
     * processProps.
     *
     * @return array<string, mixed>
     */
    private function processProps($process): array
    {
        $jurisdictionIds = $process->consents->pluck('jurisdiction_id')->all();

        $names = $jurisdictionIds === []
            ? collect()
            : DB::table('jurisdictions')
                ->whereIn('id', $jurisdictionIds)
                ->pluck('name', 'id');

        $voteSummaries = $this->consentVoteSummaries(
            $process->consents->pluck('chamber_vote_id')->filter()->map(fn ($id) => (string) $id)->all()
        );

        return [
            'id' => (string) $process->id,
            'kind' => $process->kind,
            'status' => $process->status,
            'total' => (int) $process->constituent_total,
            'required' => (int) $process->required,
            'yes' => (int) $process->yes_count,
            'no' => (int) $process->no_count,
            'pending' => (int) $process->constituent_total - (int) $process->yes_count - (int) $process->no_count,
            'closes_at' => $process->closes_at?->toDateString(),
            'consents' => $process->consents->map(function (ConstituentConsent $consent) use ($names, $voteSummaries) {
                $voteId = $consent->chamber_vote_id !== null ? (string) $consent->chamber_vote_id : null;

                $voteHref = $consent->legislature_id !== null
                    ? "/legislatures/{$consent->legislature_id}/chamber"
                    : null;

                return [
                    'jurisdiction' => [
                        'id' => (string) $consent->jurisdiction_id,
                        'name' => $names[$consent->jurisdiction_id] ?? '—',
                    ],
                    'result' => $consent->result,
                    'chamber_vote' => $voteId !== null && $voteHref !== null ? [
                        'href' => $voteHref,
                        'summary' => $voteSummaries[$voteId] ?? 'chamber vote',
                    ] : null,
                    'decided_at' => $consent->decided_at?->toDateString(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * "{yes} of {serving} serving" summaries for each chamber vote, read off
     * the closed chamber_vote_tallies (LANE_ALL) — engine snapshots, never
     * recomputed. Shared by the F-LEG-021 confirmation table and the
     * F-LEG-018 constituent-consent panel.
     *
     * @param  list<string>  $voteIds
     * @return array<string, string>
     */
    private function consentVoteSummaries(array $voteIds): array
    {
        if ($voteIds === []) {
            return [];
        }

        return ChamberVote::query()
            ->whereIn('id', $voteIds)
            ->with('tallies')
            ->get()
            ->mapWithKeys(function (ChamberVote $vote) {
                $tally = $vote->tallies->firstWhere('lane', 'all') ?? $vote->tallies->first();

                $yes = (int) ($tally?->yes ?? 0);
                $serving = (int) ($tally?->serving ?? $vote->serving_snapshot ?? 0);

                return [(string) $vote->id => "{$yes} of {$serving} serving"];
            })
            ->all();
    }

    /**
     * Resolve the chamber vote that ENACTED a direct-adoption law (its v1
     * LawVersion soft-refs the chamber_votes row) and present it as a
     * VoteTally. Null when the link cannot be resolved (the card then shows
     * the act record without the meter). Identical to the executive resolver.
     *
     * @return array<string, mixed>|null
     */
    private function voteForLaw(Law $law): ?array
    {
        $version = LawVersion::query()
            ->where('law_id', $law->id)
            ->where('source_ref_type', 'chamber_vote')
            ->orderBy('version_no')
            ->first();

        if ($version === null || $version->source_ref_id === null) {
            return null;
        }

        $vote = ChamberVote::query()->with('tallies')->find($version->source_ref_id);

        return $vote !== null ? $this->votes->tallyProps($vote) : null;
    }

    /** @return array<string, mixed> */
    private function lawChip(Law $law): array
    {
        return [
            'act_number' => $law->act_number,
            'href' => $law->enacting_bill_id !== null
                ? "/bills/{$law->enacting_bill_id}"
                : '/system/public-records',
            'enacted_at' => $law->enacted_at?->toDateString(),
            'effective_on' => $law->effective_at?->toDateString(),
        ];
    }

    /**
     * Term-lockstep card (CLK-09 · CLK-10). 10-year judicial appointment,
     * lockstep with civil appointments (Art. IV §1; Art. II §9). The value
     * is the per-court display snapshot; the AUTHORITATIVE length resolves
     * through SettingsResolver at seating (judicial_appointment_years).
     *
     * @return array<string, mixed>
     */
    private function termProps(Judiciary $judiciary): array
    {
        return [
            'years' => (int) ($judiciary->term_years ?? 10),
            'clk' => 'CLK-09',
            'civilLockstep' => 'CLK-10',
            'amendable' => true,
        ];
    }

    /**
     * `can.*` — R-09 of the SOURCE legislature drives the deep-links (not
     * POSTs here). Resolved directly: the viewer holds a current
     * legislature_members row in the court's source legislature (or, for a
     * `forming` stub, the jurisdiction's own legislature). Creation is
     * proposable while the court is `forming`; conversion while `appointed`.
     *
     * @return array{proposeCreationBill: bool, proposeConversionBill: bool}
     */
    private function can($user, Judiciary $judiciary): array
    {
        if ($user === null) {
            return ['proposeCreationBill' => false, 'proposeConversionBill' => false];
        }

        $legislatureId = $judiciary->source_legislature_id
            ?? Legislature::query()
                ->where('jurisdiction_id', $judiciary->jurisdiction_id)
                ->where('status', '!=', Legislature::STATUS_DISSOLVED)
                ->value('id');

        $isLegislator = $legislatureId !== null && LegislatureMember::query()
            ->where('legislature_id', $legislatureId)
            ->where('user_id', $user->getKey())
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->exists();

        return [
            'proposeCreationBill' => $isLegislator && $judiciary->status === Judiciary::STATUS_FORMING,
            'proposeConversionBill' => $isLegislator && $judiciary->status === Judiciary::STATUS_APPOINTED,
        ];
    }
}
