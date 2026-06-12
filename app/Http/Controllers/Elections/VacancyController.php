<?php

namespace App\Http\Controllers\Elections;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Elections\Concerns\ResolvesBoardActor;
use App\Http\Presenters\StvRoundPresenter;
use App\Models\Candidacy;
use App\Models\Election;
use App\Models\ElectionRace;
use App\Models\LegislatureMember;
use App\Models\Tabulation;
use App\Models\Vacancy;
use App\Services\SettingsResolver;
use App\Services\VacancyService;
use App\Support\SurfaceMeta;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-B8 — VacancyCountback (PHASE_B_DESIGN_frontend.md §B.8).
 *
 *   GET  /vacancies/{vacancy}                   — show
 *   POST /vacancies/{vacancy}/certify           — certify        (F-ELB-004 countback variant)
 *   POST /vacancies/{vacancy}/special-election  — scheduleSpecial (F-ELB-001)
 *
 * The page's signature moment (§B.8): the special-election date Field is
 * window-bounded client-side (min/max = CLK-04 bounds) as UX only — the
 * ENGINE rejects out-of-window dates with the Art. II §5 citation, and the
 * page surfaces that 422 as the Field error.
 */
class VacancyController extends Controller
{
    use ResolvesBoardActor;

    /** ESM-13 strip (machine PHP-owned — §B conventions). */
    public const VACANCY_MACHINE = [
        Vacancy::STATUS_DETECTED,
        Vacancy::STATUS_DECLARED,
        Vacancy::STATUS_COUNTBACK_RUNNING,
        Vacancy::STATUS_FILLED,
        Vacancy::STATUS_COUNTBACK_FAILED,
        Vacancy::STATUS_SPECIAL_SCHEDULED,
    ];

    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly StvRoundPresenter $presenter,
        private readonly SettingsResolver $settings,
        private readonly VacancyService $vacancies,
    ) {
    }

    public function show(Request $request, Vacancy $vacancy): Response
    {
        $member = $vacancy->seat_type === 'legislature_members'
            ? LegislatureMember::query()->with('user:id,name,display_name')->find($vacancy->seat_id)
            : null;

        $tabulation = $vacancy->countback_tabulation_id !== null
            ? Tabulation::query()->find($vacancy->countback_tabulation_id)
            : null;

        $winner = $this->winnerCandidacy($vacancy, $tabulation);

        $outcome = match ($vacancy->status) {
            Vacancy::STATUS_FILLED            => 'winner',
            Vacancy::STATUS_COUNTBACK_FAILED,
            Vacancy::STATUS_SPECIAL_SCHEDULED => 'exhausted',
            default                           => 'running',
        };

        $board = $this->activeBoardFor(null, (string) $vacancy->jurisdiction_id);
        $standing = $this->boardActorFor($request->user(), $board);

        $special = $vacancy->specialElection;

        return Inertia::render('Elections/VacancyCountback', [
            'surface' => SurfaceMeta::for('elections/vacancy-countback'),
            'vacancy' => [
                'id'           => (string) $vacancy->id,
                'office_label' => ($vacancy->jurisdiction?->name ?? 'Unknown jurisdiction') . ' legislature',
                'seat_no'      => $member?->seat_no,
                'member_name'  => $member?->user?->display_name ?: $member?->user?->name,
                'declared_at'  => $vacancy->declared_at?->toIso8601String(),
                'declared_by'  => $vacancy->declaredBy !== null
                    ? ($vacancy->declaredBy->display_name ?: $vacancy->declaredBy->name)
                    : 'system (dev declaration — F-LEG-036 arrives in Phase C)',
                'declared_via' => $vacancy->declared_via_form,
                'reason'       => $member?->vacancy_reason,
                'status'       => $vacancy->status,
                'window'       => $this->window($vacancy),
            ],
            'machine' => self::VACANCY_MACHINE,
            'rerun' => [
                'source'  => $this->source($tabulation, $member),
                'outcome' => $outcome,
                'winner'  => $winner !== null ? [
                    'candidacy_id' => (string) $winner->id,
                    'name'         => $winner->user?->display_name ?: $winner->user?->name,
                ] : null,
                'quota'   => $tabulation !== null ? (int) $tabulation->quota : null,
                'scale'   => $tabulation !== null
                    ? max(1, (int) round((int) $tabulation->quota * StvRoundPresenter::SCALE_FACTOR))
                    : null,
                'bars'    => $tabulation !== null && $tabulation->status === Tabulation::STATUS_COMPLETE
                    ? $this->presenter->countbackBars($tabulation, $winner !== null ? (string) $winner->id : null)
                    : [],
            ],
            'certification' => $vacancy->status === Vacancy::STATUS_FILLED ? [
                'certified_at' => $vacancy->filled_at?->toIso8601String(),
                'winner_name'  => $winner?->user?->display_name ?: $winner?->user?->name,
            ] : null,
            'specialElection' => $special !== null ? [
                'id'            => (string) $special->id,
                'scheduled_for' => $special->ranked_opens_at?->toDateString(),
                'status'        => $special->status,
            ] : null,
            'can' => [
                'certify'  => $standing !== false
                    && in_array($vacancy->status, [Vacancy::STATUS_DETECTED, Vacancy::STATUS_DECLARED], true),
                'schedule' => $standing !== false
                    && in_array($vacancy->status, [Vacancy::STATUS_COUNTBACK_FAILED, Vacancy::STATUS_SPECIAL_SCHEDULED], true),
            ],
        ]);
    }

    /**
     * F-ELB-004 (countback variant) — normally the countback pipeline
     * certifies automatically (RunCountbackJob → CertificationService).
     * This endpoint drives a vacancy whose countback never ran (crashed
     * queue, dev seeding) and answers honestly otherwise.
     */
    public function certify(Request $request, Vacancy $vacancy): RedirectResponse
    {
        $board = $this->activeBoardFor(null, (string) $vacancy->jurisdiction_id);
        $standing = $this->boardActorFor($request->user(), $board);

        abort_if($standing === false, 403, 'Certifying a countback requires standing on the election board (R-08).');

        if (in_array($vacancy->status, [Vacancy::STATUS_DETECTED, Vacancy::STATUS_DECLARED], true)) {
            $vacancy = $this->vacancies->runCountback($vacancy);

            return back()->with('status', $vacancy->status === Vacancy::STATUS_FILLED
                ? 'Countback complete — the replacement is certified and seated (F-ELB-004).'
                : 'Countback exhausted — the special-election window is armed (CLK-04).');
        }

        if ($vacancy->status === Vacancy::STATUS_COUNTBACK_RUNNING) {
            throw new ConstitutionalViolation(
                'The countback is still running — certification follows automatically when the re-run completes.',
                'Art. II §5'
            );
        }

        if ($vacancy->status === Vacancy::STATUS_FILLED) {
            return back()->with('status', 'Already certified — the replacement holds the seat.');
        }

        throw new ConstitutionalViolation(
            'The countback exhausted — there is no winner to certify; schedule the special election instead.',
            'Art. II §5'
        );
    }

    /**
     * F-ELB-001 — schedule (or refine) the special election. The ranked
     * window must sit inside [declared + min_days, declared + max_days];
     * the engine 422s out-of-window dates with the constitutional citation.
     */
    public function scheduleSpecial(Request $request, Vacancy $vacancy): RedirectResponse
    {
        $validated = $request->validate([
            'scheduled_for' => ['required', 'date'],
        ]);

        $board = $this->activeBoardFor(null, (string) $vacancy->jurisdiction_id);
        $standing = $this->boardActorFor($request->user(), $board);

        abort_if($standing === false, 403, 'Scheduling a special election requires standing on the election board (R-08).');

        $jurisdictionId = (string) $vacancy->jurisdiction_id;
        $windowDays = max(1, $this->settings->resolveInt($jurisdictionId, 'ranked_window_days', 14));

        $rankedOpens = CarbonImmutable::parse($validated['scheduled_for'], 'UTC')->startOfDay();
        $rankedCloses = $rankedOpens->addDays($windowDays);

        $existing = $vacancy->special_election_id !== null
            ? Election::query()->find($vacancy->special_election_id)
            : null;

        $approvalMinDays = max(1, $this->settings->resolveInt($jurisdictionId, 'approval_min_days', 30));
        $opens = $existing?->approval_opens_at !== null
            ? CarbonImmutable::parse($existing->approval_opens_at)
            : CarbonImmutable::now('UTC');
        $cutoff = $existing?->finalist_cutoff_at !== null
            ? CarbonImmutable::parse($existing->finalist_cutoff_at)
            : $opens->addDays($approvalMinDays);

        $payload = [
            'jurisdiction_id'    => $jurisdictionId,
            'vacancy_id'         => (string) $vacancy->id,
            'approval_opens_at'  => $opens->toIso8601String(),
            'finalist_cutoff_at' => $cutoff->toIso8601String(),
            'ranked_opens_at'    => $rankedOpens->toIso8601String(),
            'ranked_closes_at'   => $rankedCloses->toIso8601String(),
        ];

        if ($existing !== null) {
            $payload['election_id'] = (string) $existing->id;
        } else {
            $payload['kind'] = Election::KIND_SPECIAL;
            $payload['legislature_id'] = (string) $vacancy->legislature_id;
            $payload['trigger'] = 'vacancy';
        }

        $this->engine->file('F-ELB-001', $standing['actor'], $payload);

        if ($existing === null && $vacancy->refresh()->special_election_id === null) {
            // Creation path: link the fresh order's election to the vacancy.
            $created = Election::query()
                ->where('vacancy_id', (string) $vacancy->id)
                ->orderByDesc('created_at')
                ->first();

            if ($created !== null) {
                $vacancy->forceFill([
                    'status'              => Vacancy::STATUS_SPECIAL_SCHEDULED,
                    'special_election_id' => (string) $created->id,
                ])->save();
            }
        }

        return back()->with(
            'status',
            "Special election scheduled — ranked window opens {$rankedOpens->toDateString()} · scheduling order issued (F-ELB-001) · WF-ELE-04."
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * CLK-04 window bounds. `opens_on`/`latest_start` are day-aligned so
     * the page's date Field min/max are honest: the earliest schedulable
     * DAY fully clears declared_at + min_days, and the latest start still
     * fits the whole ranked window inside declared_at + max_days.
     */
    private function window(Vacancy $vacancy): array
    {
        $declared = CarbonImmutable::parse($vacancy->declared_at ?? $vacancy->detected_at ?? now());
        $jurisdictionId = (string) $vacancy->jurisdiction_id;

        $minDays = $this->settings->resolveInt($jurisdictionId, 'special_election_min_days', 90);
        $maxDays = $this->settings->resolveInt($jurisdictionId, 'special_election_max_days', 180);
        $windowDays = max(1, $this->settings->resolveInt($jurisdictionId, 'ranked_window_days', 14));

        $opens = $declared->addDays($minDays);
        $closes = $declared->addDays($maxDays);

        $earliestDay = $opens->equalTo($opens->startOfDay()) ? $opens : $opens->addDay()->startOfDay();
        $latestStart = $closes->subDays($windowDays)->startOfDay();

        return [
            'opens_on'           => $earliestDay->toDateString(),
            'closes_on'          => $closes->toDateString(),
            'latest_start'       => $latestStart->toDateString(),
            'min_days'           => $minDays,
            'max_days'           => $maxDays,
            'ranked_window_days' => $windowDays,
        ];
    }

    private function winnerCandidacy(Vacancy $vacancy, ?Tabulation $tabulation): ?Candidacy
    {
        if ($vacancy->filled_by_user_id === null || $tabulation === null) {
            return null;
        }

        return Candidacy::query()
            ->where('race_id', (string) $tabulation->race_id)
            ->where('user_id', (string) $vacancy->filled_by_user_id)
            ->with('user:id,name,display_name')
            ->first();
    }

    private function source(?Tabulation $tabulation, ?LegislatureMember $member): ?array
    {
        $raceId = $tabulation?->race_id ?? $member?->elected_in_race_id;

        if ($raceId === null) {
            return null;
        }

        $race = ElectionRace::query()->with(['election.jurisdiction:id,name', 'jurisdiction:id,name'])->find($raceId);

        if ($race === null) {
            return null;
        }

        $total = (int) ($tabulation?->total_valid ?? $race->total_valid_ballots ?? 0);
        $seats = (int) ($tabulation?->seats ?? $race->seats);
        $quota = (int) ($tabulation?->quota ?? $race->quota ?? 0);

        $election = $race->election;
        $label = ($election?->jurisdiction?->name ?? $race->jurisdiction?->name ?? 'Unknown')
            . ' ' . ($election?->kind ?? 'general') . ' election';

        return [
            'election_label' => $label,
            'total_valid'    => $total,
            'seats'          => $seats,
            'quota'          => $quota,
            'quota_formula'  => sprintf(
                'floor(%s ÷ %d) + 1 = %s',
                number_format($total),
                $seats + 1,
                number_format($quota),
            ),
        ];
    }
}
