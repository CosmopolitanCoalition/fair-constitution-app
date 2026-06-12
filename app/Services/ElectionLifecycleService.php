<?php

namespace App\Services;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\ElectionSchedulingDelegate;
use App\Models\Candidacy;
use App\Models\ClockTimer;
use App\Models\Election;
use App\Models\ElectionBoard;
use App\Models\ElectionRace;
use App\Models\Legislature;
use App\Models\LegislatureDistrict;
use App\Models\LegislatureDistrictMap;
use App\Models\LegislatureMember;
use App\Models\Vacancy;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WI-B3 — THE single authority for ESM-03 election phase moves
 * (PHASE_B_DESIGN_schema_lifecycle §B.1–B.4).
 *
 *   scheduled → approval_open → finalist_cutoff → ranked_open →
 *   voting_closed → tabulating → certified → final
 *                                certified → audit_rerun → final
 *   (cancelled reachable only before the ranked window opens)
 *
 * Every transition is audited (module 'elections', ref ESM-03); no other
 * code path may flip `elections.status`. Elections fire from clocks
 * (`triggered_by_timer_id`), never official discretion — the no-skip
 * guarantee is that no API here (or anywhere) moves CLK-01 `fires_at`.
 *
 * Race generation implements the §B.4 ruling (Art. II §8):
 *  - active district map        → one race per district (seats from district)
 *  - no map, seats ≤ max (9)    → ONE at-large race — the constitutional
 *                                 default, not a workaround
 *  - no map, seats > max        → the election is BLOCKED pending
 *                                 subdivision: row stays `scheduled`, no
 *                                 races, a rejected=true chain entry
 *                                 records the posture (citation Art. II §8).
 *                                 WI-B7's initial-map generation / re-plan
 *                                 unblocks it (Montegiardino / San Marino).
 *  - type_b seats               → at-large by construction (Art. V §3);
 *                                 type_b > max is the deferred Earth-scale
 *                                 operator ruling — blocked with citation.
 *
 * CLK-21 is a derived formula, not a timer: finalist_count =
 * finalist_multiplier × seats, resolved per jurisdiction at race creation
 * (SettingsResolver ancestor walk; the registry CLK-21 row carries no
 * setting_key, so resolution goes straight to the `finalist_multiplier`
 * column) and FROZEN into election_races.finalist_count — later amendments
 * never move a published cutoff.
 *
 * Dev compression: config('cga.election_demo_compression') (minutes, > 0)
 * shrinks the default phase windows for demos — config, never data; the
 * approval_min_days floor is only ever bypassed via config.
 *
 * Implements WI-B4's ElectionSchedulingDelegate seam (the F-ELB-001
 * handler delegates map-derived race generation + phase-timer arming
 * here). The orchestrator rebinds the contract from
 * NoopElectionSchedulingDelegate to this service in ConstitutionProvider;
 * until that rebind, ScheduleGeneralElectionJob detects the no-op binding
 * and takes the direct (equally audited) path.
 */
class ElectionLifecycleService implements ElectionSchedulingDelegate
{
    /** ESM-03 adjacency — the only legal moves. */
    public const TRANSITIONS = [
        Election::STATUS_SCHEDULED       => [Election::STATUS_APPROVAL_OPEN, Election::STATUS_CANCELLED],
        Election::STATUS_APPROVAL_OPEN   => [Election::STATUS_FINALIST_CUTOFF, Election::STATUS_CANCELLED],
        Election::STATUS_FINALIST_CUTOFF => [Election::STATUS_RANKED_OPEN, Election::STATUS_CANCELLED],
        Election::STATUS_RANKED_OPEN     => [Election::STATUS_VOTING_CLOSED],
        Election::STATUS_VOTING_CLOSED   => [Election::STATUS_TABULATING],
        Election::STATUS_TABULATING      => [Election::STATUS_CERTIFIED],
        Election::STATUS_CERTIFIED       => [Election::STATUS_AUDIT_RERUN, Election::STATUS_FINAL],
        Election::STATUS_AUDIT_RERUN     => [Election::STATUS_CERTIFIED, Election::STATUS_FINAL],
        Election::STATUS_FINAL           => [],
        Election::STATUS_CANCELLED       => [],
    ];

    /** Race statuses that simply mirror the parent election phase. */
    private const MIRRORED_RACE_STATUSES = [
        Election::STATUS_SCHEDULED,
        Election::STATUS_APPROVAL_OPEN,
        Election::STATUS_FINALIST_CUTOFF,
        Election::STATUS_RANKED_OPEN,
        Election::STATUS_VOTING_CLOSED,
    ];

    /** Steps carried by the election-subject phase timers (cancel set on re-arm). */
    private const PHASE_TIMER_STEPS = ['finalist_cutoff', 'ranked_open', 'ranked_close'];

    /** Gap between the finalist cutoff and the ranked window opening (days). */
    public const RANKED_GAP_DAYS = 1;

