<?php

namespace App\Services;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\CertificationPipeline;
use App\Jobs\Elections\TabulateElectionJob;
use App\Models\Candidacy;
use App\Models\ClockTimer;
use App\Models\Election;
use App\Models\ElectionAudit;
use App\Models\ElectionCertification;
use App\Models\ElectionRace;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\RaceResult;
use App\Models\Tabulation;
use App\Models\Term;
use App\Models\Vacancy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * WI-B5 — the certification → seating pipeline (design §B.2.5, counting
 * design §D "Certification"). Bound to CertificationPipeline in
 * ConstitutionProvider; the F-ELB-004 handler calls certify() INSIDE the
 * engine transaction after it has validated (every race counted, record
 * hashes sealed, board provenance, idempotency) and flipped ESM-03 to
 * `certified` — this class is the side-effect block only. Its writes ride
 * the handler's single F-ELB-004 chain entry via the returned payload
 * (winners, terms, successor); the clock arms and the successor's phase
 * moves append their own entries through their owning services.
 *
 * GENERAL election certification:
 *   - chamber turnover: prior current members → 'term_ended', their
 *     active lockstep terms → 'completed' (a general election re-elects
 *     the whole chamber; lockstep terms END at certification of the
 *     successor count — CLK-10, never extended);
 *   - winners → legislature_members (status 'elected'; the Phase C oath
 *     flips to 'seated'), seat_type from race seat_kind, district linkage,
 *     vote_share_norm copied from race_results;
 *   - one `terms` row per winner: lockstepWindow() — starts_on =
 *     certification date, ends_on = starts + election_interval_months
 *     (per-jurisdiction resolved at certification);
 *   - legislature forming → active, term_starts_on/term_ends_on set,
 *     term_number advances on re-election cycles;
 *   - CLK-10 armed per term (fires_at NULL — the derived lockstep FLAG:
 *     observable by term-sync screens and audit:verify; the no-API
 *     guarantee is what enforces it, pinned by TermLockstepTest);
 *   - CLK-01 re-armed for the next cycle (fires_at = certified_at +
 *     interval − approval/ranked lead, via ElectionLifecycleService) —
 *     the ONLY place this timer is ever (re)armed;
 *   - election N+1 created with its approval phase open (openSuccessor —
 *     the cycle loop closes; no dead period).
 *
 * SPECIAL election certification: seats exactly the vacancy — the term
 * INHERITS THE ORIGINAL EXPIRY (inheritedWindow(), CLK-10: never a fresh
 * term), the vacated seat_no is reused, the vacancy flips to 'filled',
 * the CLK-04 backstop is cancelled. No turnover, no successor, no
 * legislature term change.
 *
 * COUNTBACK certification (certifyCountback, called by VacancyService —
 * the F-ELB-004 "countback variant" of design §C, recorded with that ref;
 * it does not pass through the form handler because the original election
 * is already certified): same inherited-expiry seating for the first
 * eligible countback replacement.
 */
