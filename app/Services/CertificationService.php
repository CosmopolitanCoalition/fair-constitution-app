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
        // Phase D (PHASE_D_DESIGN_executive §B.2.6 — constitutional
        // review): executive elections seat EXECUTIVE members, not
        // chamber members. Same certified-tabulation discipline; the
        // FIRST elected term inherits the legislature's remaining
        // lockstep window (conversion never resets lockstep — CLK-10).
        if ($election->kind === Election::KIND_EXECUTIVE) {
            return $this->certifyExecutive($election, $certification);
        }

        // Phase E (PHASE_E_DESIGN_judiciary §B.5 — constitutional review):
        // judicial elections seat ELECTED JUDGES as judicial_seats. Same
        // certified-tabulation + inherited-lockstep discipline as the
        // executive path — judges are an STV group; their terms last the
        // SAME length as legislators (Art. IV §3), the FIRST elected term
        // inheriting the chamber's remaining lockstep window (CLK-10).
        if ($election->kind === Election::KIND_JUDICIAL) {
            return $this->certifyJudicial($election, $certification);
        }

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
    // Executive certification (Phase D — Art. III §2/§3; design §B.2.6)
    // =========================================================================

    /**
     * Seat an executive election's winners as executive_members:
     *
     *  - exec_committee races (PR-STV, untouched counting path): every
     *    seated result → role 'principal', selection 'elected_stv';
     *  - single races (RCV): the winner → 'principal'/'elected_rcv', then
     *    deriveAdvisors() seats ranks 1–4 as 'advisor'/'advisor_derivation'
     *    (underivable ranks stay vacant — the engine contract);
     *  - TERMS (lockstep, CLK-10): every member AND advisor gets a terms
     *    row office_kind 'executive_seat', term_class 'lockstep' — the
     *    FIRST election after conversion uses inheritedWindow ending on
     *    the legislature's CURRENT term_ends_on (term = remainder;
     *    conversion never resets lockstep);
     *  - the executive row evolves: conversion_voted → elected, type set
     *    from the race shape, delegated-era member rows close ('left').
     */
    private function certifyExecutive(Election $election, ElectionCertification $certification): array
    {
        $legislature = $election->legislature;
        $executive   = $election->executive;

        if ($legislature === null || $executive === null) {
            throw new ConstitutionalViolation(
                'An executive election certifies against its legislature (lockstep anchor) and office.',
                'Art. III §2'
            );
        }

        if ($legislature->term_ends_on === null) {
            throw new ConstitutionalViolation(
                'No lockstep expiry exists to inherit — the chamber has no term schedule.',
                'Art. III §3'
            );
        }

        $certifiedAt = $certification->certified_at !== null
            ? CarbonImmutable::parse($certification->certified_at)
            : CarbonImmutable::now('UTC');

        $window = self::inheritedWindow($certifiedAt, CarbonImmutable::parse($legislature->term_ends_on));

        $winners = [];
        $terms   = [];
        $type    = $executive->type;

        foreach ($election->races()->get() as $race) {
            $tabulation = $this->certifiedTabulation($race);

            $isCommittee = $race->seat_kind === ElectionRace::SEAT_KIND_EXEC_COMMITTEE;
            $type        = $isCommittee ? 'committee' : 'individual';

            $results = RaceResult::query()
                ->where('tabulation_id', $tabulation->id)
                ->whereNotNull('seat_no')
                ->orderBy('seat_no')
                ->get();

            $winnerIds = [];

            foreach ($results as $result) {
                $candidacy = Candidacy::query()->findOrFail($result->candidacy_id);

                [$member, $term] = $this->seatExecutiveMember(
                    executive: $executive,
                    election: $election,
                    race: $race,
                    candidacy: $candidacy,
                    role: 'principal',
                    rank: 0,
                    selection: $isCommittee ? 'elected_stv' : 'elected_rcv',
                    window: $window,
                );

                $winnerIds[] = (string) $candidacy->id;

                $winners[] = [
                    'user_id'      => (string) $candidacy->user_id,
                    'candidacy_id' => (string) $candidacy->id,
                    'race_id'      => (string) $race->id,
                    'member_id'    => (string) $member->id,
                    'role'         => 'principal',
                    'rank'         => 0,
                ];

                $terms[] = [
                    'term_id'        => (string) $term->id,
                    'holder_user_id' => (string) $term->holder_user_id,
                    'starts_on'      => $term->starts_on->toDateString(),
                    'ends_on'        => $term->ends_on->toDateString(),
                    'term_class'     => $term->term_class,
                ];
            }

            // Individual model: ranks 1–4 advisors by sequential exclusion
            // (deriveAdvisors — Art. III §3; underivable ranks stay vacant).
            if (! $isCommittee) {
                $advisorRuns = app(VoteCountingService::class)->deriveAdvisors(
                    app(TabulationRecorder::class)->countInput($race)
                );

                foreach ([1, 2, 3, 4] as $rank) {
                    $candidacyId = $advisorRuns[$rank]?->elected[0]['candidacy_id'] ?? null;

                    if ($candidacyId === null) {
                        continue; // vacant rank — the engine contract
                    }

                    $candidacy = Candidacy::query()->find($candidacyId);

                    if ($candidacy === null) {
                        continue;
                    }

                    [$member, $term] = $this->seatExecutiveMember(
                        executive: $executive,
                        election: $election,
                        race: $race,
                        candidacy: $candidacy,
                        role: 'advisor',
                        rank: $rank,
                        selection: 'advisor_derivation',
                        window: $window,
                    );

                    $winnerIds[] = (string) $candidacy->id;

                    $winners[] = [
                        'user_id'      => (string) $candidacy->user_id,
                        'candidacy_id' => (string) $candidacy->id,
                        'race_id'      => (string) $race->id,
                        'member_id'    => (string) $member->id,
                        'role'         => 'advisor',
                        'rank'         => $rank,
                    ];

                    $terms[] = [
                        'term_id'        => (string) $term->id,
                        'holder_user_id' => (string) $term->holder_user_id,
                        'starts_on'      => $term->starts_on->toDateString(),
                        'ends_on'        => $term->ends_on->toDateString(),
                        'term_class'     => $term->term_class,
                    ];
                }
            }

            Candidacy::query()
                ->where('race_id', $race->id)
                ->whereIn('status', [Candidacy::STATUS_FINALIST, Candidacy::STATUS_NON_FINALIST])
                ->whereNotIn('id', $winnerIds)
                ->update(['status' => Candidacy::STATUS_DEFEATED, 'updated_at' => now()]);
        }

        // The delegated era closes; ESM-16 evolves the SAME row — I-EXC
        // dissolves into I-EEO.
        DB::table('executive_members')
            ->where('executive_id', $executive->id)
            ->where('selection', 'delegated_proportional')
            ->where('status', 'seated')
            ->update(['status' => 'left', 'left_at' => now()->toDateString(), 'updated_at' => now()]);

        $executive->forceFill([
            'status'         => 'elected',
            'type'           => $type,
            'converted_at'   => now(),
            'term_number'    => ((int) $executive->term_number) + ($executive->converted_at !== null ? 1 : 0),
            'term_starts_on' => $window['starts_on']->toDateString(),
            'term_ends_on'   => $window['ends_on']->toDateString(),
        ])->save();

        $this->roles->flush();

        return [
            'winners'     => $winners,
            'terms'       => $terms,
            'term_window' => [
                'starts_on' => $window['starts_on']->toDateString(),
                'ends_on'   => $window['ends_on']->toDateString(),
                'inherited' => true,
            ],
            'executive'   => [
                'id'     => (string) $executive->id,
                'status' => $executive->refresh()->status,
                'type'   => $executive->type,
            ],
            'next_election_id' => null,
            'vacancy_filled'   => null,
            'referendums'      => [],
        ];
    }

    /**
     * @param  array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}  $window
     * @return array{0: \App\Models\ExecutiveMember, 1: Term}
     */
    private function seatExecutiveMember(
        \App\Models\Executive $executive,
        Election $election,
        ElectionRace $race,
        Candidacy $candidacy,
        string $role,
        int $rank,
        string $selection,
        array $window,
    ): array {
        $member = \App\Models\ExecutiveMember::create([
            'executive_id'       => (string) $executive->id,
            'user_id'            => (string) $candidacy->user_id,
            'role'               => $role,
            'rank'               => $rank,
            'joined_at'          => $window['starts_on']->toDateString(),
            'elected_in_race_id' => (string) $race->id,
            'selection'          => $selection,
            'status'             => 'seated',
        ]);

        $term = Term::create([
            'office_kind'        => 'executive_seat',
            'office_type'        => 'executive_members',
            'office_id'          => (string) $member->id,
            'holder_user_id'     => (string) $candidacy->user_id,
            'jurisdiction_id'    => (string) $election->jurisdiction_id,
            'legislature_id'     => (string) $election->legislature_id,
            'term_class'         => Term::CLASS_LOCKSTEP,
            'starts_on'          => $window['starts_on']->toDateString(),
            'ends_on'            => $window['ends_on']->toDateString(),
            'source_election_id' => (string) $election->id,
            'status'             => Term::STATUS_ACTIVE,
        ]);

        $member->forceFill(['term_id' => (string) $term->id])->save();
        $candidacy->forceFill(['status' => Candidacy::STATUS_ELECTED])->save();

        // CLK-10 flag — same derived-lockstep observability as chamber seats.
        $this->clocks->arm(
            'CLK-10',
            (string) $election->jurisdiction_id,
            'term',
            (string) $term->id,
            null,
            ['step' => 'lockstep', 'ends_on' => $window['ends_on']->toDateString()],
        );

        return [$member, $term];
    }

    // =========================================================================
    // Judicial certification (Phase E — Art. IV §3/§4; design §B.5)
    // =========================================================================

    /**
     * Seat a judicial election's STV winners as judicial_seats
     * (seat_class 'elected'):
     *
     *  - ONE judicial_group race (PR-STV/Droop, the UNTOUCHED counting
     *    path): every seated result → a judicial_seats row, holder set,
     *    status 'seated', provenance via elected_in_race_id;
     *  - TERMS (lockstep, CLK-10): every elected judge gets a terms row
     *    office_kind 'judicial_seat', term_class 'lockstep' — the FIRST
     *    election after conversion uses inheritedWindow ending on the
     *    chartering legislature's CURRENT term_ends_on (term = remainder;
     *    conversion never resets lockstep, Art. IV §3);
     *  - the appointed-era judicial_seats rows close ('term_ended', their
     *    CLK-09 timers cancelled) — the appointed bench's civil terms
     *    complete; the judiciary row evolves conversion_voted → elected,
     *    type 'elected', converted_at set. I-JUD evolves on the SAME row
     *    (no second judiciary row — ESM-18 is one machine). There are NO
     *    advisors (judges are elected in a group, Art. IV §3).
     */
    private function certifyJudicial(Election $election, ElectionCertification $certification): array
    {
        $legislature = $election->legislature;
        $judiciary   = $election->judiciary_id !== null
            ? \App\Models\Judiciary::query()->find((string) $election->judiciary_id)
            : null;

        if ($legislature === null || $judiciary === null) {
            throw new ConstitutionalViolation(
                'A judicial election certifies against its legislature (lockstep anchor) and judiciary.',
                'Art. IV §3'
            );
        }

        if ($legislature->term_ends_on === null) {
            throw new ConstitutionalViolation(
                'No lockstep expiry exists to inherit — the chamber has no term schedule.',
                'Art. IV §3'
            );
        }

        $certifiedAt = $certification->certified_at !== null
            ? CarbonImmutable::parse($certification->certified_at)
            : CarbonImmutable::now('UTC');

        // First election after conversion inherits the chamber's remaining
        // lockstep window (conversion never resets lockstep — CLK-10).
        $window = self::inheritedWindow($certifiedAt, CarbonImmutable::parse($legislature->term_ends_on));

        $winners   = [];
        $terms     = [];
        $seatCount = 0;

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

                [$seat, $term] = $this->seatJudicialMember(
                    judiciary: $judiciary,
                    election: $election,
                    race: $race,
                    candidacy: $candidacy,
                    seatNumber: ++$seatCount,
                    window: $window,
                );

                $winnerIds[] = (string) $candidacy->id;

                $winners[] = [
                    'user_id'      => (string) $candidacy->user_id,
                    'candidacy_id' => (string) $candidacy->id,
                    'race_id'      => (string) $race->id,
                    'seat_id'      => (string) $seat->id,
                    'seat_number'  => (int) $seat->seat_number,
                ];

                $terms[] = [
                    'term_id'        => (string) $term->id,
                    'holder_user_id' => (string) $term->holder_user_id,
                    'starts_on'      => $term->starts_on->toDateString(),
                    'ends_on'        => $term->ends_on->toDateString(),
                    'term_class'     => $term->term_class,
                ];
            }

            Candidacy::query()
                ->where('race_id', $race->id)
                ->whereIn('status', [Candidacy::STATUS_FINALIST, Candidacy::STATUS_NON_FINALIST])
                ->whereNotIn('id', $winnerIds)
                ->update(['status' => Candidacy::STATUS_DEFEATED, 'updated_at' => now()]);
        }

        // The appointed bench closes: each seated appointed seat → term_ended,
        // its civil-appointment term completes, CLK-09 timer cancelled (the
        // governor-removal term-cancellation mechanics).
        $this->closeAppointedBench($judiciary);

        // The judiciary EVOLVES the SAME row (ESM-18 — one machine).
        $judiciary->forceFill([
            'status'       => \App\Models\Judiciary::STATUS_ELECTED,
            'type'         => \App\Models\Judiciary::TYPE_ELECTED,
            'converted_at' => now(),
            'judge_count'  => $seatCount,
        ])->save();

        $this->roles->flush();

        return [
            'winners'     => $winners,
            'terms'       => $terms,
            'term_window' => [
                'starts_on' => $window['starts_on']->toDateString(),
                'ends_on'   => $window['ends_on']->toDateString(),
                'inherited' => true,
            ],
            'judiciary'   => [
                'id'     => (string) $judiciary->id,
                'status' => $judiciary->refresh()->status,
                'type'   => $judiciary->type,
            ],
            'next_election_id' => null,
            'vacancy_filled'   => null,
            'referendums'      => [],
        ];
    }

    /**
     * @param  array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}  $window
     * @return array{0: \App\Models\JudicialSeat, 1: Term}
     */
    private function seatJudicialMember(
        \App\Models\Judiciary $judiciary,
        Election $election,
        ElectionRace $race,
        Candidacy $candidacy,
        int $seatNumber,
        array $window,
    ): array {
        $seat = \App\Models\JudicialSeat::create([
            'judiciary_id'       => (string) $judiciary->id,
            'user_id'            => (string) $candidacy->user_id,
            'seat_number'        => $seatNumber,
            'seat_class'         => \App\Models\JudicialSeat::CLASS_ELECTED,
            'elected_in_race_id' => (string) $race->id,
            'term_starts_on'     => $window['starts_on']->toDateString(),
            'term_ends_on'       => $window['ends_on']->toDateString(),
            'status'             => \App\Models\JudicialSeat::STATUS_SEATED,
        ]);

        $term = Term::create([
            'office_kind'        => 'judicial_seat',
            'office_type'        => 'judicial_seats',
            'office_id'          => (string) $seat->id,
            'holder_user_id'     => (string) $candidacy->user_id,
            'jurisdiction_id'    => (string) $election->jurisdiction_id,
            'legislature_id'     => (string) $election->legislature_id,
            'term_class'         => Term::CLASS_LOCKSTEP,
            'starts_on'          => $window['starts_on']->toDateString(),
            'ends_on'            => $window['ends_on']->toDateString(),
            'source_election_id' => (string) $election->id,
            'status'             => Term::STATUS_ACTIVE,
        ]);

        $seat->forceFill(['term_id' => (string) $term->id])->save();
        $candidacy->forceFill(['status' => Candidacy::STATUS_ELECTED])->save();

        // CLK-10 flag — same derived-lockstep observability as chamber seats.
        $this->clocks->arm(
            'CLK-10',
            (string) $election->jurisdiction_id,
            'term',
            (string) $term->id,
            null,
            ['step' => 'lockstep', 'ends_on' => $window['ends_on']->toDateString()],
        );

        return [$seat, $term];
    }

    /**
     * Close the appointed-era bench on conversion to elected: each seated
     * appointed seat → term_ended, its civil-appointment term completes, its
     * CLK-09 expiry timer cancelled (the BoardGovernorService removal
     * term-cancellation mechanics). The rows close, never delete.
     */
    private function closeAppointedBench(\App\Models\Judiciary $judiciary): void
    {
        $seats = \App\Models\JudicialSeat::query()
            ->where('judiciary_id', $judiciary->id)
            ->where('status', \App\Models\JudicialSeat::STATUS_SEATED)
            ->whereIn('seat_class', [
                \App\Models\JudicialSeat::CLASS_CONSTITUENT_NOMINATED,
                \App\Models\JudicialSeat::CLASS_COMMITTEE_NOMINATED,
            ])
            ->get();

        foreach ($seats as $seat) {
            if ($seat->term_id !== null) {
                $term = Term::query()->whereKey($seat->term_id)->first();

                if ($term !== null && $term->status === Term::STATUS_ACTIVE) {
                    $term->forceFill(['status' => Term::STATUS_COMPLETED])->save();

                    foreach ($this->armedTimers('term', (string) $term->id, 'CLK-09') as $timer) {
                        $this->clocks->cancel($timer, 'appointed judge term closed on conversion to elected court');
                    }
                }
            }

            $holder = $seat->user_id !== null ? (string) $seat->user_id : null;

            $seat->forceFill(['status' => \App\Models\JudicialSeat::STATUS_TERM_ENDED])->save();

            if ($holder !== null) {
                $this->roles->flushUser($holder);
            }
        }
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

        // Phase D (design §B.1.5): delegated executive members are ex
        // officio — their executive seat ends with their legislative one.
        app(\App\Services\Executive\ExecutiveFormationService::class)
            ->closeDelegatedMembersOnTurnover($legislature);
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