    public function __construct(
        private readonly AuditService $audit,
        private readonly ClockService $clocks,
        private readonly SettingsResolver $settings,
        private readonly ApprovalService $approvals,
    ) {
    }

    // =========================================================================
    // Scheduling (CLK-01 / CLK-18 open / bootstrap F-ELB-001)
    // =========================================================================

    /**
     * Schedule (or confirm) the next general election for a legislature.
     *
     * CLK-01 'schedule_general' entry point — also the direct path while
     * the F-ELB-001 handler (WI-B4) is not yet registered, and the
     * bootstrap path's workhorse (WI-B7 step 3.5 calls through F-ELB-001).
     *
     * Adopts an existing open-cycle election when one exists (created at
     * certification of the prior election with its approval phase already
     * open — §B.2.1) and confirms/sets its dates; otherwise creates the
     * cycle's election. Races are generated per §B.4; a blocked outcome
     * (seats > max with no active map) leaves the row `scheduled` with the
     * rejection recorded and NO timers armed.
     *
     * $dates may carry approval_opens_at / finalist_cutoff_at /
     * ranked_opens_at / ranked_closes_at overrides (the F-ELB-001
     * refine-within-bounds path). Ordering is enforced here; the
     * cutoff ≥ opens + approval_min_days floor is enforced unless dev
     * compression is configured.
     */
    public function scheduleGeneral(Legislature $legislature, ?ClockTimer $timer = null, array $dates = []): Election
    {
        $jurisdictionId = $legislature->jurisdiction_id;

        return DB::transaction(function () use ($legislature, $timer, $dates, $jurisdictionId) {
            $election = Election::query()
                ->where('legislature_id', $legislature->id)
                ->where('kind', Election::KIND_GENERAL)
                ->whereIn('status', [Election::STATUS_SCHEDULED, Election::STATUS_APPROVAL_OPEN])
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            $created = false;

            if ($election === null) {
                $election = Election::create([
                    'jurisdiction_id'       => $jurisdictionId,
                    'legislature_id'        => $legislature->id,
                    'kind'                  => Election::KIND_GENERAL,
                    'status'                => Election::STATUS_SCHEDULED,
                    'trigger'               => $timer !== null ? 'scheduled' : 'manual',
                    'voting_method'         => $this->votingMethodSnapshot($jurisdictionId),
                    'election_board_id'     => $this->activeBoardId($jurisdictionId),
                    'triggered_by_timer_id' => $timer?->id,
                ]);
                $created = true;
            } elseif ($timer !== null && $election->triggered_by_timer_id === null) {
                $election->forceFill(['triggered_by_timer_id' => $timer->id])->save();
            }

            $this->applySchedule($election, $dates);

            $plan = $this->racePlan($legislature);

            if ($plan['blocked']) {
                $this->recordBlocked($election, $legislature, $plan);

                return $election->refresh();
            }

            $races = $this->createRaces($election, $legislature, $plan);

            $this->audit->append(
                module: 'elections',
                event: 'election.scheduled',
                payload: [
                    'election_id'        => $election->id,
                    'legislature_id'     => $legislature->id,
                    'kind'               => $election->kind,
                    'created'            => $created,
                    'district_map_id'    => $election->district_map_id,
                    'approval_opens_at'  => $election->approval_opens_at?->toIso8601String(),
                    'finalist_cutoff_at' => $election->finalist_cutoff_at?->toIso8601String(),
                    'ranked_opens_at'    => $election->ranked_opens_at?->toIso8601String(),
                    'ranked_closes_at'   => $election->ranked_closes_at?->toIso8601String(),
                    // X pre-published with the scheduling order (CLK-21).
                    'races'              => array_map(fn (ElectionRace $r) => [
                        'race_id'        => $r->id,
                        'district_id'    => $r->district_id,
                        'seat_kind'      => $r->seat_kind,
                        'seats'          => $r->seats,
                        'finalist_count' => $r->finalist_count,
                    ], $races),
                ],
                ref: 'F-ELB-001',
                jurisdictionId: $jurisdictionId,
            );

            if ($election->status === Election::STATUS_SCHEDULED) {
                $this->openApproval($election);
            }

            $this->armPhaseTimers($election);

            return $election->refresh();
        });
    }

    /**
     * §B.2.1 — inside the certification transaction of election N the
     * handler (WI-B5, F-ELB-004) creates election N+1 with its approval
     * phase open immediately (no dead period; CLK-18 window opens at
     * certification). Dates stay NULL until the next CLK-01 fire confirms
     * them through scheduleGeneral().
     */
    public function openSuccessor(Election $certified): Election
    {
        $legislature = $certified->legislature;

        if ($legislature === null) {
            throw new ConstitutionalViolation(
                'Cannot open a successor approval phase for an election without a legislature.',
                'Art. II §2'
            );
        }

        return DB::transaction(function () use ($certified, $legislature) {
            $successor = Election::create([
                'jurisdiction_id'   => $certified->jurisdiction_id,
                'legislature_id'    => $legislature->id,
                'kind'              => Election::KIND_GENERAL,
                'status'            => Election::STATUS_SCHEDULED,
                'trigger'           => 'scheduled',
                'voting_method'     => $this->votingMethodSnapshot($certified->jurisdiction_id),
                'election_board_id' => $this->activeBoardId($certified->jurisdiction_id),
                'prior_election_id' => $certified->id,
                'approval_opens_at' => now(),
            ]);

            $plan = $this->racePlan($legislature);

            if ($plan['blocked']) {
                $this->recordBlocked($successor, $legislature, $plan);
            } else {
                $this->createRaces($successor, $legislature, $plan);
                $this->openApproval($successor);
            }

            return $successor->refresh();
        });
    }

