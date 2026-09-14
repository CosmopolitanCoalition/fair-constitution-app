<?php

namespace App\Http\Controllers\Jurisdictions;

use App\Http\Controllers\Controller;
use App\Models\BorderSettlement;
use App\Models\DisintermediationProcess;
use App\Models\Jurisdiction;
use App\Models\JurisdictionActivation;
use App\Models\Law;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\RestorationEvent;
use App\Models\UnionProcess;
use App\Models\Verdict;
use App\Services\ActivationService;
use App\Services\Executive\ExecutiveFormationService;
use App\Services\Jurisdictions\BorderSettlementService;
use App\Services\Jurisdictions\DisintermediationService;
use App\Services\Jurisdictions\RestorationService;
use App\Services\Jurisdictions\UnionService;
use App\Services\Legislature\ChamberActService;
use App\Services\SettingsResolver;
use App\Support\CivicPopulation;
use App\Support\LifecycleHistoryPager;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The jurisdiction LIFECYCLE surfaces (Wave 2, V3 synthesis §6): four pages
 * over backends that were complete with zero routes — bootstrap (WF-JUR-01),
 * union formation (F-LEG-029 / WF-JUR-02/03), disintermediation (F-LEG-030 /
 * WF-JUR-04) and restoration (WF-JUR-07). Design contracts:
 * mockups/v3/jurisdictions/{bootstrap,union-formation,disintermediation,
 * restoration}.html.
 *
 * Reads are public (Art. II §2 — anyone may watch the machinery). The two
 * WRITE doors are the chamber PROPOSALS the engine already handles
 * (ChamberActService::proposeUnion / proposeDisintermediation): auth here,
 * the seat requirement resolved in-controller, every constitutional guard in
 * the service/engine — a ConstitutionalViolation renders as a 422 with its
 * citation via the global handler. No client-side simulation anywhere: a
 * page with no live process says so instead of faking one.
 */
class LifecycleController extends Controller
{
    /** GET /jurisdictions/bootstrap — how a place wakes up. */
    public function bootstrap(Request $request, ActivationService $activation, SettingsResolver $settings): Response
    {
        $focus = $this->resolveFocus($request);

        $activationRow = $focus === null ? null : JurisdictionActivation::query()
            ->where('jurisdiction_id', $focus->id)
            ->first();

        $threshold = null;
        if ($focus !== null) {
            $required = $activation->thresholdFor((string) $focus->id, $focus->population !== null ? (int) $focus->population : null, $settings);
            $verified = CivicPopulation::of((string) $focus->id);
            $threshold = [
                'required' => $required,
                'verified' => $verified,
                'met' => $required > 0 && $verified >= $required,
            ];
        }

        return Inertia::render('Jurisdictions/Bootstrap', [
            'surface' => SurfaceMeta::for('jurisdictions/bootstrap'),
            'focus' => $focus === null ? null : [
                'id' => (string) $focus->id,
                'name' => (string) $focus->name,
                'slug' => (string) $focus->slug,
                'lifecycle_status' => $focus->lifecycle_status,
                'population' => $focus->population !== null ? (int) $focus->population : null,
            ],
            'activation' => $activationRow === null ? null : [
                'state' => (string) $activationRow->state,
                'states' => JurisdictionActivation::STATE_ORDER,
                'critical_population_at' => $activationRow->critical_population_at?->toIso8601String(),
                'activated_at' => $activationRow->activated_at?->toIso8601String(),
                'legislature_id' => $activationRow->legislature_id !== null ? (string) $activationRow->legislature_id : null,
                'notes' => (array) ($activationRow->notes ?? []),
            ],
            'threshold' => $threshold,
            'stages' => $focus === null ? [] : $this->wakeUpStages($focus, $activationRow),
            'rollup' => $this->activationRollup(),
        ]);
    }

    /** GET /jurisdictions/union-formation — check differences, agree one rulebook, vote. */
    public function union(Request $request, LifecycleHistoryPager $history): Response
    {
        $paged = $history->page($request, 'union', '/jurisdictions/union-formation',
            UnionProcess::query()->with('constituentProcess.consents'));
        $processes = $paged['page']->getCollection();

        $names = $this->jurisdictionNames($processes->flatMap(function (UnionProcess $p) {
            return array_merge(
                (array) ($p->applicant_jurisdiction_ids ?? []),
                array_filter([(string) ($p->union_jurisdiction_id ?? ''), (string) ($p->resulting_jurisdiction_id ?? '')]),
                $p->constituentProcess?->consents->pluck('jurisdiction_id')->map('strval')->all() ?? [],
            );
        })->unique()->values()->all());

        $seat = $this->currentSeat($request->user()?->getKey());

        return Inertia::render('Jurisdictions/UnionFormation', [
            'surface' => SurfaceMeta::for('jurisdictions/union-formation'),
            'processes' => $processes->map(fn (UnionProcess $p) => $this->unionProcessProps($p, $names, $seat))->values()->all(),
            'door' => $this->unionDoor($seat),
            'pagination' => $paged['pagination'],
        ]);
    }

