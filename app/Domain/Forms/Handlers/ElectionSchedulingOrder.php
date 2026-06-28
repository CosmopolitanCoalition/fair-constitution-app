<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\ElectionSchedulingDelegate;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\BoardProvenance;
use App\Models\Election;
use App\Models\ElectionBoard;
use App\Models\ElectionRace;
use App\Models\Legislature;
use App\Models\LegislatureDistrict;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\ConstitutionalValidator;
use App\Services\SettingsResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * F-ELB-001 — Election Scheduling Order (R-08; system for bootstrap/CLK-01).
 *
 * Elections fire from clocks, never official discretion (design §B.1
 * hardened no-skip): ScheduleGeneralElectionJob (WI-B3) system-files this
 * form; a human board files it only to CREATE the bootstrap/special order
 * or to REFINE dates within bounds. It can never delay past the lockstep
 * boundary.
 *
 * Validation (design §C):
 *  - dates UTC + ordered: approval_opens_at < finalist_cutoff_at ≤
 *    ranked_opens_at < ranked_closes_at;
 *  - finalist_cutoff_at ≥ approval_opens_at + approval_min_days and ranked
 *    window = ranked_window_days (per-jurisdiction resolved; the dev demo
 *    compresses via config('cga.election_demo_compression') — config,
 *    never data);
 *  - schedule ≤ lockstep expiry (legislature.term_ends_on) — Art. II §2;
 *  - specials: ranked window ∈ [vacancy.declared_at + special_election_
 *    min_days, + max_days] — out-of-window REJECTED, Art. II §5 (CLK-04);
 *  - race structure per ConstitutionalValidator::checkRaceStructure
 *    (Art. II §8) with X = finalist_multiplier × seats (CLK-21) FROZEN
 *    into election_races.finalist_count — pre-published with the order.
 *
 * Mutation: create or confirm the elections row + explicit-payload races;
 * map-derived race generation and phase-timer arming delegate to
 * ElectionSchedulingDelegate (WI-B3 rebinds ElectionLifecycleService).
 */
class ElectionSchedulingOrder implements FormHandler
{
    /** The only election kinds writable in Phase B (engine gate). */
    private const WRITABLE_KINDS = [Election::KIND_GENERAL, Election::KIND_SPECIAL];

    public function __construct(
        private readonly ConstitutionalValidator $validator,
        private readonly SettingsResolver $settings,
        private readonly ElectionSchedulingDelegate $scheduling,
    ) {}

    public function module(): string
    {
        return 'elections';
    }

    public function event(): string
    {
        return 'election.scheduled';
    }