    /**
     * Schedule the special election for a vacancy (countback exhausted —
     * §B.2.6), or force-schedule it from the CLK-04 backstop. The ranked
     * window must fall inside [declared_at + special_election_min_days,
     * declared_at + special_election_max_days] (Art. II §5); a forced
     * backstop schedule past the window records the violation (the
     * backstop's own audit entry) but still produces an election —
     * discretion can never produce "no election".
     */
    public function scheduleSpecial(Vacancy $vacancy, ?ClockTimer $timer = null, bool $forced = false): Election
    {
        $declaredAt = $vacancy->declared_at ?? $vacancy->detected_at ?? now();
        $minDays    = $this->settings->resolveInt($vacancy->jurisdiction_id, 'special_election_min_days', 90);
        $maxDays    = $this->settings->resolveInt($vacancy->jurisdiction_id, 'special_election_max_days', 180);

        $windowOpens  = $declaredAt->copy()->addDays($minDays);
        $windowCloses = $declaredAt->copy()->addDays($maxDays);

        return DB::transaction(function () use ($vacancy, $timer, $forced, $windowOpens, $windowCloses) {
            if ($vacancy->special_election_id !== null) {
                return Election::query()->findOrFail($vacancy->special_election_id);
            }

            [$member, $originalRace] = $this->vacatedSeat($vacancy);

            $approvalMinDays  = $this->approvalMinDays($vacancy->jurisdiction_id);
            $rankedWindowDays = $this->rankedWindowDays($vacancy->jurisdiction_id);

            $opens  = now();
            $cutoff = $opens->copy()->addDays($approvalMinDays);
            $rOpen  = $cutoff->copy()->addDays(self::RANKED_GAP_DAYS);
            if ($rOpen->lt($windowOpens)) {
                $rOpen = $windowOpens->copy();
            }
            $rClose = $rOpen->copy()->addDays($rankedWindowDays);

            if ($rClose->gt($windowCloses) && ! $forced) {
                throw new ConstitutionalViolation(
                    'Special election ranked window falls outside the constitutional window.',
                    'Art. II §5'
                );
            }

            $raceJurisdiction = $originalRace?->jurisdiction_id ?? $vacancy->jurisdiction_id;

            $election = Election::create([
                'jurisdiction_id'       => $vacancy->jurisdiction_id,
                'legislature_id'        => $vacancy->legislature_id,
                'kind'                  => Election::KIND_SPECIAL,
                'status'                => Election::STATUS_SCHEDULED,
                'trigger'               => 'vacancy',
                'voting_method'         => $this->votingMethodSnapshot($vacancy->jurisdiction_id),
                'election_board_id'     => $this->activeBoardId($vacancy->jurisdiction_id),
                'vacancy_id'            => $vacancy->id,
                'triggered_by_timer_id' => $timer?->id,
                'approval_opens_at'     => $opens,
                'finalist_cutoff_at'    => $cutoff,
                'ranked_opens_at'       => $rOpen,
                'ranked_closes_at'      => $rClose,
            ]);

            // ONE race, scoped to the vacated seat's district (or at-large
            // footprint), for exactly the vacant seat.
            $race = ElectionRace::create([
                'election_id'     => $election->id,
                'district_id'     => $originalRace?->district_id,
                'jurisdiction_id' => $raceJurisdiction,
                'seat_kind'       => $originalRace?->seat_kind ?? ($member?->seat_type === 'b' ? ElectionRace::SEAT_KIND_TYPE_B : ElectionRace::SEAT_KIND_TYPE_A),
                'seats'           => 1,
                'finalist_count'  => max(1, $this->finalistMultiplier($vacancy->jurisdiction_id)),
                'electorate_type' => ElectionRace::ELECTORATE_RESIDENTS,
                'status'          => Election::STATUS_SCHEDULED,
            ]);

            $vacancy->forceFill([
                'status'              => Vacancy::STATUS_SPECIAL_SCHEDULED,
                'special_election_id' => $election->id,
            ])->save();

            $this->audit->append(
                module: 'elections',
                event: 'election.scheduled',
                payload: [
                    'election_id'        => $election->id,
                    'kind'               => Election::KIND_SPECIAL,
                    'vacancy_id'         => $vacancy->id,
                    'forced_by_backstop' => $forced,
                    'window_opens_at'    => $windowOpens->toIso8601String(),
                    'window_closes_at'   => $windowCloses->toIso8601String(),
                    'ranked_opens_at'    => $rOpen->toIso8601String(),
                    'ranked_closes_at'   => $rClose->toIso8601String(),
                    'races'              => [[
                        'race_id'        => $race->id,
                        'district_id'    => $race->district_id,
                        'seat_kind'      => $race->seat_kind,
                        'seats'          => $race->seats,
                        'finalist_count' => $race->finalist_count,
                    ]],
                ],
                ref: 'CLK-04',
                jurisdictionId: $vacancy->jurisdiction_id,
            );

            $this->openApproval($election);
            $this->armPhaseTimers($election);

            return $election->refresh();
        });
    }