    /**
     * POST /jurisdictions/union-formation/propose — the F-LEG-029 door. The
     * caller must hold a current seat; the chamber supermajority OPENS the
     * process on adoption (the proposal itself grants nothing).
     */
    public function proposeUnion(Request $request, ChamberActService $acts): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:formation,join,exit'],
            'applicant_ids' => ['required', 'array', 'min:1'],
            'applicant_ids.*' => ['uuid'],
            'union_jurisdiction_id' => ['nullable', 'uuid'],
        ]);

        [$legislature, $member] = $this->requireSeat($request->user()?->getKey());

        // A founding union's constituents-to-be ARE the applicants; a join or
        // exit polls the union's existing direct constituents.
        $constituents = $data['kind'] === UnionProcess::KIND_FORMATION || empty($data['union_jurisdiction_id'])
            ? array_map('strval', $data['applicant_ids'])
            : DB::table('jurisdictions')->where('parent_id', $data['union_jurisdiction_id'])
                ->whereNull('deleted_at')->pluck('id')->map('strval')->all();

        $acts->proposeUnion(
            $legislature,
            $member,
            (string) $data['kind'],
            array_map('strval', $data['applicant_ids']),
            $constituents,
            $data['union_jurisdiction_id'] ?? null,
        );

        return back()->with('status', 'union-proposal-opened');
    }

    /** GET /jurisdictions/disintermediation — removing a middle layer. */
    public function disintermediation(Request $request, LifecycleHistoryPager $history): Response
    {
        $paged = $history->page($request, 'disinter', '/jurisdictions/disintermediation',
            DisintermediationProcess::query()->with(['constituentProcess.consents', 'lawMergeResolutions']));
        $processes = $paged['page']->getCollection();

        $names = $this->jurisdictionNames($processes->flatMap(function (DisintermediationProcess $p) {
            return array_merge(
                [(string) $p->intermediary_jurisdiction_id, (string) $p->encompassing_jurisdiction_id],
                $p->constituentProcess?->consents->pluck('jurisdiction_id')->map('strval')->all() ?? [],
                $p->lawMergeResolutions->pluck('target_jurisdiction_id')->map('strval')->all(),
            );
        })->unique()->values()->all());

        $lawTitles = Law::query()
            ->whereIn('id', $processes->flatMap(fn ($p) => $p->lawMergeResolutions->pluck('law_id'))->unique()->values()->all())
            ->pluck('title', 'id');

        $seat = $this->currentSeat($request->user()?->getKey());

        return Inertia::render('Jurisdictions/Disintermediation', [
            'surface' => SurfaceMeta::for('jurisdictions/disintermediation'),
            'processes' => $processes->map(fn (DisintermediationProcess $p) => $this->disintermediationProps($p, $names, $lawTitles, $seat))->values()->all(),
            'door' => $this->disintermediationDoor($seat),
            'pagination' => $paged['pagination'],
        ]);
    }

    /**
     * POST /jurisdictions/disintermediation/propose — the F-LEG-030 door.
     * The Art. V §8 shape is derived, never picked: the proposing chamber's
     * OWN jurisdiction is the intermediary, its parent the encompassing
     * jurisdiction, its children the constituents.
     */
    public function proposeDisintermediation(Request $request, ChamberActService $acts): RedirectResponse
    {
        [$legislature, $member] = $this->requireSeat($request->user()?->getKey());

        $jurisdiction = Jurisdiction::query()->findOrFail((string) $legislature->jurisdiction_id);
        $constituents = DB::table('jurisdictions')->where('parent_id', $jurisdiction->id)
            ->whereNull('deleted_at')->pluck('id')->map('strval')->all();

        $acts->proposeDisintermediation(
            $legislature,
            $member,
            (string) $jurisdiction->id,
            (string) ($jurisdiction->parent_id ?? ''),
            $constituents,
        );

        return back()->with('status', 'disintermediation-proposal-opened');
    }

    /**
     * GET /federation — "Between governments", the read-only CITIZEN view
     * (ruling §10 item 9: the operator console moved to /operator/federation).
     * Border settlement is the everyday case: the people inside the moving
     * boundary deliberate, vote by 2/3 of ALL affected, and the map updates.
     */
    public function federation(Request $request, LifecycleHistoryPager $history): Response
    {
        $paged = $history->page($request, 'border', '/federation', BorderSettlement::query());
        $settlements = $paged['page']->getCollection();

        $names = $this->jurisdictionNames($settlements->flatMap(fn (BorderSettlement $s) => [
            (string) $s->jurisdiction_a_id,
            (string) $s->jurisdiction_b_id,
        ])->unique()->values()->all());

        return Inertia::render('Jurisdictions/BetweenGovernments', [
            'surface' => SurfaceMeta::for('jurisdictions/federation'),
            'settlements' => $settlements->map(fn (BorderSettlement $s) => [
                'id' => (string) $s->id,
                'a' => $names[(string) $s->jurisdiction_a_id] ?? (string) $s->jurisdiction_a_id,
                'b' => $names[(string) $s->jurisdiction_b_id] ?? (string) $s->jurisdiction_b_id,
                'affected_population' => (int) ($s->affected_population ?? 0),
                'required' => \App\Services\ConstitutionalValidator::supermajority((int) ($s->affected_population ?? 0)),
                'supermajority_met' => (bool) $s->affected_supermajority_met,
                'status' => (string) $s->status,
                'opened_at' => $s->created_at?->toIso8601String(),
            ])->values()->all(),
            'viewer' => $this->viewerSeat($request->user()?->getKey()),
            'pagination' => $paged['pagination'],
        ]);
    }

    /** GET /jurisdictions/restoration — when government is lost. */
    public function restoration(Request $request, LifecycleHistoryPager $history): Response
    {
        $paged = $history->page($request, 'restoration', '/jurisdictions/restoration', RestorationEvent::query());
        $events = $paged['page']->getCollection();

        $names = $this->jurisdictionNames($events->pluck('jurisdiction_id')->map('strval')->unique()->values()->all());

        return Inertia::render('Jurisdictions/Restoration', [
            'surface' => SurfaceMeta::for('jurisdictions/restoration'),
            'events' => $events->map(fn (RestorationEvent $e) => [
                'id' => (string) $e->id,
                'jurisdiction' => $names[(string) $e->jurisdiction_id] ?? (string) $e->jurisdiction_id,
                'condition' => (string) $e->condition,
                'status' => (string) $e->status,
                'judicially_confirmed' => (bool) $e->judicially_confirmed,
                'judicial_finding' => $this->judicialFindingFor($e),
                'review_case_id' => $e->review_case_id !== null ? (string) $e->review_case_id : null,
                'tier' => (int) ($e->tier ?? 0),
                'declared_at' => $e->created_at?->toIso8601String(),
            ])->values()->all(),
            'conditions' => [
                RestorationEvent::CONDITION_COUNTERMANDED,
                RestorationEvent::CONDITION_CAPTURED,
                RestorationEvent::CONDITION_DESTROYED,
            ],
            'viewer' => $this->viewerSeat($request->user()?->getKey()),
            'pagination' => $paged['pagination'],
        ]);
    }

    // ── S2 WRITE DOORS ───────────────────────────────────────────────────────
    // Thin: validate, resolve the seat where the act requires a seated member,
    // call the service, back()->with('status'). The constitutional guards live
    // in the service — a ConstitutionalViolation reaches the 422 handler with
    // its citation verbatim. The doors add one authority check the service
    // cannot make from its arguments alone: the acting chamber must belong to
    // the body the act speaks for (an applicant jurisdiction for a union
    // referendum, an affected sub-jurisdiction for a border referendum, the
    // encompassing chamber for disintermediation consent — the last passed
    // through to the service, which raises the citation). The two CONSENT doors
    // do not write consent:
    // they open a peg-quorum chamber vote in the constituent legislature
    // (ExecutiveFormationService::openConstituentConsentVote — the same
    // constituent_consent votable InstitutionActController::consent opens), so
    // refusal and recovery ride the chamber-vote lifecycle and the process
    // finalizes itself when both meters close (the generic arm's new
    // union_processes / disintermediation_processes branches).

    /** POST /jurisdictions/union-formation/{unionProcess}/applicant-referendum */
    public function unionApplicantReferendum(Request $request, UnionProcess $unionProcess, UnionService $union): RedirectResponse
    {
        $data = $request->validate(['yes_votes' => ['required', 'integer', 'min:0']]);
        [$legislature] = $this->requireSeat($request->user()?->getKey());
        abort_unless($unionProcess->status === UnionProcess::STATUS_OPEN, 422, 'This union process is already resolved.');
        // The applicant referendum is the APPLICANT population's own vote — only
        // a chamber of an applicant jurisdiction may record its outcome.
        abort_unless(
            in_array((string) $legislature->jurisdiction_id, array_map('strval', (array) $unionProcess->applicant_jurisdiction_ids), true),
            403,
            'Only an applicant jurisdiction may record its population referendum.',
        );

        $union->markApplicantReferendum($unionProcess, (int) $data['yes_votes']);

        return back()->with('status', 'union-applicant-referendum-recorded');
    }

    /** POST /jurisdictions/union-formation/{unionProcess}/consent — opens the caller's constituent chamber vote. */
    public function unionConsent(Request $request, UnionProcess $unionProcess, ExecutiveFormationService $formation): RedirectResponse
    {
        [$legislature, $member] = $this->requireSeat($request->user()?->getKey());
        $mjv = $unionProcess->constituentProcess()->first();
        abort_unless($mjv !== null, 422, 'This union process has no constituent vote to consent to.');

        $formation->openConstituentConsentVote($mjv, $legislature, $member);

        return back()->with('status', 'union-consent-opened');
    }

    /** POST /jurisdictions/union-formation/{unionProcess}/finalize */
    public function unionFinalize(Request $request, UnionProcess $unionProcess, UnionService $union): RedirectResponse
    {
        $this->requireSeat($request->user()?->getKey());
        abort_unless($unionProcess->status === UnionProcess::STATUS_OPEN, 422, 'This union process is already resolved.');
        $mjv = $unionProcess->constituentProcess()->first();
        abort_unless($mjv !== null, 422, 'This union process has no constituent vote to close.');

        // maybeFinalize is the not-yet-met-safe path: it applies the union change
        // only when BOTH meters are met and leaves the process OPEN otherwise —
        // never marking it FAILED on a premature press (calling finalize() direct
        // would irreversibly kill a process the constituents are still voting on).
        $union->maybeFinalize($mjv);

        return back()->with('status', $unionProcess->refresh()->status === UnionProcess::STATUS_PASSED
            ? 'union-finalized'
            : 'union-not-yet-ready');
    }

    /** POST /jurisdictions/disintermediation/{disintermediationProcess}/encompassing-consent */
    public function disintermediationEncompassingConsent(Request $request, DisintermediationProcess $disintermediationProcess, DisintermediationService $service): RedirectResponse
    {
        $data = $request->validate(['consented' => ['required', 'boolean']]);
        [$legislature] = $this->requireSeat($request->user()?->getKey());
        abort_unless($disintermediationProcess->status === DisintermediationProcess::STATUS_OPEN, 422, 'This process is already resolved.');

        // Art. V §8: the ENCOMPASSING jurisdiction consents to dissolving its
        // intermediary. The acting chamber is passed to the service, which
        // refuses when it is not the encompassing chamber (the guard lives in
        // the service, mirroring openConstituentConsentVote's constituent gate).
        $service->recordEncompassingConsent($disintermediationProcess, (bool) $data['consented'], null, $legislature);

        return back()->with('status', 'disintermediation-encompassing-consent-recorded');
    }

    /** POST /jurisdictions/disintermediation/{disintermediationProcess}/consent */
    public function disintermediationConsent(Request $request, DisintermediationProcess $disintermediationProcess, ExecutiveFormationService $formation): RedirectResponse
    {
        [$legislature, $member] = $this->requireSeat($request->user()?->getKey());
        $mjv = $disintermediationProcess->constituentProcess()->first();
        abort_unless($mjv !== null, 422, 'This process has no constituent vote to consent to.');

        $formation->openConstituentConsentVote($mjv, $legislature, $member);

        return back()->with('status', 'disintermediation-consent-opened');
    }

    /** POST /jurisdictions/disintermediation/{disintermediationProcess}/finalize */
    public function disintermediationFinalize(Request $request, DisintermediationProcess $disintermediationProcess, DisintermediationService $service): RedirectResponse
    {
        $this->requireSeat($request->user()?->getKey());
        abort_unless($disintermediationProcess->status === DisintermediationProcess::STATUS_OPEN, 422, 'This process is already resolved.');
        $mjv = $disintermediationProcess->constituentProcess()->first();
        abort_unless($mjv !== null, 422, 'This process has no constituent vote to close.');

        // maybeFinalize applies the dissolution only when constituent unanimity
        // AND encompassing consent are both met, leaving the process OPEN
        // otherwise — never marking it FAILED on a premature press.
        $service->maybeFinalize($mjv);

        return back()->with('status', $disintermediationProcess->refresh()->status === DisintermediationProcess::STATUS_MERGED
            ? 'disintermediation-finalized'
            : 'disintermediation-not-yet-ready');
    }

    /** POST /federation/border/propose — a between-governments act (no chamber proposal kind). */
    public function borderPropose(Request $request, BorderSettlementService $service): RedirectResponse
    {
        $data = $request->validate([
            'jurisdiction_a_id' => ['required', 'uuid'],
            'jurisdiction_b_id' => ['required', 'uuid', 'different:jurisdiction_a_id'],
            'affected_ids' => ['required', 'array', 'min:1'],
            'affected_ids.*' => ['uuid'],
        ]);
        $this->requireSeat($request->user()?->getKey());

        $service->open(
            (string) $data['jurisdiction_a_id'],
            (string) $data['jurisdiction_b_id'],
            array_map('strval', $data['affected_ids']),
        );

        return back()->with('status', 'border-opened');
    }

    /** POST /federation/border/{borderSettlement}/referendum */
    public function borderReferendum(Request $request, BorderSettlement $borderSettlement, BorderSettlementService $service): RedirectResponse
    {
        $data = $request->validate(['yes_votes' => ['required', 'integer', 'min:0']]);
        [$legislature] = $this->requireSeat($request->user()?->getKey());
        abort_unless($borderSettlement->status === BorderSettlement::STATUS_OPEN, 422, 'This settlement is already resolved.');
        // The referendum is the AFFECTED area's own vote — only a chamber of an
        // affected sub-jurisdiction may record its outcome.
        abort_unless(
            in_array((string) $legislature->jurisdiction_id, array_map('strval', (array) $borderSettlement->affected_jurisdiction_ids), true),
            403,
            'Only a jurisdiction in the affected area may record its referendum.',
        );

        $service->recordReferendum($borderSettlement, (int) $data['yes_votes']);

        return back()->with('status', 'border-referendum-recorded');
    }

    /** POST /federation/border/{borderSettlement}/adopt */
    public function borderAdopt(Request $request, BorderSettlement $borderSettlement, BorderSettlementService $service): RedirectResponse
    {
        $this->requireSeat($request->user()?->getKey());
        abort_unless($borderSettlement->status === BorderSettlement::STATUS_OPEN, 422, 'This settlement is already resolved.');
        // Refuse a premature press WITHOUT invoking adopt() — adopt() marks the
        // settlement REJECTED when the meter is unmet, which would irreversibly
        // kill a settlement whose affected-area referendum is still pending.
        abort_unless((bool) $borderSettlement->affected_supermajority_met, 422, 'The affected-area supermajority has not been met yet.');

        $service->adopt($borderSettlement);

        return back()->with('status', 'border-adopted');
    }

    /** POST /jurisdictions/restoration/declare */
    public function restorationDeclare(Request $request, RestorationService $service): RedirectResponse
    {
        $data = $request->validate([
            'jurisdiction_id' => ['required', 'uuid'],
            'condition' => ['required', 'in:countermanded,captured,destroyed'],
            'review_case_id' => ['nullable', 'uuid'],
            'evidence' => ['nullable', 'array'],
        ]);
        $this->requireSeat($request->user()?->getKey());

        $service->declare(
            (string) $data['jurisdiction_id'],
            (string) $data['condition'],
            (array) ($data['evidence'] ?? []),
            $data['review_case_id'] ?? null,
        );

        return back()->with('status', 'restoration-declared');
    }

    /** POST /jurisdictions/restoration/{restorationEvent}/confirm — the finding is read from the tied case, never from input. */
    public function restorationConfirm(Request $request, RestorationEvent $restorationEvent, RestorationService $service): RedirectResponse
    {
        $this->requireSeat($request->user()?->getKey());

        $service->confirm($restorationEvent, $this->judicialFindingFor($restorationEvent));

        return back()->with('status', 'restoration-confirmed');
    }

    /** POST /jurisdictions/restoration/{restorationEvent}/tier */
    public function restorationTier(Request $request, RestorationEvent $restorationEvent, RestorationService $service): RedirectResponse
    {
        $data = $request->validate([
            'tier' => ['required', 'integer', 'in:1,2,3'],
            'tier_election_id' => ['nullable', 'uuid'],
        ]);
        $this->requireSeat($request->user()?->getKey());

        $service->advanceTier($restorationEvent, (int) $data['tier'], $data['tier_election_id'] ?? null);

        return back()->with('status', 'restoration-tier-entered');
    }

    /** POST /jurisdictions/restoration/{restorationEvent}/complete */
    public function restorationComplete(Request $request, RestorationEvent $restorationEvent, RestorationService $service): RedirectResponse
    {
        $this->requireSeat($request->user()?->getKey());

        $service->complete($restorationEvent);

        return back()->with('status', 'restoration-completed');
    }

    /** POST /jurisdictions/restoration/{restorationEvent}/abandon */
    public function restorationAbandon(Request $request, RestorationEvent $restorationEvent, RestorationService $service): RedirectResponse
    {
        $this->requireSeat($request->user()?->getKey());

        $service->abandon($restorationEvent);

        return back()->with('status', 'restoration-abandoned');
    }

    /**
     * The judicial constitutional finding for a restoration event, read from
     * the TIED review case (Art. VI §2 — no unilateral declaration): true only
     * when that case carries a verdict FOR THE PETITIONER (the court upheld the
     * challenge that the condition holds). Never read from request input.
     */
    private function judicialFindingFor(RestorationEvent $event): bool
    {
        if ($event->review_case_id === null) {
            return false;
        }

        return Verdict::query()
            ->where('case_id', (string) $event->review_case_id)
            ->where('outcome', Verdict::OUTCOME_FOR_PETITIONER)
            ->exists();
    }

    /** The viewer's seat capability read for the border/restoration surfaces (never a gate). */
    private function viewerSeat(?string $userId): ?array
    {
        return $this->currentSeat($userId);
    }

    // ── Bootstrap helpers ────────────────────────────────────────────────────

    /** The focused jurisdiction: ?jurisdiction=<slug|uuid>, else the most recently advanced activation. */
    private function resolveFocus(Request $request): ?Jurisdiction
    {
        $wanted = trim((string) $request->query('jurisdiction', ''));

        if ($wanted !== '') {
            return Jurisdiction::query()
                ->where(fn ($q) => $q->where('slug', $wanted)->when(
                    preg_match('/^[0-9a-f-]{36}$/i', $wanted) === 1,
                    fn ($qq) => $qq->orWhere('id', $wanted),
                ))
                ->first();
        }

        $latest = JurisdictionActivation::query()->orderByDesc('updated_at')->first();

        return $latest === null ? null : Jurisdiction::query()->find((string) $latest->jurisdiction_id);
    }

    /**
     * The wake-up sequence, derived from OBSERVABLE institutional facts — no
     * materialized step registry exists (the gap matrix's named hole), and a
     * fake one would violate the no-client-simulation doctrine. Seven stages,
     * mockup order; each is done/active/pending from what actually stands.
     *
     * @return list<array{label: string, state: string, detail: string}>
     */
    private function wakeUpStages(Jurisdiction $focus, ?JurisdictionActivation $activation): array
    {
        $state = $activation?->state;
        $order = array_flip(JurisdictionActivation::STATE_ORDER);
        $rank = $state !== null ? ($order[$state] ?? -1) : -1;

        $legislature = Legislature::query()
            ->where('jurisdiction_id', $focus->id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->first();
        $seated = $legislature !== null && LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->exists();
        $board = DB::table('election_boards')
            ->where('jurisdiction_id', $focus->id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->first();
        $hasExecutive = DB::table('executives')->where('jurisdiction_id', $focus->id)->whereNull('deleted_at')->exists();
        $hasJudiciary = DB::table('judiciaries')->where('jurisdiction_id', $focus->id)->whereNull('deleted_at')->exists();
        $electionId = (string) (($activation?->notes['bootstrap_election_id'] ?? '') ?: '');

        $done = [
            $activation !== null,
            $rank >= ($order[JurisdictionActivation::STATE_CRITICAL_POPULATION] ?? 1),
            $board !== null && $electionId !== '',
            $seated,
            $hasExecutive,
            $hasJudiciary,
            $seated && $hasExecutive && $hasJudiciary,
        ];

        $labels = [
            ['System genesis', 'The boundary is loaded and the place is tracked.'],
            ['Population onboarding', 'Verified residents accumulate toward the critical population threshold.'],
            ['First election', 'A bootstrap election board stands and the first general election is called.'],
            ['Legislature constitutes', 'The certified winners take their seats.'],
            ['Executive branch established', 'The legislature delegates or elects its executive.'],
            ['Judiciary established', 'The courts are appointed.'],
            ['Full governance achieved', 'Every branch stands; the place is self-governing.'],
        ];

        $firstPending = null;
        foreach ($done as $i => $ok) {
            if (! $ok) {
                $firstPending = $i;
                break;
            }
        }

        return array_map(fn (int $i) => [
            'label' => $labels[$i][0],
            'detail' => $labels[$i][1],
            'state' => $done[$i] ? 'done' : ($i === $firstPending ? 'active' : 'pending'),
        ], array_keys($labels));
    }

    /** @return array{dormant: int, by_state: array<string, int>} */
    private function activationRollup(): array
    {
        $byState = JurisdictionActivation::query()
            ->select('state', DB::raw('count(*) as n'))
            ->groupBy('state')
            ->pluck('n', 'state')
            ->map(fn ($n) => (int) $n)
            ->all();

        $total = (int) DB::table('jurisdictions')->whereNull('deleted_at')->count();

        return [
            'dormant' => max(0, $total - array_sum($byState)),
            'by_state' => $byState,
        ];
    }

    // ── Union / disintermediation helpers ────────────────────────────────────

    /** @param  array<string, string>  $names */
    private function unionProcessProps(UnionProcess $p, array $names, ?array $seat = null): array
    {
        $mjv = $p->constituentProcess;

        return [
            'id' => (string) $p->id,
            'kind' => (string) $p->kind,
            'status' => (string) $p->status,
            'consentable_by_viewer' => $this->viewerMayConsent($mjv, $p->status, UnionProcess::STATUS_OPEN, $seat),
            'applicants' => array_map(
                fn ($id) => ['id' => (string) $id, 'name' => $names[(string) $id] ?? (string) $id],
                (array) ($p->applicant_jurisdiction_ids ?? []),
            ),
            'union_name' => $p->union_jurisdiction_id !== null ? ($names[(string) $p->union_jurisdiction_id] ?? null) : null,
            'resulting_name' => $p->resulting_jurisdiction_id !== null ? ($names[(string) $p->resulting_jurisdiction_id] ?? null) : null,
            'applicant_supermajority_met' => (bool) $p->applicant_supermajority_met,
            'compatibility_diff' => (array) ($p->compatibility_diff ?? []),
            'constituent_vote' => $mjv === null ? null : [
                'yes' => (int) $mjv->yes_count,
                'no' => (int) $mjv->no_count,
                'required' => (int) $mjv->required,
                'total' => (int) $mjv->constituent_total,
                'status' => (string) $mjv->status,
                'consents' => $mjv->consents->map(fn ($c) => [
                    'jurisdiction' => $names[(string) $c->jurisdiction_id] ?? (string) $c->jurisdiction_id,
                    'result' => (string) $c->result,
                ])->values()->all(),
            ],
            'opened_at' => $p->created_at?->toIso8601String(),
        ];
    }

    /** @param  array<string, string>  $names */
    private function disintermediationProps(DisintermediationProcess $p, array $names, $lawTitles, ?array $seat = null): array
    {
        $mjv = $p->constituentProcess;

        return [
            'id' => (string) $p->id,
            'status' => (string) $p->status,
            'consentable_by_viewer' => $this->viewerMayConsent($mjv, $p->status, DisintermediationProcess::STATUS_OPEN, $seat),
            // The encompassing-consent capability read (never a gate — the
            // service re-checks): the viewer's seated jurisdiction IS the
            // encompassing jurisdiction of this open process.
            'viewer_is_encompassing' => $seat !== null
                && ($seat['jurisdiction_id'] ?? '') !== ''
                && (string) $seat['jurisdiction_id'] === (string) $p->encompassing_jurisdiction_id
                && $p->status === DisintermediationProcess::STATUS_OPEN,
            'intermediary' => $names[(string) $p->intermediary_jurisdiction_id] ?? (string) $p->intermediary_jurisdiction_id,
            'encompassing' => $names[(string) $p->encompassing_jurisdiction_id] ?? (string) $p->encompassing_jurisdiction_id,
            'encompassing_consent' => $p->encompassing_consent,
            'unanimity' => $mjv === null ? null : [
                'yes' => (int) $mjv->yes_count,
                'no' => (int) $mjv->no_count,
                'required' => (int) $mjv->required,
                'total' => (int) $mjv->constituent_total,
                'status' => (string) $mjv->status,
                'consents' => $mjv->consents->map(fn ($c) => [
                    'jurisdiction' => $names[(string) $c->jurisdiction_id] ?? (string) $c->jurisdiction_id,
                    'result' => (string) $c->result,
                ])->values()->all(),
            ],
            // The folded-acts inventory: one row per act per inheriting
            // constituent (Art. V §8 — the CONSTITUENTS inherit, ruled
            // 2026-07-28), populated once the process reaches MERGED.
            'folded_acts' => $p->lawMergeResolutions->map(fn ($r) => [
                'law' => (string) ($lawTitles[(string) $r->law_id] ?? $r->law_id),
                'inherited_by' => $names[(string) $r->target_jurisdiction_id] ?? (string) $r->target_jurisdiction_id,
                'decision' => (string) $r->decision,
            ])->values()->all(),
            'opened_at' => $p->created_at?->toIso8601String(),
        ];
    }

    /**
     * The consent capability read (never a gate — openConstituentConsentVote
     * re-checks): the viewer's seated jurisdiction still has a PENDING consent
     * row on this OPEN process.
     *
     * @param  array{jurisdiction_id?: string}|null  $seat
     */
    private function viewerMayConsent(?\App\Models\MultiJurisdictionVote $mjv, string $status, string $openStatus, ?array $seat): bool
    {
        if ($mjv === null || $seat === null || $status !== $openStatus || ($seat['jurisdiction_id'] ?? '') === '') {
            return false;
        }

        return $mjv->consents
            ->where('jurisdiction_id', $seat['jurisdiction_id'])
            ->where('result', \App\Models\ConstituentConsent::RESULT_PENDING)
            ->isNotEmpty();
    }

    /** @param  list<string>  $ids
     *  @return array<string, string> */
    private function jurisdictionNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('jurisdictions')->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    // ── The seat requirement (shared by both doors) ──────────────────────────

    /** @return array{0: ?Legislature, 1: ?LegislatureMember} */
    private function currentSeatPair(?string $userId): array
    {
        if ($userId === null) {
            return [null, null];
        }

        $member = LegislatureMember::query()
            ->where('user_id', $userId)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->orderByDesc('created_at')
            ->first();

        if ($member === null) {
            return [null, null];
        }

        $legislature = Legislature::query()
            ->whereKey((string) $member->legislature_id)
            ->whereNull('deleted_at')
            ->first();

        return [$legislature, $member];
    }

    /** The door's capability read (never a gate — the service re-checks). */
    private function currentSeat(?string $userId): ?array
    {
        [$legislature, $member] = $this->currentSeatPair($userId);

        if ($legislature === null || $member === null) {
            return null;
        }

        $jurisdiction = Jurisdiction::query()->find((string) $legislature->jurisdiction_id);

        return [
            'legislature_id' => (string) $legislature->id,
            'jurisdiction_id' => (string) ($jurisdiction?->id ?? ''),
            'jurisdiction_name' => (string) ($jurisdiction?->name ?? ''),
            'has_parent' => $jurisdiction?->parent_id !== null,
            'child_count' => $jurisdiction === null ? 0 : (int) DB::table('jurisdictions')
                ->where('parent_id', $jurisdiction->id)->whereNull('deleted_at')->count(),
        ];
    }

    /** @return array{0: Legislature, 1: LegislatureMember} */
    private function requireSeat(?string $userId): array
    {
        [$legislature, $member] = $this->currentSeatPair($userId);

        abort_unless($legislature !== null && $member !== null, 422, 'Proposing requires a current legislative seat.');

        return [$legislature, $member];
    }

    private function unionDoor(?array $seat): array
    {
        $siblings = [];
        if ($seat !== null && $seat['jurisdiction_id'] !== '') {
            $parentId = Jurisdiction::query()->find($seat['jurisdiction_id'])?->parent_id;
            if ($parentId !== null) {
                $siblings = DB::table('jurisdictions')
                    ->where('parent_id', $parentId)
                    ->where('id', '<>', $seat['jurisdiction_id'])
                    ->whereNull('deleted_at')
                    ->orderBy('name')
                    ->limit(100)
                    ->get(['id', 'name'])
                    ->map(fn ($r) => ['id' => (string) $r->id, 'name' => (string) $r->name])
                    ->all();
            }
        }

        return ['seat' => $seat, 'siblings' => $siblings];
    }

    private function disintermediationDoor(?array $seat): array
    {
        return [
            'seat' => $seat,
            // Art. V §8 needs both directions of the sandwich to exist.
            'proposable' => $seat !== null && $seat['has_parent'] && $seat['child_count'] > 0,
        ];
    }
}
