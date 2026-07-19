<?php

namespace App\Services;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Election;
use App\Models\ElectionBoard;
use App\Models\ElectionBoardMember;
use App\Models\Jurisdiction;
use App\Models\JurisdictionActivation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * WI-7 — the activation engine (WF-JUR-01 bootstrap pipeline, Phase A
 * skeleton).
 *
 * onCriticalPopulation() — CLK-06 crossing: activation row →
 *   critical_population (idempotent; audit ref CLK-06).
 *
 * activate() — the pipeline:
 *   1. → bootstrapping
 *   2. ensure a legislature:
 *      - jurisdictions WITH direct children → reuse the existing sizing
 *        path (`apportionment:seed --jurisdiction=<id>`, cube-root over
 *        Σ direct-children population → type_a) PLUS the bicameral rule:
 *        type_b_seats = count(direct children) — one seat per constituent,
 *        Art. V §3; both kinds required whenever constituents exist.
 *      - LEAF jurisdictions → type_a = max(5, round(∛own population)),
 *        unicameral (type_b = 0), CLAMPED to the resolved ceiling (9):
 *        a childless jurisdiction has nothing to subdivide over, and a
 *        > 9-seat voter pool is unconstitutional (Art. II §2/§8). The
 *        clamp is audited; the shortest-split-line drawing tool
 *        (backlog #1) restores cube-root sizing with districts later.
 *      status 'forming', term_number 1.
 *   3. executive + judiciary stubs (InstitutionStubService — shared with
 *      Setup Step 4).
 *   3.5 (WI-B7, design §B.3 — bootstrap elections, WF-ELE-02):
 *      a. constitute the bootstrap election board (is_bootstrap=true +
 *         the synthetic system member, user_id NULL);
 *      b. chambers with children whose type_a exceeds the ceiling →
 *         generate + activate the INITIAL DISTRICT MAP
 *         (InitialDistrictMapService → DistrictingService auto-composite
 *         → system-filed F-ELB-003);
 *      c. leaf chambers above the ceiling → THE CLAMP (re-plan to 9);
 *      d. system-file F-ELB-001 scheduling the first general election
 *         (engine seam → ElectionLifecycleService; dates compressed only
 *         via config('cga.election_demo_compression') — config, never
 *         data). A §B.4-blocked chamber records the rejected chain entry
 *         and activation continues.
 *   4. → self_governing (legislature row exists).
 *
 * replan() — re-enter step 3.5 for an ALREADY-activated jurisdiction
 * whose legislature is still memberless + forming (the Montegiardino
 * 10-seat dev row; `jurisdiction:activate <slug> --replan`). Seated
 * chambers are never resized.
 *
 * Every step appends to the audit chain (module 'jurisdictions',
 * ref WF-JUR-01) — these entries become the Phase F bootstrap-tracker
 * records. Idempotent throughout: an already-self-governing jurisdiction
 * returns unchanged; an existing legislature is adopted, never resized.
 *
 * The seat math lives in pure statics (cubeRootSeats / seatPlan /
 * quorumRequired) so tests/Constitutional/ActivationMathTest pins it
 * without a database.
 */
class ActivationService
{
    /** Constitutional floor on chamber size (Art. II §2). */
    public const SEAT_FLOOR = 5;

    public function __construct(
        private readonly AuditService $audit,
        private readonly InstitutionStubService $stubs,
        private readonly InitialDistrictMapService $initialMaps,
        private readonly ElectionLifecycleService $lifecycle,
        private readonly ConstitutionalEngine $engine,
    ) {
    }

    // =========================================================================
    // Pure sizing math (DB-free — pinned by ActivationMathTest)
    // =========================================================================

    /**
     * Cube-root law (Taagepera): max(floor, round(population^(1/3))).
     * Mirrors ConstitutionalDefaults::sizeFromPopulation's default branch
     * without the per-jurisdiction settings lookup.
     */
    public static function cubeRootSeats(int|float $population, int $floor = self::SEAT_FLOOR): int
    {
        $pop = max((float) $population, 1.0);

        return max($floor, (int) round(pow($pop, 1.0 / 3.0)));
    }

    /**
     * Full chamber plan (cycle-2 Type B ladder, operator ruling 2026-07-19):
     *  - constituents present → bicameral: type_a from Σ children
     *    population (cube root); type_b from TypeBSeatLadder — equal
     *    representation of the constituents, bound by type_a (each
     *    constituent contributes rep_floor seats, tiny pop ≤ 5 contributes
     *    min(pop, rep_floor); the ladder descends 5 → 2, then flags for
     *    the deferred Type B districting);
     *  - leaf → unicameral: type_a from OWN population under the SAME law
     *    (floor clamp only — an over-ceiling leaf line-splits, never
     *    truncates), no type_b.
     *
     * @param list<int> $childPopulations direct live constituents' populations
     * @return array{type_a:int, type_b:int, bicameral:bool, type_b_rep_floor:?int, type_b_needs_districting:bool}
     */
    public static function seatPlan(
        float $ownPopulation,
        float $childrenPopulation,
        array $childPopulations,
        int $startingRep = 5,
    ): array {
        if ($childPopulations !== []) {
            $typeA  = self::cubeRootSeats($childrenPopulation);
            $ladder = \App\Services\Legislature\TypeBSeatLadder::apportion($typeA, $childPopulations, $startingRep);

            return [
                'type_a'                   => $typeA,
                'type_b'                   => $ladder['seats'],
                'bicameral'                => true,
                'type_b_rep_floor'         => $ladder['rep_floor'],
                'type_b_needs_districting' => $ladder['needs_districting'],
            ];
        }

        return [
            'type_a'                   => self::cubeRootSeats($ownPopulation),
            'type_b'                   => 0,
            'bicameral'                => false,
            'type_b_rep_floor'         => null,
            'type_b_needs_districting' => false,
        ];
    }

    /** Quorum sizing used at instantiation (matches ApportionmentSeedCommand). */
    public static function quorumRequired(int $totalSeats): int
    {
        return max(3, (int) ceil($totalSeats / 2));
    }

    // =========================================================================
    // CLK-06 — critical population
    // =========================================================================

    /**
     * CLK-06 crossing: upsert the activation row → critical_population.
     * Idempotent — a row already past boundary_loaded is returned
     * untouched (re-runs never double-fire).
     */
    public function onCriticalPopulation(string $jurisdictionId, int $verifiedResidents, int $threshold): JurisdictionActivation
    {
        return DB::transaction(function () use ($jurisdictionId, $verifiedResidents, $threshold) {
            $activation = JurisdictionActivation::query()
                ->where('jurisdiction_id', $jurisdictionId)
                ->lockForUpdate()
                ->first();

            if ($activation !== null && $activation->state !== JurisdictionActivation::STATE_BOUNDARY_LOADED) {
                return $activation;
            }

            if ($activation === null) {
                $activation = new JurisdictionActivation(['jurisdiction_id' => $jurisdictionId]);
            }

            $activation->forceFill([
                'state'                  => JurisdictionActivation::STATE_CRITICAL_POPULATION,
                'critical_population_at' => now(),
                'notes'                  => array_merge($activation->notes ?? [], [
                    'critical_population' => [
                        'verified_residents' => $verifiedResidents,
                        'threshold'          => $threshold,
                        'at'                 => now()->toIso8601String(),
                    ],
                ]),
            ])->save();

            $this->audit->append(
                module: 'jurisdictions',
                event: 'critical_population_reached',
                payload: [
                    'activation_id'      => $activation->id,
                    'verified_residents' => $verifiedResidents,
                    'threshold'          => $threshold,
                    'fires_workflow'     => 'WF-JUR-01',
                ],
                ref: 'CLK-06',
                jurisdictionId: $jurisdictionId,
            );

            return $activation;
        });
    }

    // =========================================================================
    // WF-JUR-01 — the activation pipeline
    // =========================================================================

    /**
     * Run the bootstrap pipeline for a jurisdiction. Idempotent: already
     * self-governing → returned unchanged; an existing legislature is
     * adopted, never resized (the Earth legislature is simply the first
     * activated instance).
     */
    public function activate(Jurisdiction $jurisdiction): JurisdictionActivation
    {
        // ── Step 1: → bootstrapping ─────────────────────────────────────────
        $activation = DB::transaction(function () use ($jurisdiction) {
            $activation = JurisdictionActivation::query()
                ->where('jurisdiction_id', $jurisdiction->id)
                ->lockForUpdate()
                ->first();

            if ($activation === null) {
                $activation = new JurisdictionActivation(['jurisdiction_id' => $jurisdiction->id]);
            }

            if ($activation->state === JurisdictionActivation::STATE_SELF_GOVERNING) {
                return $activation;
            }

            $activation->forceFill([
                'state' => JurisdictionActivation::STATE_BOOTSTRAPPING,
                'notes' => array_merge($activation->notes ?? [], [
                    'bootstrapping' => ['at' => now()->toIso8601String()],
                ]),
            ])->save();

            $this->audit->append(
                module: 'jurisdictions',
                event: 'activation_bootstrapping',
                payload: [
                    'activation_id' => $activation->id,
                    'slug'          => $jurisdiction->slug,
                ],
                ref: 'WF-JUR-01',
                jurisdictionId: $jurisdiction->id,
            );

            return $activation;
        });

        if ($activation->state === JurisdictionActivation::STATE_SELF_GOVERNING) {
            return $activation;
        }

        // ── Step 2: ensure a legislature ────────────────────────────────────
        $legislature = $this->ensureLegislature($jurisdiction, $activation);

        // ── Step 3: institution stubs (shared with Setup Step 4) ───────────
        $created = $this->stubs->generate([$jurisdiction->id]);

        $this->audit->append(
            module: 'jurisdictions',
            event: 'institution_stubs_ensured',
            payload: [
                'activation_id'       => $activation->id,
                'executives_created'  => $created['executives_created'],
                'judiciaries_created' => $created['judiciaries_created'],
            ],
            ref: 'WF-JUR-01',
            jurisdictionId: $jurisdiction->id,
        );

        // ── Step 3.5: bootstrap elections (WI-B7, design §B.3) ─────────────
        $this->bootstrapElections($jurisdiction, $activation);

        // ── Step 4: → self_governing ────────────────────────────────────────
        DB::transaction(function () use ($jurisdiction, $activation, $legislature) {
            $activation->forceFill([
                'state'          => JurisdictionActivation::STATE_SELF_GOVERNING,
                'activated_at'   => now(),
                'legislature_id' => $legislature->id,
            ])->save();

            $this->audit->append(
                module: 'jurisdictions',
                event: 'activation_self_governing',
                payload: [
                    'activation_id'  => $activation->id,
                    'legislature_id' => $legislature->id,
                ],
                ref: 'WF-JUR-01',
                jurisdictionId: $jurisdiction->id,
            );
        });

        return $activation->refresh();
    }

    /**
     * WI-B7 — re-enter step 3.5 for an ALREADY-activated jurisdiction
     * (`jurisdiction:activate <slug> --replan`). Pre-conditions: an
     * activation row at bootstrapping or beyond, and a MEMBERLESS,
     * FORMING legislature — a seated chamber is never resized. Re-runs
     * the sizing posture (leaf clamp / initial map), the bootstrap board,
     * and the first-election scheduling. Idempotent: every sub-step
     * adopts existing state.
     */
    public function replan(Jurisdiction $jurisdiction): JurisdictionActivation
    {
        $activation = JurisdictionActivation::query()
            ->where('jurisdiction_id', $jurisdiction->id)
            ->first();

        if ($activation === null || ! $activation->hasReached(JurisdictionActivation::STATE_BOOTSTRAPPING)) {
            throw new RuntimeException(
                "--replan requires an already-activated jurisdiction — run jurisdiction:activate {$jurisdiction->slug} first."
            );
        }

        $legislature = $this->legislatureRow($jurisdiction->id);

        if ($legislature === null) {
            throw new RuntimeException("No legislature to re-plan for {$jurisdiction->slug}.");
        }

        if (! $this->isMemberlessForming($legislature)) {
            throw new RuntimeException(
                "Re-plan refused: legislature {$legislature->id} is not a memberless forming chamber — "
                . 'seated chambers are never resized.'
            );
        }

        $this->audit->append(
            module: 'jurisdictions',
            event: 'activation_replan',
            payload: [
                'activation_id'  => $activation->id,
                'legislature_id' => (string) $legislature->id,
                'type_a_seats'   => (int) $legislature->type_a_seats,
                'type_b_seats'   => (int) $legislature->type_b_seats,
            ],
            ref: 'WF-JUR-01',
            jurisdictionId: $jurisdiction->id,
        );

        $this->bootstrapElections($jurisdiction, $activation);

        return $activation->refresh();
    }

    // -------------------------------------------------------------------------

    /**
     * Find-or-create the jurisdiction's legislature per the sizing law.
     * An existing legislature is adopted untouched.
     *
     * @return object{id:string, type_a_seats:int, type_b_seats:int, total_seats:int}
     */
    private function ensureLegislature(Jurisdiction $jurisdiction, JurisdictionActivation $activation): object
    {
        $existing = DB::table('legislatures')
            ->where('jurisdiction_id', $jurisdiction->id)
            ->whereNull('deleted_at')
            ->first(['id', 'type_a_seats', 'type_b_seats', 'total_seats']);

        if ($existing !== null) {
            return $existing;
        }

        $children = DB::table('jurisdictions')
            ->where('parent_id', $jurisdiction->id)
            ->whereNull('deleted_at')
            ->selectRaw('count(*) AS cnt, coalesce(sum(population), 0) AS pop')
            ->first();

        $childCount = (int) ($children->cnt ?? 0);

        if ($childCount > 0) {
            $legislature = $this->instantiateBicameral($jurisdiction, $childCount);
        } else {
            $legislature = $this->instantiateLeaf($jurisdiction);
        }

        $this->audit->append(
            module: 'jurisdictions',
            event: 'legislature_instantiated',
            payload: [
                'activation_id'   => $activation->id,
                'legislature_id'  => $legislature->id,
                'type_a_seats'    => (int) $legislature->type_a_seats,
                'type_b_seats'    => (int) $legislature->type_b_seats,
                'bicameral'       => $childCount > 0,
                'direct_children' => $childCount,
                'sizing'          => $childCount > 0
                    ? 'cube_root(sum direct-children population) via apportionment:seed'
                    : 'cube_root(own population), unicameral leaf',
            ],
            ref: 'WF-JUR-01',
            jurisdictionId: $jurisdiction->id,
        );

        return $legislature;
    }

    /**
     * Parent path: reuse `apportionment:seed --jurisdiction=<id>` for the
     * level-local cube-root type_a sizing AND the Type B ladder (cycle-2:
     * the command itself now mints the ladder chamber — TypeBSeatLadder is
     * the ONE formula both mass paths share, so no override is needed
     * here anymore). No --stamp-instance: activation runs must never
     * rewrite setup-wizard state.
     */
    private function instantiateBicameral(Jurisdiction $jurisdiction, int $childCount): object
    {
        $exit = Artisan::call('apportionment:seed', [
            '--jurisdiction' => $jurisdiction->id,
        ]);

        if ($exit !== 0) {
            throw new RuntimeException(
                "apportionment:seed --jurisdiction={$jurisdiction->id} exited with {$exit}."
            );
        }

        $legislature = DB::table('legislatures')
            ->where('jurisdiction_id', $jurisdiction->id)
            ->whereNull('deleted_at')
            ->first(['id', 'type_a_seats', 'type_b_seats', 'total_seats']);

        if ($legislature === null) {
            throw new RuntimeException(
                "apportionment:seed produced no legislature for {$jurisdiction->slug}."
            );
        }

        return $legislature;
    }

    /**
     * Leaf path: unicameral, type_a = max(floor, round(∛own population)) —
     * the SAME law as parents (cycle-2 ruling 2026-07-19: the old ceiling
     * clamp truncated; an over-ceiling leaf legislature now line-splits its
     * own districts via the LeafGiantResolver root-leaf context — the
     * shortest-split-line capability the WI-B7 clamp was waiting for).
     */
    private function instantiateLeaf(Jurisdiction $jurisdiction): object
    {
        $plan  = self::seatPlan((float) ($jurisdiction->population ?? 0), 0.0, []);
        $typeA = $plan['type_a'];
        $total = $typeA + $plan['type_b'];

        $id = (string) Str::uuid();

        DB::table('legislatures')->insert([
            'id'              => $id,
            'jurisdiction_id' => $jurisdiction->id,
            'term_number'     => 1,
            'status'          => 'forming',
            'total_seats'     => $total,
            'type_a_seats'    => $typeA,
            'type_b_seats'    => $plan['type_b'],
            'quorum_required' => self::quorumRequired($total),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return (object) [
            'id'           => $id,
            'type_a_seats' => $typeA,
            'type_b_seats' => $plan['type_b'],
            'total_seats'  => $total,
        ];
    }

    // =========================================================================
    // Step 3.5 — bootstrap elections (WI-B7, design §B.3 / WF-ELE-02)
    // =========================================================================

    /**
     * Bootstrap board + sizing posture + first general election for the
     * jurisdiction's legislature. No-op unless the chamber is memberless
     * + forming (a seated chamber is never touched). Every sub-step is
     * individually idempotent and audited.
     */
    private function bootstrapElections(Jurisdiction $jurisdiction, JurisdictionActivation $activation): void
    {
        $legislature = $this->legislatureRow($jurisdiction->id);

        if ($legislature === null || ! $this->isMemberlessForming($legislature)) {
            return;
        }

        // (a) the bootstrap election board (system-as-board).
        $this->ensureBootstrapBoard($jurisdiction, $legislature);

        // (b)/(c) sizing posture: initial map (children) or clamp (leaf).
        $legislature = $this->applySeatPosture($jurisdiction, $activation, $legislature);

        // (d) first general election — F-ELB-001 through the engine.
        $this->scheduleBootstrapElection($jurisdiction, $activation, $legislature);
    }

    /**
     * Constitute the bootstrap election board (design §B.3.1): one
     * `election_boards` row `is_bootstrap=true, status='active'` plus the
     * synthetic system member (user_id NULL — always 'seated' per the B-2
     * CHECK). An existing ACTIVE board (bootstrap or real) is adopted.
     */
    private function ensureBootstrapBoard(Jurisdiction $jurisdiction, object $legislature): ElectionBoard
    {
        $existing = ElectionBoard::query()
            ->where('jurisdiction_id', $jurisdiction->id)
            ->active()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($jurisdiction, $legislature) {
            $board = ElectionBoard::create([
                'jurisdiction_id' => $jurisdiction->id,
                'legislature_id'  => (string) $legislature->id,
                'is_bootstrap'    => true,
                'status'          => ElectionBoard::STATUS_ACTIVE,
            ]);

            $member = ElectionBoardMember::create([
                'election_board_id' => (string) $board->id,
                'user_id'           => null, // THE SYSTEM ITSELF (B-2 schema)
                'status'            => ElectionBoardMember::STATUS_SEATED,
            ]);

            $this->audit->append(
                module: 'elections',
                event: 'bootstrap_board_constituted',
                payload: [
                    'election_board_id' => (string) $board->id,
                    'legislature_id'    => (string) $legislature->id,
                    'is_bootstrap'      => true,
                    'system_member_id'  => (string) $member->id,
                    'banner'            => 'temporary · replacement queued (retired by WF-ELE-10, Phase C)',
                ],
                ref: 'WF-ELE-02',
                jurisdictionId: $jurisdiction->id,
            );

            return $board;
        });
    }

    /**
     * Sizing posture for a memberless forming chamber (design §B.4):
     *  - children + type_a > ceiling → generate + activate the initial
     *    district map (San Marino: 32 type_a over 9 castelli → 5–9-seat
     *    districts). Generation failure records the blocked posture and
     *    leaves the §B.4 engine rejection to the F-ELB-001 filing.
     *  - leaf + type_a > ceiling → THE CLAMP to the ceiling
     *    (Montegiardino: 10 → 9), audited with citation.
     *
     * @return object the (possibly re-planned) legislatures row
     */
    private function applySeatPosture(Jurisdiction $jurisdiction, JurisdictionActivation $activation, object $legislature): object
    {
        $ceiling = ConstitutionalDefaults::ceiling($jurisdiction->id);
        $typeA   = (int) $legislature->type_a_seats;

        if ($typeA <= $ceiling) {
            return $legislature;
        }

        $childCount = (int) DB::table('jurisdictions')
            ->where('parent_id', $jurisdiction->id)
            ->whereNull('deleted_at')
            ->count();

        if ($childCount > 0) {
            try {
                $map = $this->initialMaps->ensureInitialMap($legislature, $jurisdiction->id);

                if ($map !== null) {
                    $activation->forceFill(['notes' => array_merge($activation->notes ?? [], [
                        'initial_district_map' => [
                            'map_id'         => $map['map_id'],
                            'district_count' => $map['district_count'],
                            'seat_vector'    => $map['seat_vector'],
                            'generated'      => $map['generated'],
                            'at'             => now()->toIso8601String(),
                        ],
                    ])])->save();
                }
            } catch (RuntimeException|ConstitutionalViolation $e) {
                // F-ELB-003 violations are already chained by the engine;
                // generation failures get their own rejected entry. Either
                // way the chamber stays in the §B.4 blocked posture and
                // activation continues (self_governing = legislature row
                // exists — design §B.3.3).
                if ($e instanceof RuntimeException) {
                    $this->audit->append(
                        module: 'elections',
                        event: 'district_map.generation_failed',
                        payload: [
                            'legislature_id' => (string) $legislature->id,
                            'type_a_seats'   => $typeA,
                            'reason'         => $e->getMessage(),
                        ],
                        ref: 'WF-ELE-02',
                        jurisdictionId: $jurisdiction->id,
                        rejected: true,
                        blockedReason: $e->getMessage() . ' (Art. II §8)',
                    );
                }
            }

            return $this->legislatureRow($jurisdiction->id) ?? $legislature;
        }

        // Cycle-2 leaf law (2026-07-19): the ceiling clamp is RETIRED. A
        // childless chamber above the ceiling keeps its lawful size — its
        // districts come from LINE-SPLIT drawing (LeafGiantResolver's
        // root-leaf context: the autoseeder or the mapper's draw surface).
        // Recorded as a posture note, never a truncation.
        $this->audit->append(
            module: 'elections',
            event: 'legislature_awaiting_line_split',
            payload: [
                'legislature_id'   => (string) $legislature->id,
                'type_a_seats'     => $typeA,
                'ceiling'          => $ceiling,
                'districts_needed' => (int) ceil($typeA / max($ceiling, 1)),
                'note'             => 'over-ceiling leaf chamber — districts by line split (cycle-2 leaf law, 2026-07-19)',
            ],
            ref: 'WF-ELE-02',
            jurisdictionId: $jurisdiction->id,
        );

        return $legislature;
    }

    /**
     * Schedule the chamber's first general election by SYSTEM-FILING
     * F-ELB-001 through the ConstitutionalEngine (null actor = the
     * bootstrap board acting as the system; design §B.3.2). Dates come
     * from ElectionLifecycleService::defaultDates — constitutional
     * windows unless config('cga.election_demo_compression') is set
     * (config, never data). An existing open-cycle election is adopted
     * (idempotent). A §B.4-blocked plan is recorded by the engine as a
     * rejected chain entry with citation; activation proceeds.
     */
    private function scheduleBootstrapElection(Jurisdiction $jurisdiction, JurisdictionActivation $activation, object $legislature): void
    {
        $existing = Election::query()
            ->where('legislature_id', (string) $legislature->id)
            ->where('kind', Election::KIND_GENERAL)
            ->whereIn('status', [Election::STATUS_SCHEDULED, Election::STATUS_APPROVAL_OPEN])
            ->orderByDesc('created_at')
            ->first();

        if ($existing !== null) {
            $this->noteBootstrapElection($activation, (string) $existing->id);

            return;
        }

        $dates = $this->lifecycle->defaultDates($jurisdiction->id);

        try {
            $result = $this->engine->file('F-ELB-001', null, [
                'jurisdiction_id'    => $jurisdiction->id,
                'legislature_id'     => (string) $legislature->id,
                'kind'               => Election::KIND_GENERAL,
                'trigger'            => 'bootstrap',
                'approval_opens_at'  => $dates['approval_opens_at']->toIso8601String(),
                'finalist_cutoff_at' => $dates['finalist_cutoff_at']->toIso8601String(),
                'ranked_opens_at'    => $dates['ranked_opens_at']->toIso8601String(),
                'ranked_closes_at'   => $dates['ranked_closes_at']->toIso8601String(),
            ]);

            $this->noteBootstrapElection($activation, (string) ($result->recorded['election_id'] ?? ''));
        } catch (ConstitutionalViolation $violation) {
            // The engine sealed the rejected=true chain entry (citation
            // included). Record the posture on the tracker row only.
            $activation->forceFill(['notes' => array_merge($activation->notes ?? [], [
                'bootstrap_election_blocked' => [
                    'reason'   => $violation->getMessage(),
                    'citation' => $violation->citation,
                    'at'       => now()->toIso8601String(),
                ],
            ])])->save();
        }
    }

    // -------------------------------------------------------------------------
    // Small shared helpers
    // -------------------------------------------------------------------------

    private function legislatureRow(string $jurisdictionId): ?object
    {
        return DB::table('legislatures')
            ->where('jurisdiction_id', $jurisdictionId)
            ->whereNull('deleted_at')
            ->first();
    }

    private function isMemberlessForming(object $legislature): bool
    {
        if (($legislature->status ?? null) !== 'forming') {
            return false;
        }

        return ! DB::table('legislature_members')
            ->where('legislature_id', $legislature->id)
            ->whereNull('deleted_at')
            ->exists();
    }

    private function noteBootstrapElection(JurisdictionActivation $activation, string $electionId): void
    {
        $notes = $activation->notes ?? [];

        if (($notes['bootstrap_election_id'] ?? null) === $electionId) {
            return;
        }

        $notes['bootstrap_election_id'] = $electionId;
        unset($notes['bootstrap_election_blocked']);

        $activation->forceFill(['notes' => $notes])->save();
    }
}