    // =========================================================================
    // Race generation — the §B.4 ruling
    // =========================================================================

    /**
     * Compute the race structure for a legislature under the §B.4 ruling.
     *
     * @return array{
     *   blocked: bool,
     *   district_map_id: ?string,
     *   kinds: array<string, array{mode: string, seats?: int, reason?: string, citation?: string, districts?: \Illuminate\Support\Collection}>
     * }
     */
    public function racePlan(Legislature $legislature): array
    {
        $ceiling = ConstitutionalDefaults::ceiling($legislature->jurisdiction_id);

        $activeMap = LegislatureDistrictMap::query()
            ->where('legislature_id', $legislature->id)
            ->active()
            ->first();

        $districts = $activeMap === null
            ? collect()
            : LegislatureDistrict::query()->where('map_id', $activeMap->id)->get();

        $kinds   = [];
        $blocked = false;

        $typeA = (int) $legislature->type_a_seats;

        if ($districts->isNotEmpty()) {
            $outOfBounds = $districts->first(fn ($d) => $d->seats < 1 || $d->seats > $ceiling);

            if ($outOfBounds !== null) {
                $kinds['type_a'] = [
                    'mode'     => 'blocked',
                    'reason'   => "district {$outOfBounds->id} has {$outOfBounds->seats} seats (allowed 1–{$ceiling})",
                    'citation' => 'Art. II §2; Art. II §8',
                ];
                $blocked = true;
            } else {
                $kinds['type_a'] = ['mode' => 'districts', 'districts' => $districts];
            }
        } elseif ($typeA >= 1 && $typeA <= $ceiling) {
            // No map, seats within the max: ONE at-large race is the
            // constitutional default (Art. II §8 — subdivision is
            // forbidden unless seats exceed the max).
            $kinds['type_a'] = ['mode' => 'at_large', 'seats' => $typeA];
        } else {
            $kinds['type_a'] = [
                'mode'     => 'blocked',
                'reason'   => "type_a_seats {$typeA} exceeds the per-race maximum {$ceiling} and no active district map exists — subdivision is mandatory before any election",
                'citation' => 'Art. II §8',
            ];
            $blocked = true;
        }

        $typeB = (int) $legislature->type_b_seats;

        if ($typeB > 0) {
            if ($typeB <= $ceiling) {
                // At-large by construction — elected by the whole
                // population (Art. V §3).
                $kinds['type_b'] = ['mode' => 'at_large', 'seats' => $typeB];
            } else {
                // Earth-scale type_b has no constitutional grouping
                // mechanism — deferred pending an operator ruling (§B.4).
                $kinds['type_b'] = [
                    'mode'     => 'blocked',
                    'reason'   => "type_b_seats {$typeB} exceeds the per-race maximum {$ceiling}; at-large grouping is constitutionally undefined (operator ruling required)",
                    'citation' => 'Art. V §3; Art. II §8',
                ];
                $blocked = true;
            }
        }

        return [
            'blocked'         => $blocked,
            'district_map_id' => $districts->isNotEmpty() ? $activeMap?->id : null,
            'kinds'           => $kinds,
        ];
    }

    /**
     * ElectionSchedulingDelegate::generateRaces — the F-ELB-001 handler's
     * map-derived race generation seam (WI-B4). A blocked plan (§B.4) is a
     * SCHEDULING-TIME ENGINE REJECTION here: the violation rolls the whole
     * filing back and the engine records the rejected=true chain entry
     * with the citation (counting design §A.5 — "never a counting-time
     * fudge"). The clock-driven direct path (scheduleGeneral) records the
     * persisted blocked posture instead.
     *
     * @return list<array{race_id: string, district_id: string|null, seats: int, finalist_count: int}>
     */
    public function generateRaces(Election $election, array $payload = []): array
    {
        $legislature = $election->legislature;

        if ($legislature === null) {
            return [];
        }

        $plan = $this->racePlan($legislature);

        if ($plan['blocked']) {
            $reasons = collect($plan['kinds'])
                ->filter(fn ($spec) => $spec['mode'] === 'blocked')
                ->map(fn ($spec, $kind) => "{$kind}: {$spec['reason']}")
                ->implode('; ');

            throw new ConstitutionalViolation(
                "Race generation is blocked pending subdivision — {$reasons}.",
                'Art. II §8'
            );
        }

        return array_map(fn (ElectionRace $r) => [
            'race_id'        => (string) $r->id,
            'district_id'    => $r->district_id !== null ? (string) $r->district_id : null,
            'seats'          => (int) $r->seats,
            'finalist_count' => (int) $r->finalist_count,
        ], $this->createRaces($election, $legislature, $plan));
    }

