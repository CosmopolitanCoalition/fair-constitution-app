<?php

namespace App\Services;

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
 *        unicameral (type_b = 0). Districted later if > 9 — the 5–9 cap
 *        is per district/voter pool, not chamber size.
 *      status 'forming', term_number 1.
 *   3. executive + judiciary stubs (InstitutionStubService — shared with
 *      Setup Step 4).
 *   4. → self_governing (legislature row exists).
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
     * Bicameral trigger (Art. V §3): one type-B seat per constituent
     * jurisdiction whenever constituents exist; none otherwise.
     */
    public static function typeBSeats(int $directChildren): int
    {
        return max(0, $directChildren);
    }

    /**
     * Full chamber plan:
     *  - constituents present → bicameral: type_a from Σ children
     *    population (cube root), type_b = child count;
     *  - leaf → unicameral: type_a from OWN population (cube root), no
     *    type_b.
     *
     * @return array{type_a:int, type_b:int, bicameral:bool}
     */
    public static function seatPlan(float $ownPopulation, float $childrenPopulation, int $directChildren): array
    {
        if ($directChildren > 0) {
            return [
                'type_a'    => self::cubeRootSeats($childrenPopulation),
                'type_b'    => self::typeBSeats($directChildren),
                'bicameral' => true,
            ];
        }

        return [
            'type_a'    => self::cubeRootSeats($ownPopulation),
            'type_b'    => 0,
            'bicameral' => false,
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
     * level-local cube-root type_a sizing (the proven path — respects
     * per-jurisdiction sizing-law settings), then apply the bicameral rule:
     * type_b_seats = count(direct children), one per constituent
     * (Art. V §3) — overriding the command's settings-driven
     * type_b_seats_per_child product. No --stamp-instance: activation runs
     * must never rewrite setup-wizard state.
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

        $typeA = (int) $legislature->type_a_seats;
        $typeB = self::typeBSeats($childCount);
        $total = $typeA + $typeB;

        DB::table('legislatures')
            ->where('id', $legislature->id)
            ->update([
                'type_b_seats'    => $typeB,
                'total_seats'     => $total,
                'quorum_required' => self::quorumRequired($total),
                'updated_at'      => now(),
            ]);

        $legislature->type_b_seats = $typeB;
        $legislature->total_seats  = $total;

        return $legislature;
    }

    /**
     * Leaf path: unicameral, type_a = max(5, round(∛own population)).
     * No ceiling clamp — a > 9-seat leaf chamber is districted later
     * (the 5–9 cap is per district/voter pool, not chamber size).
     */
    private function instantiateLeaf(Jurisdiction $jurisdiction): object
    {
        $plan  = self::seatPlan((float) ($jurisdiction->population ?? 0), 0.0, 0);
        $total = $plan['type_a'] + $plan['type_b'];

        $id = (string) Str::uuid();

        DB::table('legislatures')->insert([
            'id'              => $id,
            'jurisdiction_id' => $jurisdiction->id,
            'term_number'     => 1,
            'status'          => 'forming',
            'total_seats'     => $total,
            'type_a_seats'    => $plan['type_a'],
            'type_b_seats'    => $plan['type_b'],
            'quorum_required' => self::quorumRequired($total),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return (object) [
            'id'           => $id,
            'type_a_seats' => $plan['type_a'],
            'type_b_seats' => $plan['type_b'],
            'total_seats'  => $total,
        ];
    }
}
