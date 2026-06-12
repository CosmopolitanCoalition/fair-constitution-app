<?php

namespace App\Services;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Engine\EngineResult;
use App\Domain\Forms\Contracts\ResidencyHandlerDelegate;
use App\Models\LocationPing;
use App\Models\ResidencyClaim;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WI-5 — the real residency machinery behind the F-IND-003/005/006
 * handlers (bound as ResidencyHandlerDelegate in ConstitutionProvider,
 * replacing WI-2's NoopResidencyDelegate).
 *
 * Split of responsibilities:
 *  - delegate methods (declare / recordPing / confirmVerification) run
 *    INSIDE the engine transaction, invoked by the form handlers; their
 *    return values merge into the single audit entry per filing.
 *  - public API (verify / simulatePings / qualifyingDays / thresholdDays)
 *    is what controllers and (WI-6) clock jobs call; every mutation routes
 *    through ConstitutionalEngine::file with the right F-ID.
 *
 * PRIVACY INVARIANTS:
 *  - coordinates live ONLY in location_pings and never enter audit payloads;
 *  - on verification (and on supersession of an unverified claim) the
 *    claim's raw pings are DELETEd — only `qualifying_days` and the audit
 *    entry survive.
 */
class ResidencyService implements ResidencyHandlerDelegate
{
    /** Fallback when no constitutional_settings row resolves (dev safety). */
    public const DEFAULT_THRESHOLD_DAYS = 30;

    /** Hard cap on recursive ancestor walk depth (cycle guard). */
    private const MAX_CHAIN_DEPTH = 32;

    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly RoleService $roles,
    ) {
    }

    // =========================================================================
    // ResidencyHandlerDelegate — runs inside the engine transaction
    // =========================================================================

    /**
     * F-IND-003 — create the residency claim (status: ping_monitoring).
     *
     * An open UNVERIFIED claim (declared/ping_monitoring/threshold_met/
     * verified) is superseded — that is the "correct the boundary"
     * redeclare path; its raw pings are purged (days do not transfer: the
     * boundary changed, so containment must be re-proven).
     *
     * An ACTIVE claim no longer blocks (Phase C, WF-CIV-03 — chamber ops
     * §F.2): a new declaration alongside an active claim IS relocation.
     * The active claim stays ACTIVE while the new claim monitors — rights
     * never gap; the constitutional grace is exactly the new
     * jurisdiction's CLK-05 threshold. The hand-over (supersede + transfer
     * associations + officeholder footprint check) happens at
     * confirmVerification(), when the new claim verifies.
     */
    public function declare(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'F-IND-003 is filed by the resident — system filing is not defined.',
                'Art. I'
            );
        }

        $jurisdictionId = (string) $payload['jurisdiction_id']; // validated by the handler

        $openClaims = ResidencyClaim::query()
            ->where('user_id', $actor->id)
            ->open()
            ->lockForUpdate()
            ->get();

        $supersededId     = null;
        $relocationFromId = null;

        foreach ($openClaims as $open) {
            if ($open->status === ResidencyClaim::STATUS_ACTIVE) {
                // Relocation posture: the active claim KEEPS the rights
                // until the new claim verifies (zero rights gap, Art. I).
                $relocationFromId = $open->id;

                continue;
            }

            $open->forceFill([
                'status'        => ResidencyClaim::STATUS_SUPERSEDED,
                'superseded_at' => now(),
            ])->save();

            // Privacy: a superseded monitoring claim's coordinates have no
            // further purpose — purge immediately.
            LocationPing::query()->where('claim_id', $open->id)->delete();

            $supersededId = $open->id;
        }

        $claim = ResidencyClaim::create([
            'user_id'         => $actor->id,
            'jurisdiction_id' => $jurisdictionId,
            'status'          => ResidencyClaim::STATUS_PING_MONITORING,
            'declared_at'     => now(),
            'ping_consent_at' => now(), // handler rejected the filing without consent
        ]);

        $this->roles->flushUser((string) $actor->id);

        return [
            'claim_id'                => $claim->id,
            'claim_status'            => $claim->status,
            'threshold_days'          => $this->thresholdDays($claim),
            'superseded_claim_id'     => $supersededId,
            'relocation_from_claim_id' => $relocationFromId,
        ];
    }

    /**
     * F-IND-005 — persist one location ping against the actor's monitored
     * claim and re-evaluate the qualifying-day count. NEVER returns
     * coordinates (the return value lands on the public audit chain).
     */
    public function recordPing(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'F-IND-005 is filed by the resident — system filing is not defined.',
                'Art. I'
            );
        }

        $claim = ResidencyClaim::query()
            ->where('user_id', $actor->id)
            ->whereIn('status', ResidencyClaim::MONITORING_STATUSES)
            ->lockForUpdate()
            ->first();

        if ($claim === null) {
            throw new ConstitutionalViolation(
                'No residency claim is under ping monitoring for this individual — declare residency (F-IND-003) first.',
                'Art. I'
            );
        }

        $source = $payload['source'] ?? 'manual';
        if (! in_array($source, LocationPing::SOURCES, true)) {
            $source = 'manual';
        }

        $pingedAt = now();
        if (is_string($payload['pinged_at'] ?? null)) {
            try {
                $pingedAt = Carbon::parse($payload['pinged_at']);
            } catch (\Throwable) {
                // fall back to now() — a malformed timestamp is not worth a rejection
            }
        }

        LocationPing::create([
            'user_id'         => $actor->id,
            'claim_id'        => $claim->id,
            'latitude'        => (float) $payload['latitude'],   // validated by the handler
            'longitude'       => (float) $payload['longitude'],
            'accuracy_meters' => is_numeric($payload['accuracy_meters'] ?? null)
                ? (float) $payload['accuracy_meters']
                : null,
            'source'          => $source,
            'pinged_at'       => $pingedAt,
        ]);

        $days      = $this->qualifyingDays($claim);
        $threshold = $this->thresholdDays($claim);

        $claim->qualifying_days = $days;

        if ($days >= $threshold && $claim->status === ResidencyClaim::STATUS_PING_MONITORING) {
            $claim->status           = ResidencyClaim::STATUS_THRESHOLD_MET;
            $claim->threshold_met_at = now();
        }

        $claim->save();

        // Count-bump only — coordinates never reach the audit chain.
        return [
            'claim_id'        => $claim->id,
            'qualifying_days' => $days,
            'threshold_days'  => $threshold,
            'threshold_met'   => $days >= $threshold,
        ];
    }

    /**
     * F-IND-006 — the verification sweep. Runs inside the engine
     * transaction (system filing via verify() below):
     *
     *  1. guard: threshold actually met (recomputed, never trusted);
     *  2. claim → verified → active (single update, both timestamps);
     *  3. recursive-CTE ancestor sweep from the declared jurisdiction up
     *     parent_id, PLUS dual-footprint twins of the declared jurisdiction
     *     (same footprint, different ancestry — ST_Equals with && prefilter)
     *     and THEIR ancestor chains;
     *  4. bulk insert residency_confirmations — one row per distinct
     *     enclosing jurisdiction across all chains (rights attach
     *     atomically, Art. I);
     *  5. purge the claim's raw pings (privacy — coordinates never outlive
     *     verification; qualifying_days stays on the claim).
     *
     * Returns the association jurisdiction-id list for the single F-IND-006
     * audit entry.
     */
    public function confirmVerification(?User $actor, array $payload): array
    {
        if ($actor !== null) {
            // systemOnly() on the handler already enforces this; belt-and-braces.
            throw new ConstitutionalViolation('F-IND-006 is system-filed only.', 'CGA Forms Catalog');
        }

        $claimId = $payload['claim_id'] ?? null;

        $claim = is_string($claimId)
            ? ResidencyClaim::query()->whereKey($claimId)->lockForUpdate()->first()
            : null;

        if ($claim === null) {
            throw new ConstitutionalViolation(
                'F-IND-006 requires the claim_id of the residency claim being confirmed.',
                'Art. I'
            );
        }

        if (! $claim->isMonitoring()) {
            throw new ConstitutionalViolation(
                "Residency claim [{$claim->id}] is not awaiting verification (status: {$claim->status}).",
                'Art. I'
            );
        }

        $days      = $this->qualifyingDays($claim);
        $threshold = $this->thresholdDays($claim);

        if ($days < $threshold) {
            throw new ConstitutionalViolation(
                "Residency claim [{$claim->id}] has {$days} qualifying day(s); the resolved threshold is {$threshold}.",
                'Art. I · residency_confirmation_days'
            );
        }

        // ── Ancestor + dual-footprint sweep ─────────────────────────────────
        $levels = $this->sweepJurisdictions($claim->jurisdiction_id);

        $now   = now();
        $stamp = $now->toDateTimeString(); // raw-SQL binding — PDO needs a string
        $rows  = [];
        foreach ($levels as $level) {
            $rows[] = [
                'user_id'         => $claim->user_id,
                'jurisdiction_id' => $level->id,
                'claim_id'        => $claim->id,
                'depth'           => (int) $level->depth,
                'days_confirmed'  => $days,
                'confirmed_at'    => $stamp,
                // Art. I — rights are AUTOMATIC upon association; these
                // booleans are a historical record, never a gate.
                'voting_right_active'    => true,
                'candidacy_right_active' => true,
                'is_active'              => true,
                'created_at'             => $stamp,
                'updated_at'             => $stamp,
            ];
        }

        $this->insertConfirmations($rows);

        // ── Claim verified → active ─────────────────────────────────────────
        $claim->forceFill([
            'status'                         => ResidencyClaim::STATUS_ACTIVE,
            'qualifying_days'                => $days,
            'threshold_met_at'               => $claim->threshold_met_at ?? $now,
            'threshold_days_at_verification' => $threshold,
            'verified_at'                    => $now,
        ])->save();

        // ── Relocation hand-over (Phase C, WF-CIV-03 — chamber ops §F.2) ────
        // A prior ACTIVE claim is superseded NOW (not at declaration):
        // rights never gapped because it stayed active through monitoring.
        // Its associations outside the new sweep deactivate (transferred);
        // shared ancestors (Earth, …) keep their active rows untouched.
        $newIds = array_map(fn ($level) => (string) $level->id, $levels);

        $priorActive = ResidencyClaim::query()
            ->where('user_id', $claim->user_id)
            ->whereKeyNot($claim->id)
            ->where('status', ResidencyClaim::STATUS_ACTIVE)
            ->lockForUpdate()
            ->get();

        $relocated = $priorActive->isNotEmpty();

        foreach ($priorActive as $prior) {
            $prior->forceFill([
                'status'        => ResidencyClaim::STATUS_SUPERSEDED,
                'superseded_at' => $now,
            ])->save();
        }

        $transferred = 0;

        if ($relocated) {
            $transferred = DB::table('residency_confirmations')
                ->where('user_id', $claim->user_id)
                ->where('is_active', true)
                ->whereNotIn('jurisdiction_id', $newIds)
                ->update([
                    'is_active'           => false,
                    'deactivated_at'      => $now,
                    'deactivation_reason' => 'relocation',
                    'updated_at'          => $now,
                ]);

            // Officeholder footprint check AFTER the facts commit — an
            // out-of-footprint seat system-files F-LEG-036 (reason
            // 'relocation') into the Phase B countback/special loop.
            \App\Jobs\HandleOfficeholderRelocationJob::dispatch((string) $claim->user_id)->afterCommit();
        }

        // ── Privacy purge: raw coordinates never outlive verification ───────
        $purged = LocationPing::query()->where('claim_id', $claim->id)->delete();

        $this->roles->flushUser((string) $claim->user_id);

        return [
            'association_jurisdiction_ids' => array_map(fn ($level) => $level->id, $levels),
            'association_count'            => count($levels),
            'threshold_days'               => $threshold,
            'qualifying_days'              => $days,
            'purged_pings'                 => (int) $purged,
            'relocation'                   => $relocated,
            'associations_transferred'     => $transferred,
        ];
    }

    // =========================================================================
    // Public API — controllers + (WI-6) clock jobs
    // =========================================================================

    /**
     * System-file F-IND-006 for a claim whose threshold is met. The single
     * audit entry carries the association jurisdiction-id list.
     */
    public function verify(ResidencyClaim $claim): EngineResult
    {
        return $this->engine->file('F-IND-006', null, [
            'user_id'         => (string) $claim->user_id,
            'jurisdiction_id' => (string) $claim->jurisdiction_id,
            'claim_id'        => (string) $claim->id,
            'qualifying_days' => (int) $claim->qualifying_days,
        ]);
    }

    /**
     * Dev simulator: backdated one-per-day pings at the declared
     * jurisdiction's ST_PointOnSurface, each filed through the engine as a
     * real F-IND-005 (one audit count-bump per day — the genuine path, not
     * a shortcut around it).
     *
     * @return array{simulated_days:int, qualifying_days:int, threshold_days:int, threshold_met:bool}
     */
    public function simulatePings(User $user, int $days): array
    {
        $days = max(1, min($days, 366));

        $claim = ResidencyClaim::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ResidencyClaim::MONITORING_STATUSES)
            ->first();

        if ($claim === null) {
            throw new ConstitutionalViolation(
                'No residency claim is under ping monitoring — declare residency (F-IND-003) first.',
                'Art. I'
            );
        }

        $point = DB::selectOne(
            'SELECT ST_X(p.pt) AS lng, ST_Y(p.pt) AS lat
             FROM (SELECT ST_PointOnSurface(geom) AS pt FROM jurisdictions WHERE id = ?) p',
            [$claim->jurisdiction_id]
        );

        if ($point === null || $point->lat === null) {
            throw new ConstitutionalViolation(
                'Declared jurisdiction has no boundary geometry — cannot simulate pings.',
                'Art. I'
            );
        }

        $last = null;
        for ($i = $days - 1; $i >= 0; $i--) {
            $last = $this->engine->file('F-IND-005', $user, [
                'latitude'  => (float) $point->lat,
                'longitude' => (float) $point->lng,
                'source'    => 'simulated',
                'pinged_at' => now()->subDays($i)->toIso8601String(),
            ]);
        }

        $recorded = $last?->recorded ?? [];

        return [
            'simulated_days'  => $days,
            'qualifying_days' => (int) ($recorded['qualifying_days'] ?? 0),
            'threshold_days'  => (int) ($recorded['threshold_days'] ?? $this->thresholdDays($claim)),
            'threshold_met'   => (bool) ($recorded['threshold_met'] ?? false),
        ];
    }

    /**
     * Distinct days with at least one ping inside the DECLARED boundary
     * (ST_Contains over the trigger-maintained geom point).
     */
    public function qualifyingDays(ResidencyClaim $claim): int
    {
        $row = DB::selectOne(
            "SELECT count(DISTINCT date_trunc('day', p.pinged_at)) AS days
             FROM location_pings p
             JOIN jurisdictions j ON j.id = ?
             WHERE p.claim_id = ?
               AND ST_Contains(j.geom, p.geom)",
            [$claim->jurisdiction_id, $claim->id]
        );

        return (int) ($row->days ?? 0);
    }

    /**
     * Resolved residency_confirmation_days for the claim's declared
     * jurisdiction: own constitutional_settings row → planet (adm 0) row →
     * code default. Resolved at evaluation time, never frozen at declare.
     */
    public function thresholdDays(ResidencyClaim $claim): int
    {
        $own = DB::table('constitutional_settings')
            ->where('jurisdiction_id', $claim->jurisdiction_id)
            ->value('residency_confirmation_days');

        if ($own !== null) {
            return (int) $own;
        }

        $planet = DB::table('constitutional_settings as cs')
            ->join('jurisdictions as j', 'j.id', '=', 'cs.jurisdiction_id')
            ->where('j.adm_level', 0)
            ->whereNull('j.deleted_at')
            ->orderBy('j.created_at')
            ->value('cs.residency_confirmation_days');

        return $planet !== null ? (int) $planet : self::DEFAULT_THRESHOLD_DAYS;
    }

    /**
     * The user's open claim (any non-superseded/lapsed state), if any.
     */
    public function openClaimFor(User $user): ?ResidencyClaim
    {
        return ResidencyClaim::query()
            ->where('user_id', $user->id)
            ->open()
            ->first();
    }

    /**
     * Point-first declare: the SMALLEST jurisdiction containing a WGS84
     * point (highest adm_level wins — declare the smallest boundary you
     * live inside). GIST-index-driven (jurisdictions_geom_idx); NULL when
     * no boundary contains the point (open ocean / unloaded territory).
     *
     * @return object{id: string, name: string, slug: ?string, adm_level: int}|null
     */
    public function locateJurisdiction(float $lat, float $lng): ?object
    {
        return DB::selectOne(
            'SELECT id, name, slug, adm_level
             FROM jurisdictions
             WHERE deleted_at IS NULL
               AND geom IS NOT NULL
               AND ST_Contains(geom, ST_SetSRID(ST_MakePoint(?, ?), 4326))
             ORDER BY adm_level DESC
             LIMIT 1',
            [$lng, $lat]
        );
    }

    /**
     * Root-first ancestor chain (Earth → … → the jurisdiction itself) via
     * recursive CTE up parent_id. Read-only preview for the point-first
     * declare flow — the association sweep at verification time remains
     * sweepJurisdictions() (which additionally handles dual-footprint twins).
     *
     * @return list<object{id: string, name: string, slug: ?string, adm_level: int}>
     */
    public function ancestorChain(string $jurisdictionId): array
    {
        return DB::select(
            'WITH RECURSIVE chain AS (
                SELECT j.id, j.name, j.slug, j.adm_level, j.parent_id, 0 AS depth
                FROM jurisdictions j
                WHERE j.id = ? AND j.deleted_at IS NULL

                UNION ALL

                SELECT p.id, p.name, p.slug, p.adm_level, p.parent_id, c.depth + 1
                FROM chain c
                JOIN jurisdictions p ON p.id = c.parent_id AND p.deleted_at IS NULL
                WHERE c.depth < ' . self::MAX_CHAIN_DEPTH . '
            )
            SELECT id, name, slug, adm_level::int AS adm_level
            FROM chain
            ORDER BY depth DESC',
            [$jurisdictionId]
        );
    }

    // =========================================================================

    /**
     * Every distinct enclosing jurisdiction for the declared boundary:
     * the declared jurisdiction (depth 0) + its dual-footprint twins
     * (ST_Equals, && prefiltered, declared jurisdiction only — bounded),
     * then every chain's ancestors via recursive CTE up parent_id.
     * Chains that converge (e.g. at Earth) deduplicate to MIN(depth).
     *
     * @return list<object{id: string, depth: int}>
     */
    private function sweepJurisdictions(string $declaredJurisdictionId): array
    {
        return DB::select(
            'WITH RECURSIVE roots AS (
                SELECT j.id
                FROM jurisdictions j
                WHERE j.id = ? AND j.deleted_at IS NULL

                UNION

                SELECT t.id
                FROM jurisdictions d
                JOIN jurisdictions t
                  ON t.id <> d.id
                 AND t.deleted_at IS NULL
                 AND t.geom && d.geom
                 AND ST_Equals(t.geom, d.geom)
                WHERE d.id = ? AND d.deleted_at IS NULL
            ),
            chain AS (
                SELECT r.id, 0 AS depth
                FROM roots r

                UNION ALL

                SELECT p.id, c.depth + 1
                FROM chain c
                JOIN jurisdictions j ON j.id = c.id
                JOIN jurisdictions p ON p.id = j.parent_id AND p.deleted_at IS NULL
                WHERE c.depth < ' . self::MAX_CHAIN_DEPTH . '
            )
            SELECT id, MIN(depth)::int AS depth
            FROM chain
            GROUP BY id
            ORDER BY MIN(depth), id',
            [$declaredJurisdictionId, $declaredJurisdictionId]
        );
    }

    /**
     * Bulk insert association rows. Raw SQL because the active-rows unique
     * is a PARTIAL index — ON CONFLICT must name its predicate, which the
     * query builder's upsert() cannot express. DO NOTHING keeps the sweep
     * idempotent (re-verification never duplicates an active association).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertConfirmations(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columns = array_keys($rows[0]);
        $marks   = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        $bindings = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $bindings[] = $row[$column];
            }
        }

        DB::insert(
            'INSERT INTO residency_confirmations (' . implode(', ', $columns) . ') VALUES '
            . implode(', ', array_fill(0, count($rows), $marks))
            . ' ON CONFLICT (user_id, jurisdiction_id) WHERE is_active DO NOTHING',
            $bindings
        );
    }
}