    /**
     * Materialize the plan into election_races rows (idempotent: an
     * election that already has races keeps them). X = finalist_multiplier
     * × seats (CLK-21) is resolved here and frozen per race.
     *
     * @return ElectionRace[]
     */
    public function createRaces(Election $election, Legislature $legislature, ?array $plan = null): array
    {
        $plan ??= $this->racePlan($legislature);

        if ($plan['blocked']) {
            throw new ConstitutionalViolation(
                'Race generation is blocked pending subdivision.',
                'Art. II §8'
            );
        }

        $existing = $election->races()->get();
        if ($existing->isNotEmpty()) {
            return $existing->all();
        }

        $multiplier = $this->finalistMultiplier($legislature->jurisdiction_id);
        $races      = [];

        foreach ($plan['kinds'] as $kind => $spec) {
            if ($spec['mode'] === 'districts') {
                foreach ($spec['districts'] as $district) {
                    $races[] = ElectionRace::create([
                        'election_id'     => $election->id,
                        'district_id'     => $district->id,
                        'jurisdiction_id' => $district->jurisdiction_id ?? $legislature->jurisdiction_id,
                        'seat_kind'       => $kind,
                        'seats'           => (int) $district->seats,
                        'finalist_count'  => $multiplier * (int) $district->seats,
                        'electorate_type' => ElectionRace::ELECTORATE_RESIDENTS,
                        'status'          => $election->status,
                    ]);
                }
            } else { // at_large
                $races[] = ElectionRace::create([
                    'election_id'     => $election->id,
                    'district_id'     => null,
                    'jurisdiction_id' => $legislature->jurisdiction_id,
                    'seat_kind'       => $kind,
                    'seats'           => (int) $spec['seats'],
                    'finalist_count'  => $multiplier * (int) $spec['seats'],
                    'electorate_type' => ElectionRace::ELECTORATE_RESIDENTS,
                    'status'          => $election->status,
                ]);
            }
        }

        if ($plan['district_map_id'] !== null && $election->district_map_id === null) {
            $election->forceFill(['district_map_id' => $plan['district_map_id']])->save();
        }

        return $races;
    }

    // =========================================================================
    // Phase moves (single ESM-03 authority — each audited)
    // =========================================================================

    /** scheduled → approval_open (CLK-18 window opens; WF-CIV-08 begins). */
    public function openApproval(Election $election): Election
    {
        return $this->transition($election, Election::STATUS_APPROVAL_OPEN, attributes: [
            'approval_opens_at' => $election->approval_opens_at ?? now(),
        ]);
    }

    /**
     * approval_open → finalist_cutoff (CLK-18 close / CLK-21) — one
     * transaction: final frozen standings rollup per race, top
     * `finalist_count` candidacies → finalist, the rest → non_finalist
     * (WRITE-IN ELIGIBLE — the right to stand is preserved, Art. I),
     * withdrawals locked from here (F-CAN-003 enforces against
     * finalist_cutoff_at). Ties at the line break by earlier validated_at
     * (registration seniority), recorded with citation.
     */
    public function applyFinalistCutoff(Election $election): Election
    {
        return DB::transaction(function () use ($election) {
            $fresh = Election::query()->whereKey($election->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->status !== Election::STATUS_APPROVAL_OPEN) {
                return $fresh ?? $election; // idempotent no-op (timer re-fires, races already cut)
            }

            foreach ($fresh->races()->get() as $race) {
                $standings = $this->approvals->rollupRace($race, freeze: true);

                $eligible = Candidacy::query()
                    ->where('race_id', $race->id)
                    ->whereIn('status', [Candidacy::STATUS_VALIDATED, Candidacy::STATUS_IN_POOL])
                    ->get()
                    ->keyBy('id');

                $counts = collect($standings)->pluck('approvals_count', 'candidacy_id');

                $ranked = $eligible->values()->sort(function (Candidacy $a, Candidacy $b) use ($counts) {
                    $byCount = ($counts[$b->id] ?? 0) <=> ($counts[$a->id] ?? 0);
                    if ($byCount !== 0) {
                        return $byCount;
                    }
                    // Tie at the line: registration seniority (earlier
                    // validated_at), then id — deterministic. Citation
                    // 'Art. II §2 · as implemented'.
                    $bySeniority = ($a->validated_at?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->validated_at?->getTimestamp() ?? PHP_INT_MAX);

                    return $bySeniority !== 0 ? $bySeniority : strcmp($a->id, $b->id);
                })->values();

                $finalists = $ranked->take($race->finalist_count);
                $rest      = $ranked->slice($race->finalist_count);

                $tieAtLine = $finalists->isNotEmpty() && $rest->isNotEmpty()
                    && ($counts[$finalists->last()->id] ?? 0) === ($counts[$rest->first()->id] ?? 0);

                foreach ($finalists as $candidacy) {
                    $candidacy->forceFill(['status' => Candidacy::STATUS_FINALIST])->save();
                }
                foreach ($rest as $candidacy) {
                    $candidacy->forceFill(['status' => Candidacy::STATUS_NON_FINALIST])->save();
                }

                $this->audit->append(
                    module: 'elections',
                    event: 'race.finalists_locked',
                    payload: array_filter([
                        'race_id'        => $race->id,
                        'election_id'    => $fresh->id,
                        'finalist_count' => $race->finalist_count,
                        'finalists'      => $finalists->pluck('id')->all(),
                        'non_finalists'  => $rest->pluck('id')->values()->all(),
                        'tie_break'      => $tieAtLine ? [
                            'stage'    => 'registration_seniority',
                            'citation' => 'Art. II §2 · as implemented',
                            'flag'     => 'pending_operator_ratification',
                        ] : null,
                    ], fn ($v) => $v !== null),
                    ref: 'CLK-21',
                    jurisdictionId: $fresh->jurisdiction_id,
                );
            }

            return $this->transition($fresh, Election::STATUS_FINALIST_CUTOFF);
        });
    }