class CertificationService implements CertificationPipeline
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ClockService $clocks,
        private readonly SettingsResolver $settings,
        private readonly ElectionLifecycleService $lifecycle,
        private readonly RoleService $roles,
    ) {
    }

    // =========================================================================
    // Pure lockstep math (CLK-10) — pinned by tests/Constitutional/TermLockstepTest
    // =========================================================================

    /**
     * A fresh lockstep window: starts the day of certification, ends
     * exactly election_interval_months later (Art. II §2; CLK-01/CLK-10).
     *
     * @return array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}
     */
    public static function lockstepWindow(CarbonInterface $certifiedAt, int $intervalMonths): array
    {
        $starts = CarbonImmutable::instance($certifiedAt)->startOfDay();

        return [
            'starts_on' => $starts,
            'ends_on'   => $starts->addMonthsNoOverflow($intervalMonths),
        ];
    }

    /**
     * An INHERITED lockstep window (countback / special-election
     * replacement): starts the day the replacement is certified, ends on
     * the ORIGINAL expiry — the identity is the constitutional pin
     * (Art. II §5; CLK-10): a replacement term may never outlive the term
     * it fills, and nothing may move the inherited date.
     *
     * @return array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}
     */
    public static function inheritedWindow(CarbonInterface $certifiedAt, CarbonInterface $originalEndsOn): array
    {
        return [
            'starts_on' => CarbonImmutable::instance($certifiedAt)->startOfDay(),
            'ends_on'   => CarbonImmutable::instance($originalEndsOn)->startOfDay(),
        ];
    }

    // =========================================================================
    // CertificationPipeline
    // =========================================================================

    public function certify(Election $election, ElectionCertification $certification): array
    {
        $legislature = $election->legislature;

        if ($legislature === null) {
            throw new ConstitutionalViolation(
                'Certification cannot seat winners — the election has no legislature.',
                'Art. II §2'
            );
        }

        $certifiedAt = $certification->certified_at !== null
            ? CarbonImmutable::parse($certification->certified_at)
            : CarbonImmutable::now('UTC');

        $isSpecial = $election->kind === Election::KIND_SPECIAL;

        $vacancy      = null;
        $vacatedSeat  = null;

        if ($isSpecial) {
            $vacancy     = $election->vacancy_id !== null ? Vacancy::query()->find($election->vacancy_id) : null;
            $vacatedSeat = $vacancy !== null ? LegislatureMember::query()->find($vacancy->seat_id) : null;

            $originalEndsOn = $this->originalExpiry($vacatedSeat, $legislature);
            $window         = self::inheritedWindow($certifiedAt, $originalEndsOn);
        } else {
            $interval = $this->settings->resolveInt($legislature->jurisdiction_id, 'election_interval_months', 60);
            $window   = self::lockstepWindow($certifiedAt, $interval);

            $this->turnOverChamber($legislature);
        }

        $winners = [];
        $terms   = [];

        foreach ($election->races()->get() as $race) {
            $tabulation = $this->certifiedTabulation($race);

            $results = RaceResult::query()
                ->where('tabulation_id', $tabulation->id)
                ->whereNotNull('seat_no')
                ->orderBy('seat_no')
                ->get();

            $winnerIds = [];

            foreach ($results as $result) {
                $candidacy = Candidacy::query()->findOrFail($result->candidacy_id);

                $member = $this->seatWinner(
                    legislature: $legislature,
                    race: $race,
                    election: $election,
                    candidacy: $candidacy,
                    seatNo: $isSpecial ? ($vacatedSeat?->seat_no ?? $result->seat_no) : $result->seat_no,
                    voteShareNorm: $result->vote_share_norm,
                    window: $window,
                );

                $term = $this->openTerm($member, $election, $window);

                $winnerIds[] = (string) $candidacy->id;

                $winners[] = [
                    'user_id'         => (string) $candidacy->user_id,
                    'candidacy_id'    => (string) $candidacy->id,
                    'race_id'         => (string) $race->id,
                    'seat_no'         => $member->seat_no,
                    'member_id'       => (string) $member->id,
                    'vote_share_norm' => $member->vote_share_norm,
                ];

                $terms[] = [
                    'term_id'        => (string) $term->id,
                    'holder_user_id' => (string) $term->holder_user_id,
                    'starts_on'      => $term->starts_on->toDateString(),
                    'ends_on'        => $term->ends_on->toDateString(),
                    'term_class'     => $term->term_class,
                ];
            }

            // The rest of the public record: standing candidacies of this
            // race that did not win are defeated (ESM-06 terminal).
            Candidacy::query()
                ->where('race_id', $race->id)
                ->whereIn('status', [Candidacy::STATUS_FINALIST, Candidacy::STATUS_NON_FINALIST])
                ->whereNotIn('id', $winnerIds)
                ->update(['status' => Candidacy::STATUS_DEFEATED, 'updated_at' => now()]);
        }

        $nextElectionId = null;
        $referendums    = [];

        if ($isSpecial) {
            $this->resolveVacancy($vacancy, $winners);

            // A whole-jurisdiction special may carry questions too (§D).
            $referendums = app(ReferendumService::class)->certifyForElection($election, null);
        } else {
            $this->advanceLegislatureTerm($legislature, $window);

            // CLK-01: cancel any leftover armed cycle timer, then arm the
            // next cycle — certification is the ONLY (re)arm point.
            foreach ($this->armedTimers('legislature', (string) $legislature->id, 'CLK-01') as $stale) {
                $this->clocks->cancel($stale, 'superseded by certification re-arm');
            }

            $this->lifecycle->armNextGeneralElection($legislature, $certifiedAt);

            // The loop closes: election N+1 with its approval phase open.
            $nextElectionId = (string) $this->lifecycle->openSuccessor($election)->id;

            // C-R1 (votes_laws §D): resolve this ballot's referendum
            // questions against the population peg — passed questions
            // enact with the CLK-19 shield anchored to the successor
            // general (the shield lapses when IT certifies)…
            $referendums = app(ReferendumService::class)->certifyForElection($election, $nextElectionId);

            // …and THIS certification is the lapse point for acts whose
            // shield election is the one now certified (general only).
            app(ReferendumService::class)->releaseShields($election);
        }

        // Winners derive R-09 from the member rows — flush so a long-lived
        // worker reflects the new facts immediately.
        $this->roles->flush();

        return [
            'winners'          => $winners,
            'terms'            => $terms,
            'term_window'      => [
                'starts_on' => $window['starts_on']->toDateString(),
                'ends_on'   => $window['ends_on']->toDateString(),
                'inherited' => $isSpecial,
            ],
            'legislature'      => [
                'id'          => (string) $legislature->id,
                'status'      => $legislature->refresh()->status,
                'term_number' => (int) $legislature->term_number,
            ],
            'next_election_id' => $nextElectionId,
            'vacancy_filled'   => $vacancy !== null ? (string) $vacancy->id : null,
            'referendums'      => $referendums,
        ];
    }

    public function beginAuditRerun(ElectionAudit $audit): void
    {
        TabulateElectionJob::dispatch(
            (string) $audit->election_id,
            Tabulation::KIND_AUDIT_RERUN,
            $audit->race_id !== null ? (string) $audit->race_id : null,
            (string) $audit->id,
        );
    }

    // =========================================================================
    // Countback variant (Art. II §5 — called by VacancyService)
    // =========================================================================

    /**
     * Seat a countback replacement: identical seating mechanics with the
     * INHERITED window; the vacancy flips to filled and its CLK-04
     * backstop (if armed) is cancelled. Appends its own chain entry with
     * ref F-ELB-004 (the countback certification variant, design §C).
     */
    public function certifyCountback(Vacancy $vacancy, Tabulation $tabulation, Candidacy $winner): LegislatureMember
    {
        return DB::transaction(function () use ($vacancy, $tabulation, $winner): LegislatureMember {
            $race        = ElectionRace::query()->findOrFail($tabulation->race_id);
            $election    = Election::query()->findOrFail($race->election_id);
            $legislature = Legislature::query()->findOrFail($vacancy->legislature_id);
            $vacatedSeat = LegislatureMember::query()->find($vacancy->seat_id);

            $window = self::inheritedWindow(
                CarbonImmutable::now('UTC'),
                $this->originalExpiry($vacatedSeat, $legislature),
            );

            $result = RaceResult::query()
                ->where('tabulation_id', $tabulation->id)
                ->where('candidacy_id', $winner->id)
                ->first();

            $member = $this->seatWinner(
                legislature: $legislature,
                race: $race,
                election: $election,
                candidacy: $winner,
                seatNo: $vacatedSeat?->seat_no ?? $result?->seat_no,
                voteShareNorm: $result?->vote_share_norm,
                window: $window,
            );

            $term = $this->openTerm($member, $election, $window);

            $vacancy->forceFill([
                'status'            => Vacancy::STATUS_FILLED,
                'filled_by_user_id' => $winner->user_id,
                'filled_at'         => now(),
            ])->save();

            foreach ($this->armedTimers('vacancy', (string) $vacancy->id, 'CLK-04') as $backstop) {
                $this->clocks->cancel($backstop, 'vacancy filled by countback');
            }

            $this->roles->flush();

            $this->audit->append(
                module: 'elections',
                event: 'vacancy.filled_by_countback',
                payload: [
                    'vacancy_id'      => (string) $vacancy->id,
                    'member_id'       => (string) $member->id,
                    'user_id'         => (string) $winner->user_id,
                    'candidacy_id'    => (string) $winner->id,
                    'race_id'         => (string) $race->id,
                    'tabulation_id'   => (string) $tabulation->id,
                    'record_hash'     => $tabulation->record_hash,
                    'vote_share_norm' => $member->vote_share_norm,
                    'term'            => [
                        'term_id'   => (string) $term->id,
                        'starts_on' => $window['starts_on']->toDateString(),
                        'ends_on'   => $window['ends_on']->toDateString(),
                        'inherited' => true,
                    ],
                ],
                ref: 'F-ELB-004',
                jurisdictionId: $vacancy->jurisdiction_id,
            );

            return $member;
        });
    }

    // =========================================================================
    // Seating mechanics
    // =========================================================================

    /**
     * The certified record per race: latest COMPLETE tabulation with a
     * sealed hash — same selection the F-ELB-004 handler validated.
     */
    private function certifiedTabulation(ElectionRace $race): Tabulation
    {
        $tabulation = Tabulation::query()
            ->where('race_id', $race->id)
            ->where('status', Tabulation::STATUS_COMPLETE)
            ->whereNotNull('record_hash')
            ->orderByDesc('completed_at')
            ->first();

        if ($tabulation === null) {
            throw new ConstitutionalViolation(
                "Race [{$race->id}] has no complete tabulation — certification requires every race counted.",
                'CGA Forms Catalog (F-ELB-004)'
            );
        }

        return $tabulation;
    }

    /**
     * @param  array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}  $window
     */
    private function seatWinner(
        Legislature $legislature,
        ElectionRace $race,
        Election $election,
        Candidacy $candidacy,
        ?int $seatNo,
        mixed $voteShareNorm,
        array $window,
    ): LegislatureMember {
        $member = LegislatureMember::create([
            'legislature_id'       => $legislature->id,
            'user_id'              => $candidacy->user_id,
            'seat_type'            => $race->seat_kind === ElectionRace::SEAT_KIND_TYPE_B ? 'b' : 'a',
            'seat_no'              => $seatNo,
            'district_id'          => $race->district_id,
            'elected_in_race_id'   => $race->id,
            'election_id'          => $election->id,
            'vote_share_norm'      => $voteShareNorm,
            'status'               => LegislatureMember::STATUS_ELECTED,
            'seated_on'            => $window['starts_on']->toDateString(),
            'term_ends_on'         => $window['ends_on']->toDateString(),
            'home_jurisdiction_id' => $this->deepestAssociation((string) $candidacy->user_id),
        ]);

        $candidacy->forceFill(['status' => Candidacy::STATUS_ELECTED])->save();

        return $member;
    }

    /**
     * The CLK-10 substrate row + its lockstep flag timer. `ends_on` is
     * written exactly once, here, at creation — no update path exists
     * anywhere (TermLockstepTest pins the absence).
     *
     * @param  array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}  $window
     */
    private function openTerm(LegislatureMember $member, Election $election, array $window): Term
    {
        $term = Term::create([
            'office_kind'        => 'legislature_seat',
            'office_type'        => 'legislature_members',
            'office_id'          => $member->id,
            'holder_user_id'     => $member->user_id,
            'jurisdiction_id'    => $election->jurisdiction_id,
            'legislature_id'     => $member->legislature_id,
            'term_class'         => Term::CLASS_LOCKSTEP,
            'starts_on'          => $window['starts_on']->toDateString(),
            'ends_on'            => $window['ends_on']->toDateString(),
            'source_election_id' => $election->id,
            'status'             => Term::STATUS_ACTIVE,
        ]);

        $member->forceFill(['term_id' => $term->id])->save();

        // CLK-10 flag (fires_at NULL — derived, never a deadline): makes
        // lockstep state observable to term-sync screens and audit:verify.
        $this->clocks->arm(
            'CLK-10',
            (string) $election->jurisdiction_id,
            'term',
            (string) $term->id,
            null,
            ['step' => 'lockstep', 'ends_on' => $window['ends_on']->toDateString()],
        );

        return $term;
    }

    /**
     * General-election turnover: every current member's seat ends at the
     * certification of the successor count (lockstep — the term rows keep
     * their original ends_on as the historical record; only status moves).
     */
    private function turnOverChamber(Legislature $legislature): void
    {
        $outgoing = Term::query()
            ->where('legislature_id', $legislature->id)
            ->where('term_class', Term::CLASS_LOCKSTEP)
            ->where('status', Term::STATUS_ACTIVE)
            ->get();

        foreach ($outgoing as $term) {
            $term->forceFill(['status' => Term::STATUS_COMPLETED])->save();

            foreach ($this->armedTimers('term', (string) $term->id, 'CLK-10') as $flag) {
                $this->clocks->cancel($flag, 'term completed at successor certification');
            }
        }

        LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->update(['status' => LegislatureMember::STATUS_TERM_ENDED, 'updated_at' => now()]);
    }

    /**
     * @param  array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}  $window
     */
    private function advanceLegislatureTerm(Legislature $legislature, array $window): void
    {
        $legislature->forceFill([
            'status'         => Legislature::STATUS_ACTIVE,
            'term_number'    => $legislature->term_starts_on !== null
                ? ((int) $legislature->term_number) + 1
                : (int) $legislature->term_number,
            'term_starts_on' => $window['starts_on']->toDateString(),
            'term_ends_on'   => $window['ends_on']->toDateString(),
        ])->save();
    }

    private function resolveVacancy(?Vacancy $vacancy, array $winners): void
    {
        if ($vacancy === null) {
            return;
        }

        $vacancy->forceFill([
            'status'            => Vacancy::STATUS_FILLED,
            'filled_by_user_id' => $winners[0]['user_id'] ?? null,
            'filled_at'         => now(),
        ])->save();

        foreach ($this->armedTimers('vacancy', (string) $vacancy->id, 'CLK-04') as $backstop) {
            $this->clocks->cancel($backstop, 'vacancy filled by special election');
        }
    }

    /**
     * The original lockstep expiry a replacement inherits: the vacated
     * seat's own term row, else the legislature's term schedule.
     */
    private function originalExpiry(?LegislatureMember $vacatedSeat, Legislature $legislature): CarbonInterface
    {
        $fromTerm = $vacatedSeat?->term_id !== null
            ? Term::query()->whereKey($vacatedSeat->term_id)->value('ends_on')
            : null;

        $endsOn = $fromTerm ?? $vacatedSeat?->term_ends_on ?? $legislature->term_ends_on;

        if ($endsOn === null) {
            throw new ConstitutionalViolation(
                'No lockstep expiry exists to inherit — the chamber has no term schedule.',
                'Art. II §5'
            );
        }

        return CarbonImmutable::parse($endsOn);
    }

    /** The member's association at election time (deepest active confirmation). */
    private function deepestAssociation(string $userId): ?string
    {
        $row = DB::table('residency_confirmations')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->orderByRaw('depth ASC NULLS LAST')
            ->first(['jurisdiction_id']);

        return $row?->jurisdiction_id;
    }

    /** @return list<ClockTimer> */
    private function armedTimers(string $subjectType, string $subjectId, string $clockId): array
    {
        return ClockTimer::query()
            ->armed()
            ->where('clock_id', $clockId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->get()
            ->all();
    }
}