    public function requiredRoles(): array
    {
        return ['R-08'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $dates = self::parseDates($payload);

        [$election, $created] = $this->resolveElection($payload, $dates);

        // SCOPE (R-08): a HUMAN board member may only order elections for THIS election's responsible board.
        // The role gate proves a seat on SOME active board (RoleService::hasActiveBoardSeat is board-blind),
        // so without this a board member of jurisdiction A could schedule a jurisdiction-B election. The
        // system/clock path (null actor — ScheduleGeneralElectionJob / bootstrap) bypasses, exactly as the
        // engine bypasses the role gate for a system filing.
        if ($actor !== null) {
            BoardProvenance::resolveMember($actor, $election, 'F-ELB-001');
        }

        $jurisdictionId = (string) $election->jurisdiction_id;
        $compressed = (bool) config('cga.election_demo_compression', false);

        self::assertWindowOrdering($dates);

        if (! $compressed) {
            $this->assertPhaseLengths($dates, $jurisdictionId);
        }

        $this->assertLockstepCeiling($election, $dates);

        if ($election->kind === Election::KIND_SPECIAL) {
            $this->assertSpecialInsideWindow($election, $payload, $dates, $jurisdictionId);
        }

        // Persist the (re)confirmed schedule. Approval opens immediately
        // when the order says so — no dead period (design §B.2.1).
        $election->fill([
            'approval_opens_at' => $dates['approval_opens_at'],
            'finalist_cutoff_at' => $dates['finalist_cutoff_at'],
            'ranked_opens_at' => $dates['ranked_opens_at'],
            'ranked_closes_at' => $dates['ranked_closes_at'],
        ]);

        if (in_array($election->status, [Election::STATUS_SCHEDULED, Election::STATUS_APPROVAL_OPEN], true)) {
            $election->status = $dates['approval_opens_at']->lessThanOrEqualTo(now())
                ? Election::STATUS_APPROVAL_OPEN
                : Election::STATUS_SCHEDULED;
        }

        $election->save();

        $races = $this->resolveRaces($election, $payload);

        $this->scheduling->armPhaseTimers($election);

        // X pre-published with the order (design §C).
        return [
            'election_id' => (string) $election->id,
            'kind' => $election->kind,
            'jurisdiction_id' => $jurisdictionId,
            'created' => $created,
            'dates' => [
                'approval_opens_at' => $dates['approval_opens_at']->toIso8601String(),
                'finalist_cutoff_at' => $dates['finalist_cutoff_at']->toIso8601String(),
                'ranked_opens_at' => $dates['ranked_opens_at']->toIso8601String(),
                'ranked_closes_at' => $dates['ranked_closes_at']->toIso8601String(),
            ],
            'races' => $races,
        ];
    }

    // -------------------------------------------------------------------------
    // Pure guards (pinned DB-free by tests/Feature/PhaseBHandlersTest)
    // -------------------------------------------------------------------------

    /**
     * Dates UTC + ordered: opens < cutoff ≤ ranked_opens < ranked_closes
     * (cutoff may coincide with the ranked window opening).
     *
     * @param  array{approval_opens_at: CarbonImmutable, finalist_cutoff_at: CarbonImmutable, ranked_opens_at: CarbonImmutable, ranked_closes_at: CarbonImmutable}  $dates
     */
    public static function assertWindowOrdering(array $dates): void
    {
        $ordered = $dates['approval_opens_at']->lessThan($dates['finalist_cutoff_at'])
            && $dates['finalist_cutoff_at']->lessThanOrEqualTo($dates['ranked_opens_at'])
            && $dates['ranked_opens_at']->lessThan($dates['ranked_closes_at']);

        if (! $ordered) {
            throw new ConstitutionalViolation(
                'Election schedule must be ordered: approval_opens_at < finalist_cutoff_at ≤ '
                .'ranked_opens_at < ranked_closes_at.',
                'Art. II §2'
            );
        }
    }

    /**
     * CLK-04 — the special-election ranked window must lie inside
     * [declared_at + min_days, declared_at + max_days]. Out-of-window
     * dates are REJECTED with citation (design §C; q: discretion can
     * never produce "no election").
     */
    public static function assertSpecialWindow(
        CarbonInterface $declaredAt,
        CarbonInterface $rankedOpensAt,
        CarbonInterface $rankedClosesAt,
        int $minDays,
        int $maxDays,
    ): void {
        $windowOpens = $declaredAt->toImmutable()->addDays($minDays);
        $windowCloses = $declaredAt->toImmutable()->addDays($maxDays);

        if ($rankedOpensAt->lessThan($windowOpens) || $rankedClosesAt->greaterThan($windowCloses)) {
            throw new ConstitutionalViolation(
                sprintf(
                    'Special election ranked window [%s, %s] falls outside the constitutional window '
                    .'[declared + %d days = %s, declared + %d days = %s].',
                    $rankedOpensAt->toIso8601String(),
                    $rankedClosesAt->toIso8601String(),
                    $minDays,
                    $windowOpens->toIso8601String(),
                    $maxDays,
                    $windowCloses->toIso8601String(),
                ),
                'Art. II §5'
            );
        }
    }

    /**
     * Parse the order's dates (ISO-8601, normalized to UTC). The three
     * phase boundaries are required; approval_opens_at defaults to now
     * (bootstrap: approval opens immediately).
     *
     * @return array{approval_opens_at: CarbonImmutable, finalist_cutoff_at: CarbonImmutable, ranked_opens_at: CarbonImmutable, ranked_closes_at: CarbonImmutable}
     */
    public static function parseDates(array $payload): array
    {
        $parsed = [];

        foreach (['approval_opens_at', 'finalist_cutoff_at', 'ranked_opens_at', 'ranked_closes_at'] as $key) {
            $raw = $payload[$key] ?? null;

            if ($raw === null) {
                if ($key === 'approval_opens_at') {
                    $parsed[$key] = CarbonImmutable::now('UTC');

                    continue;
                }

                throw new ConstitutionalViolation(
                    "F-ELB-001 requires {$key} (ISO-8601, UTC).",
                    'Art. II §2'
                );
            }

            try {
                $parsed[$key] = CarbonImmutable::parse($raw)->utc();
            } catch (\Throwable) {
                throw new ConstitutionalViolation(
                    "F-ELB-001 {$key} is not a parsable instant: ".json_encode($raw),
                    'Art. II §2'
                );
            }
        }

        return $parsed;
    }

    // -------------------------------------------------------------------------
    // DB-backed validation + mutation
    // -------------------------------------------------------------------------

    /** @return array{0: Election, 1: bool} the election + whether it was created */
    private function resolveElection(array $payload, array $dates): array
    {
        $electionId = $payload['election_id'] ?? null;

        if ($electionId !== null) {
            $election = Election::query()->find($electionId);

            if ($election === null) {
                throw new ConstitutionalViolation(
                    "F-ELB-001 confirmation targets unknown election [{$electionId}].",
                    'CGA Forms Catalog (F-ELB-001)'
                );
            }

            // Refinement is only possible before the cutoff freezes the
            // ballot — a published cutoff never moves (CLK-21).
            if (! in_array($election->status, [Election::STATUS_SCHEDULED, Election::STATUS_APPROVAL_OPEN], true)) {
                throw new ConstitutionalViolation(
                    "Election [{$electionId}] is past the schedulable phases (status: {$election->status}).",
                    'Art. II §2'
                );
            }

            return [$election, false];
        }

        $kind = $payload['kind'] ?? Election::KIND_GENERAL;
        $jurisdictionId = $payload['jurisdiction_id'] ?? null;

        if (! in_array($kind, self::WRITABLE_KINDS, true)) {
            throw new ConstitutionalViolation(
                "Election kind [{$kind}] is not writable in Phase B (general/special only — engine gate).",
                'CGA Forms Catalog (F-ELB-001)'
            );
        }

        if (! is_string($jurisdictionId) || ! Str::isUuid($jurisdictionId)) {
            throw new ConstitutionalViolation(
                'F-ELB-001 creation requires a jurisdiction_id (UUID).',
                'CGA Forms Catalog (F-ELB-001)'
            );
        }

        $jurisdictionExists = DB::table('jurisdictions')
            ->where('id', $jurisdictionId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $jurisdictionExists) {
            throw new ConstitutionalViolation(
                "Jurisdiction [{$jurisdictionId}] does not exist.",
                'CGA Forms Catalog (F-ELB-001)'
            );
        }

        $boardId = $payload['election_board_id']
            ?? ElectionBoard::query()
                ->where('jurisdiction_id', $jurisdictionId)
                ->active()
                ->value('id');

        $election = Election::query()->create([
            'jurisdiction_id' => $jurisdictionId,
            'legislature_id' => $payload['legislature_id'] ?? null,
            'kind' => $kind,
            'status' => Election::STATUS_SCHEDULED,
            'trigger' => $payload['trigger'] ?? 'scheduled',
            'election_board_id' => $boardId,
            'district_map_id' => $payload['district_map_id'] ?? null,
            'prior_election_id' => $payload['prior_election_id'] ?? null,
            'triggered_by_timer_id' => $payload['triggered_by_timer_id'] ?? null,
            'vacancy_id' => $payload['vacancy_id'] ?? null,
        ]);

        return [$election, true];
    }

    /**
     * Amendable phase-length rails, resolved per jurisdiction at order
     * time: cutoff ≥ opens + approval_min_days; ranked window length =
     * ranked_window_days (design §C).
     */
    private function assertPhaseLengths(array $dates, string $jurisdictionId): void
    {
        $approvalMinDays = $this->settings->resolveInt($jurisdictionId, 'approval_min_days', 30);
        $rankedWindowDays = $this->settings->resolveInt($jurisdictionId, 'ranked_window_days', 14);

        if ($dates['finalist_cutoff_at']->lessThan($dates['approval_opens_at']->addDays($approvalMinDays))) {
            throw new ConstitutionalViolation(
                "finalist_cutoff_at must be at least approval_min_days ({$approvalMinDays}) after approval_opens_at.",
                'Art. II §2 · as implemented'
            );
        }

        $actualWindowDays = (int) $dates['ranked_opens_at']->diffInDays($dates['ranked_closes_at']);

        if ($actualWindowDays !== $rankedWindowDays) {
            throw new ConstitutionalViolation(
                "Ranked window must be exactly ranked_window_days ({$rankedWindowDays}); order specifies {$actualWindowDays}.",
                'Art. II §2 · as implemented'
            );
        }
    }

    /**
     * Hardened no-delay: the schedule cannot extend past the legislature's
     * lockstep expiry (design §B.1 — the board refines dates WITHIN bounds,
     * it cannot delay past the lockstep boundary).
     */
    private function assertLockstepCeiling(Election $election, array $dates): void
    {
        if ($election->legislature_id === null) {
            return;
        }

        $termEndsOn = Legislature::query()
            ->whereKey($election->legislature_id)
            ->value('term_ends_on');

        if ($termEndsOn === null) {
            return; // forming chamber — no lockstep boundary yet
        }

        $boundary = CarbonImmutable::parse($termEndsOn)->endOfDay()->utc();

        if ($dates['ranked_closes_at']->greaterThan($boundary)) {
            throw new ConstitutionalViolation(
                "The order would delay the election past the lockstep boundary ({$boundary->toIso8601String()}) — "
                .'officials cannot delay elections.',
                'Art. II §2'
            );
        }
    }

    private function assertSpecialInsideWindow(
        Election $election,
        array $payload,
        array $dates,
        string $jurisdictionId,
    ): void {
        $vacancyId = $election->vacancy_id ?? $payload['vacancy_id'] ?? null;
        $vacancy = $vacancyId !== null ? Vacancy::query()->find($vacancyId) : null;

        if ($vacancy === null || $vacancy->declared_at === null) {
            throw new ConstitutionalViolation(
                'A special election requires a DECLARED vacancy (vacancy_id with declared_at set).',
                'Art. II §5'
            );
        }

        self::assertSpecialWindow(
            $vacancy->declared_at,
            $dates['ranked_opens_at'],
            $dates['ranked_closes_at'],
            $this->settings->resolveInt($jurisdictionId, 'special_election_min_days', 90),
            $this->settings->resolveInt($jurisdictionId, 'special_election_max_days', 180),
        );
    }

    /**
     * Races: explicit payload races are validated (race_structure rule,
     * Art. II §8) and created with X frozen; an election that already has
     * races keeps them (a published X never moves); otherwise generation
     * delegates to WI-B3.
     *
     * @return list<array{race_id: string, district_id: string|null, seats: int, finalist_count: int}>
     */
    private function resolveRaces(Election $election, array $payload): array
    {
        $specs = $payload['races'] ?? null;

        if (is_array($specs) && $specs !== []) {
            if ($election->races()->exists()) {
                throw new ConstitutionalViolation(
                    'This election already has races — published races (and their finalist counts) are frozen.',
                    'Art. II §2 · as implemented'
                );
            }

            $maxSeats = $this->settings->resolveInt(
                (string) $election->jurisdiction_id,
                'legislature_max_seats',
                \App\Services\ConstitutionalDefaults::HARD_CEILING
            );

            $summaries = [];

            foreach ($specs as $spec) {
                $summaries[] = $this->createRace($election, (array) $spec, $maxSeats);
            }

            return $summaries;
        }

        if ($election->races()->exists()) {
            return $election->races()
                ->get(['id', 'district_id', 'seats', 'finalist_count'])
                ->map(fn (ElectionRace $race) => [
                    'race_id' => (string) $race->id,
                    'district_id' => $race->district_id !== null ? (string) $race->district_id : null,
                    'seats' => (int) $race->seats,
                    'finalist_count' => (int) $race->finalist_count,
                ])
                ->all();
        }

        return $this->scheduling->generateRaces($election, $payload);
    }

    /** @return array{race_id: string, district_id: string|null, seats: int, finalist_count: int} */
    private function createRace(Election $election, array $spec, int $maxSeats): array
    {
        $seatKind = (string) ($spec['seat_kind'] ?? ElectionRace::SEAT_KIND_TYPE_A);
        $seats = (int) ($spec['seats'] ?? 0);
        $districtId = $spec['district_id'] ?? null;

        $this->validator->checkRaceStructure($seatKind, $seats, $districtId, $maxSeats);

        // Footprint: the district's parent scope, or the election's
        // jurisdiction for at-large races (design §A B-4).
        $raceJurisdictionId = (string) $election->jurisdiction_id;

        if ($districtId !== null) {
            $district = LegislatureDistrict::query()->find($districtId);

            if ($district === null) {
                throw new ConstitutionalViolation(
                    "Race references unknown district [{$districtId}].",
                    'CGA Forms Catalog (F-ELB-001)'
                );
            }

            $raceJurisdictionId = (string) $district->jurisdiction_id;
        }

        // CLK-21: X = finalist_multiplier × seats, resolved per race
        // jurisdiction and FROZEN here — later amendments never move a
        // published cutoff. (Resolved via SettingsResolver directly: the
        // CLK-21 registry row carries no setting_key, so this is the
        // working equivalent of ClockService::resolvedInt('CLK-21', …).)
        $finalistCount = $seats * $this->settings->resolveInt($raceJurisdictionId, 'finalist_multiplier', 3);

        $race = ElectionRace::query()->create([
            'election_id' => (string) $election->id,
            'district_id' => $districtId,
            'jurisdiction_id' => $raceJurisdictionId,
            'seat_kind' => $seatKind,
            'seats' => $seats,
            'finalist_count' => $finalistCount,
            'electorate_type' => $spec['electorate_type'] ?? ElectionRace::ELECTORATE_RESIDENTS,
            'status' => $election->status,
        ]);

        return [
            'race_id' => (string) $race->id,
            'district_id' => $districtId !== null ? (string) $districtId : null,
            'seats' => $seats,
            'finalist_count' => $finalistCount,
        ];
    }
}