    /** finalist_cutoff → ranked_open (F-IND-007 ballots commit from here). */
    public function openRanked(Election $election): Election
    {
        if ($election->status !== Election::STATUS_FINALIST_CUTOFF) {
            return $election; // idempotent for timer re-fires
        }

        return $this->transition($election, Election::STATUS_RANKED_OPEN);
    }

    /** ranked_open → voting_closed (tabulation is dispatched by the caller — WI-B5). */
    public function closeRanked(Election $election): Election
    {
        if ($election->status !== Election::STATUS_RANKED_OPEN) {
            return $election; // idempotent for timer re-fires
        }

        return $this->transition($election, Election::STATUS_VOTING_CLOSED);
    }

    /** voting_closed → tabulating (TabulateElectionJob, WI-B5). */
    public function markTabulating(Election $election): Election
    {
        return $this->transition($election, Election::STATUS_TABULATING);
    }

    /**
     * tabulating|audit_rerun → certified (F-ELB-004, WI-B5). The
     * certification side-effect block (winners → members → terms →
     * legislature active → CLK-01/CLK-10 arming → successor approval
     * phase) lives in the handler; this is the status authority only.
     */
    public function markCertified(Election $election, array $context = []): Election
    {
        return $this->transition($election, Election::STATUS_CERTIFIED, $context, [
            'certified_at' => now(),
        ]);
    }

    /** certified → audit_rerun (F-ELB-006). */
    public function markAuditRerun(Election $election, array $context = []): Election
    {
        return $this->transition($election, Election::STATUS_AUDIT_RERUN, $context);
    }

    /** certified|audit_rerun → final. */
    public function markFinal(Election $election, array $context = []): Election
    {
        return $this->transition($election, Election::STATUS_FINAL, $context);
    }

    /** Pre-ranked-window cancellation (never available once ballots exist). */
    public function cancel(Election $election, string $reason): Election
    {
        return $this->transition($election, Election::STATUS_CANCELLED, ['reason' => $reason]);
    }

    // =========================================================================
    // Clock arming
    // =========================================================================

    /**
     * Arm the election-subject phase timers (§B.1 table):
     *   CLK-18 @ finalist_cutoff_at  (step finalist_cutoff → FinalistCutoffJob)
     *   CLK-01 @ ranked_opens_at     (step ranked_open  → AdvanceElectionPhaseJob)
     *   CLK-01 @ ranked_closes_at    (step ranked_close → AdvanceElectionPhaseJob)
     * Previously armed phase timers for this election are cancelled first
     * (date refinement re-arms; every arm/cancel is chain-audited by
     * ClockService).
     */
    public function armPhaseTimers(Election $election): void
    {
        $armed = ClockTimer::query()
            ->armed()
            ->where('subject_type', 'election')
            ->where('subject_id', $election->id)
            ->get()
            ->filter(fn (ClockTimer $t) => in_array($t->payload['step'] ?? null, self::PHASE_TIMER_STEPS, true));

        foreach ($armed as $stale) {
            $this->clocks->cancel($stale, 'phase dates re-confirmed');
        }

        if ($election->finalist_cutoff_at !== null) {
            $this->clocks->arm(
                'CLK-18',
                $election->jurisdiction_id,
                'election',
                $election->id,
                $election->finalist_cutoff_at,
                ['step' => 'finalist_cutoff'],
            );
        }

        if ($election->ranked_opens_at !== null) {
            $this->clocks->arm(
                'CLK-01',
                $election->jurisdiction_id,
                'election',
                $election->id,
                $election->ranked_opens_at,
                ['step' => 'ranked_open'],
            );
        }

        if ($election->ranked_closes_at !== null) {
            $this->clocks->arm(
                'CLK-01',
                $election->jurisdiction_id,
                'election',
                $election->id,
                $election->ranked_closes_at,
                ['step' => 'ranked_close'],
            );
        }
    }

    /**
     * Arm the next general-election cycle (called inside the F-ELB-004
     * certification transaction, WI-B5). fires_at = certified_at +
     * election_interval_months − (ranked_window_days + gap + cutoff lead),
     * so the NEXT certification lands at lockstep expiry (§B.1). All
     * values resolve per jurisdiction at arm time; there is NO API to move
     * this timer afterwards (the hardened no-skip guarantee).
     */
    public function armNextGeneralElection(Legislature $legislature, CarbonInterface $certifiedAt): ClockTimer
    {
        $jid = $legislature->jurisdiction_id;

        $intervalMonths = $this->settings->resolveInt($jid, 'election_interval_months', 60);
        $leadDays       = $this->rankedWindowDays($jid) + self::RANKED_GAP_DAYS + $this->approvalMinDays($jid);

        $firesAt = Carbon::instance($certifiedAt)->addMonths($intervalMonths)->subDays($leadDays);

        return $this->clocks->arm(
            'CLK-01',
            $jid,
            'legislature',
            $legislature->id,
            $firesAt,
            [
                'step'   => 'schedule_general',
                // C-B2 derivation anchor (PHASE_C_DESIGN_votes_laws §C.5):
                // a later election_interval_months setting bill re-derives
                // fires_at = anchor_at + months − lead_days through
                // ClockService::rederiveForSetting. lead_days is frozen
                // here so re-derivation moves ONLY the interval part.
                'derive' => [
                    'anchor_at' => Carbon::instance($certifiedAt)->toIso8601String(),
                    'unit'      => 'months',
                    'lead_days' => $leadDays,
                ],
            ],
        );
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * The one place election status changes. Row-locked, adjacency-checked
     * against TRANSITIONS, race statuses mirrored for the pre-tabulation
     * phases, audited in the same transaction.
     */
    private function transition(Election $election, string $to, array $context = [], array $attributes = []): Election
    {
        $apply = function () use ($election, $to, $context, $attributes): Election {
            $fresh = Election::query()->whereKey($election->id)->lockForUpdate()->firstOrFail();
            $from  = $fresh->status;

            if ($from === $to) {
                return $fresh; // idempotent
            }

            if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw new ConstitutionalViolation(
                    "Illegal election phase move {$from} → {$to} (ESM-03).",
                    'Art. II §2 · CGA open-ballot spec'
                );
            }

            $fresh->forceFill($attributes + ['status' => $to])->save();

            if (in_array($to, self::MIRRORED_RACE_STATUSES, true)) {
                ElectionRace::query()
                    ->where('election_id', $fresh->id)
                    ->whereIn('status', self::MIRRORED_RACE_STATUSES)
                    ->update(['status' => $to, 'updated_at' => now()]);
            }

            $this->audit->append(
                module: 'elections',
                event: 'phase.advanced',
                payload: array_merge([
                    'election_id' => $fresh->id,
                    'kind'        => $fresh->kind,
                    'from'        => $from,
                    'to'          => $to,
                ], $context),
                ref: 'ESM-03',
                jurisdictionId: $fresh->jurisdiction_id,
            );

            $election->setRawAttributes($fresh->getAttributes(), true);

            return $fresh;
        };

        return DB::transactionLevel() > 0 ? $apply() : DB::transaction($apply);
    }

    /**
     * Set/confirm the CLK-18 window + schedule on the election row.
     * Defaults come from defaultDates(); explicit overrides (the
     * F-ELB-001 refine-within-bounds path) must keep strict ordering, and
     * the approval_min_days floor binds unless dev compression is
     * configured (config, never data).
     */
    private function applySchedule(Election $election, array $dates): void
    {
        $jid         = $election->jurisdiction_id;
        $compression = (int) config('cga.election_demo_compression', 0);

        $opens = $this->asTime($dates['approval_opens_at'] ?? null)
            ?? $election->approval_opens_at
            ?? now();

        $defaults = $this->defaultDates($jid, $opens);

        $cutoff = $this->asTime($dates['finalist_cutoff_at'] ?? null)
            ?? $election->finalist_cutoff_at
            ?? $defaults['finalist_cutoff_at'];
        $rOpen = $this->asTime($dates['ranked_opens_at'] ?? null)
            ?? $election->ranked_opens_at
            ?? $defaults['ranked_opens_at'];
        $rClose = $this->asTime($dates['ranked_closes_at'] ?? null)
            ?? $election->ranked_closes_at
            ?? $defaults['ranked_closes_at'];

        if ($compression <= 0) {
            $approvalMinDays = $this->approvalMinDays($jid);

            if ($cutoff->lt($opens->copy()->addDays($approvalMinDays))) {
                throw new ConstitutionalViolation(
                    "Finalist cutoff must fall at least {$approvalMinDays} days after the approval phase opens.",
                    'Art. II §2 · CGA open-ballot spec'
                );
            }
        }

        if (! ($opens->lt($cutoff) && $cutoff->lt($rOpen) && $rOpen->lt($rClose))) {
            throw new ConstitutionalViolation(
                'Election schedule must be strictly ordered: approval opens < finalist cutoff < ranked opens < ranked closes.',
                'Art. II §2 · CGA open-ballot spec'
            );
        }

        $election->forceFill([
            'approval_opens_at'  => $opens,
            'finalist_cutoff_at' => $cutoff,
            'ranked_opens_at'    => $rOpen,
            'ranked_closes_at'   => $rClose,
        ])->save();
    }

    /**
     * The default CLK-18 window + schedule for a jurisdiction: cutoff =
     * max(opens, now) + approval_min_days, ranked window opens after a
     * one-day gap and runs ranked_window_days. Under dev compression every
     * step is N minutes from now instead (config, never data).
     *
     * @return array{approval_opens_at: Carbon, finalist_cutoff_at: Carbon, ranked_opens_at: Carbon, ranked_closes_at: Carbon}
     */
    public function defaultDates(string $jurisdictionId, ?CarbonInterface $opensAt = null): array
    {
        $compression = (int) config('cga.election_demo_compression', 0);
        $opens       = $opensAt !== null ? Carbon::instance($opensAt) : now();

        if ($compression > 0) {
            $cutoff = now()->addMinutes($compression);
            $rOpen  = $cutoff->copy()->addMinutes($compression);
            $rClose = $rOpen->copy()->addMinutes($compression);
        } else {
            $base   = $opens->isFuture() ? $opens->copy() : now();
            $cutoff = $base->copy()->addDays($this->approvalMinDays($jurisdictionId));
            $rOpen  = $cutoff->copy()->addDays(self::RANKED_GAP_DAYS);
            $rClose = $rOpen->copy()->addDays($this->rankedWindowDays($jurisdictionId));
        }

        return [
            'approval_opens_at'  => $opens,
            'finalist_cutoff_at' => $cutoff,
            'ranked_opens_at'    => $rOpen,
            'ranked_closes_at'   => $rClose,
        ];
    }

    /** Record the §B.4 blocked posture: rejected=true chain entry, row stays `scheduled`. */
    private function recordBlocked(Election $election, Legislature $legislature, array $plan): void
    {
        $reasons = collect($plan['kinds'])
            ->filter(fn ($spec) => $spec['mode'] === 'blocked')
            ->map(fn ($spec, $kind) => [
                'seat_kind' => $kind,
                'reason'    => $spec['reason'],
                'citation'  => $spec['citation'],
            ])
            ->values()
            ->all();

        $this->audit->append(
            module: 'elections',
            event: 'election.blocked_pending_subdivision',
            payload: [
                'election_id'    => $election->id,
                'legislature_id' => $legislature->id,
                'type_a_seats'   => (int) $legislature->type_a_seats,
                'type_b_seats'   => (int) $legislature->type_b_seats,
                'blocked'        => $reasons,
                // Kinds that COULD be generated, recorded for the re-plan.
                'generable'      => collect($plan['kinds'])
                    ->filter(fn ($spec) => $spec['mode'] !== 'blocked')
                    ->map(fn ($spec, $kind) => ['seat_kind' => $kind, 'mode' => $spec['mode']])
                    ->values()
                    ->all(),
            ],
            ref: 'ESM-03',
            jurisdictionId: $legislature->jurisdiction_id,
            rejected: true,
            blockedReason: 'Subdivision required before this chamber can be elected (Art. II §8).',
        );
    }

    private function vacatedSeat(Vacancy $vacancy): array
    {
        $member = null;
        $race   = null;

        if ($vacancy->seat_type === 'legislature_members') {
            $member = LegislatureMember::query()->find($vacancy->seat_id);
            if ($member?->elected_in_race_id !== null) {
                $race = ElectionRace::query()->find($member->elected_in_race_id);
            }
        }

        return [$member, $race];
    }

    private function votingMethodSnapshot(string $jurisdictionId): string
    {
        $method = $this->settings->resolve($jurisdictionId, 'voting_method');

        return is_string($method) && $method !== '' ? $method : 'stv_droop';
    }

    private function activeBoardId(string $jurisdictionId): ?string
    {
        return ElectionBoard::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->active()
            ->value('id');
    }

    private function finalistMultiplier(string $jurisdictionId): int
    {
        return max(1, $this->settings->resolveInt($jurisdictionId, 'finalist_multiplier', 3));
    }

    private function approvalMinDays(string $jurisdictionId): int
    {
        return max(1, $this->settings->resolveInt($jurisdictionId, 'approval_min_days', 30));
    }

    private function rankedWindowDays(string $jurisdictionId): int
    {
        return max(1, $this->settings->resolveInt($jurisdictionId, 'ranked_window_days', 14));
    }

    private function asTime(mixed $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        return Carbon::parse($value);
    }
}
