<?php

namespace App\Services;

use App\Support\BenchLaw;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Planet-scale institution provisioning: the SHELL SET.
 *
 * Fleshes out every jurisdiction that has a legislature so a freshly loaded
 * world arrives with its institutions already standing: an executive, a court,
 * an election board with its system member, the civic square and halls, and
 * (Wave 6, the money plane) a public treasury in the founding currency.
 *
 * ── Two callers, one SQL ────────────────────────────────────────────────
 * 1. The keyset walk (provisionStep / provisionAll): the whole planet in
 *    committed chunks of CHUNK rows — institutions:provision and /building.
 * 2. The claim-scoped batch (provisionClaim): the Step 4 engine's shell lane
 *    claims a batch of provision_ledger rows under one token and runs the
 *    same INSERT … SELECT over exactly those jurisdictions. The candidate set
 *    is the ledger join, never a bound id list.
 *
 * ── Why this is set-based and not a per-jurisdiction pipeline ─────────────
 * ActivationService::activate() is the right thing for ONE jurisdiction and
 * the wrong thing for 955,130. Every audited filing takes
 * pg_advisory_xact_lock(0x4155444954) — a single global appender — so a
 * per-jurisdiction pass serialises the whole fleet behind one lock for hours
 * no matter how many workers run. This service does what AutoscaleSizingJob
 * does for legislatures: INSERT … SELECT … ON CONFLICT DO NOTHING in bounded
 * committed chunks, with ONE audit entry per chunk carrying the manifest.
 *
 * ── THE ETL RULE ─────────────────────────────────────────────────────────
 * Never one planet-wide statement. Keyset pagination over jurisdiction id,
 * each chunk its own committed transaction, per-chunk progress through the
 * $onProgress callback so a caller can render live bars and a kill mid-run
 * loses at most one chunk.
 *
 * ── What this deliberately does NOT create ───────────────────────────────
 * 1. `jurisdiction_activations` rows. Stamping a state here would forge the
 *    Art. II §1 consent crossing and re-break CLK-06. CLK-06 and
 *    `jurisdiction:activate` are that table's only lawful writers.
 * 2. Committees and departments. Those are system acts at Step 4 (ruling
 *    sub-institutions-path B, 2026-09-05) filed per legislature by the unit
 *    lane through F-LEG-009 / F-LEG-016, never set-based rows.
 * 3. Members, seats, appointments. Those come from the elections engine.
 *
 * Everything here is idempotent: safe to re-run, safe under concurrent
 * workers (the partial unique indexes from
 * 2026_07_25_000002_institution_live_uniqueness make a loser a silent no-op),
 * and safe to resume after a kill.
 */
class InstitutionProvisionService
{
    /** Rows per committed chunk. Matches AutoscaleEnumeration's proven size. */
    public const CHUNK = 25000;

    private const ZERO_UUID = '00000000-0000-0000-0000-000000000000';

    /** The steps this service provisions, in dependency order. */
    public const STEPS = ['executives', 'judiciaries', 'election_boards', 'board_members', 'social_spaces', 'treasuries'];

    private ?string $binding = null;

    /** The founding currency id, memoized per run; '' when none exists. */
    private ?string $currencyId = null;

    public function __construct(private readonly AuditService $audit) {}

    /**
     * The world's population binding — a FOUNDING property, read once per run.
     *
     * `real` = institutions scale to actual population (the zero rule applies).
     * `free` = population imposes nothing; every jurisdiction gets its set.
     */
    public function binding(): string
    {
        return $this->binding ??= (string) (DB::table('instance_settings')
            ->whereNull('deleted_at')
            ->value('population_binding') ?: InstitutionScaleService::BINDING_REAL);
    }

    /**
     * The founding currency (Art. V §5, the root's). NULL when the world has
     * no currency yet — the treasuries step then has nothing to do.
     */
    public function currencyId(): ?string
    {
        if ($this->currencyId === null) {
            $id = DB::table('currencies')->whereNull('deleted_at')->orderBy('created_at')->value('id');
            $this->currencyId = $id !== null && Str::isUuid((string) $id) ? (string) $id : '';
        }

        return $this->currencyId === '' ? null : $this->currencyId;
    }

    /** Forget the memoized currency (the founding service calls this after minting). */
    public function forgetCurrency(): void
    {
        $this->currencyId = null;
    }

    /**
     * How many jurisdictions the zero rule excludes — the "nothing for nobody"
     * count, reported so the operator sees what was deliberately skipped.
     */
    public function skippedUninhabited(): int
    {
        if ($this->binding() === InstitutionScaleService::BINDING_FREE) {
            return 0;
        }

        return (int) DB::scalar('
            SELECT count(*) FROM jurisdictions j
             WHERE j.deleted_at IS NULL
               AND COALESCE(j.population, 0) < 1
               AND NOT EXISTS (SELECT 1 FROM jurisdictions c
                                WHERE c.parent_id = j.id AND c.deleted_at IS NULL)
               AND EXISTS (SELECT 1 FROM legislatures l
                            WHERE l.jurisdiction_id = j.id AND l.deleted_at IS NULL)
        ');
    }

    /**
     * Provision every step across every jurisdiction holding a legislature.
     *
     * @param  callable|null  $onProgress  fn(string $step, int $done, int $total, int $created): void
     * @return array<string,int>  rows actually created per step
     */
    public function provisionAll(?callable $onProgress = null, bool $audit = true): array
    {
        $created = [];

        foreach (self::STEPS as $step) {
            $created[$step] = $this->provisionStep($step, $onProgress, $audit);
        }

        return $created;
    }

    /**
     * One step, keyset-chunked over the planet. Returns rows actually created.
     *
     * @param  callable|null  $onProgress  fn(string $step, int $done, int $total, int $created): void
     */
    public function provisionStep(string $step, ?callable $onProgress = null, bool $audit = true): int
    {
        self::assertStep($step);

        if ($step === 'treasuries' && $this->currencyId() === null) {
            return 0;
        }

        $total     = $this->pendingTotal($step);
        $afterId   = self::ZERO_UUID;
        $created   = 0;
        $processed = 0;

        while (true) {
            // Each chunk is its own committed transaction — THE ETL RULE.
            $result = DB::transaction(function () use ($step, $afterId): array {
                return $this->runChunk($step, $afterId);
            });

            if ($result['scanned'] === 0) {
                break;
            }

            $afterId    = $result['last_id'];
            $created   += $result['created'];
            $processed += $result['scanned'];

            if ($onProgress !== null) {
                $onProgress($step, $processed, $total, $created);
            }

            if ($result['scanned'] < self::CHUNK) {
                break;
            }
        }

        // ONE chain entry per step, not per jurisdiction — the autoscale
        // revert precedent. The manifest is what makes it auditable.
        if ($audit && $created > 0) {
            $this->audit->append(
                module: 'jurisdictions',
                event: 'institutions_provisioned',
                payload: [
                    'step'      => $step,
                    'created'   => $created,
                    'scanned'   => $processed,
                    'chunk'     => self::CHUNK,
                    'set_based' => true,
                ],
                ref: 'WF-JUR-01',
            );
        }

        return $created;
    }

    /**
     * One step over ONE CLAIM: the provision_ledger rows held under
     * $claimToken (the Step 4 shell lane). One statement, committed by the
     * caller's chunk boundary. Returns rows actually created.
     */
    public function provisionClaim(string $step, string $claimToken): int
    {
        self::assertStep($step);

        if ($step === 'treasuries' && $this->currencyId() === null) {
            return 0;
        }

        $row = DB::selectOne(
            "WITH batch AS (
                 {$this->sourceSql($step, withKeyset: false, claimScoped: true)}
             ),
             ins AS (
                 {$this->insertSql($step)}
                 RETURNING 1
             )
             SELECT (SELECT count(*) FROM ins) AS created",
            [$claimToken]
        );

        return (int) ($row->created ?? 0);
    }

    /** How many jurisdictions still lack this step's row. */
    public function pendingTotal(string $step): int
    {
        self::assertStep($step);

        if ($step === 'treasuries' && $this->currencyId() === null) {
            return 0;
        }

        return (int) DB::scalar(
            'SELECT count(*) FROM ('.$this->sourceSql($step, withKeyset: false).') s',
            []
        );
    }

    public static function assertStep(string $step): void
    {
        if (! in_array($step, self::STEPS, true)) {
            throw new \InvalidArgumentException("Unknown provisioning step [{$step}].");
        }
    }

    /**
     * One chunk: select the keyset page and insert from it in a SINGLE
     * statement. The ids never travel to PHP and back.
     *
     * @return array{scanned:int, created:int, last_id:string}
     */
    private function runChunk(string $step, string $afterId): array
    {
        $row = DB::selectOne(
            "WITH batch AS (
                 {$this->sourceSql($step, withKeyset: true)}
                 ORDER BY jid
                 LIMIT ?
             ),
             ins AS (
                 {$this->insertSql($step)}
                 RETURNING 1
             )
             SELECT (SELECT count(*) FROM batch) AS scanned,
                    (SELECT count(*) FROM ins)   AS created,
                    (SELECT jid FROM batch ORDER BY jid DESC LIMIT 1) AS last_id",
            [$afterId, self::CHUNK]
        );

        $scanned = (int) ($row->scanned ?? 0);

        if ($scanned === 0) {
            return ['scanned' => 0, 'created' => 0, 'last_id' => $afterId];
        }

        return [
            'scanned' => $scanned,
            'created' => (int) ($row->created ?? 0),
            'last_id' => (string) ($row->last_id ?? $afterId),
        ];
    }

    /**
     * Candidate jurisdictions for a step: has a live legislature, is itself
     * live, is inhabited under the binding, and does not already have the row.
     * Claim-scoped: the same predicates over the ledger rows under one token.
     */
    private function sourceSql(string $step, bool $withKeyset, bool $claimScoped = false): string
    {
        $missing = match ($step) {
            'executives' => 'NOT EXISTS (SELECT 1 FROM executives e
                                          WHERE e.jurisdiction_id = j.id AND e.deleted_at IS NULL)',
            'judiciaries' => 'NOT EXISTS (SELECT 1 FROM judiciaries jd
                                           WHERE jd.jurisdiction_id = j.id AND jd.deleted_at IS NULL)',
            'election_boards' => "NOT EXISTS (SELECT 1 FROM election_boards b
                                               WHERE b.jurisdiction_id = j.id
                                                 AND b.status = 'active' AND b.deleted_at IS NULL)",
            'board_members' => "EXISTS (SELECT 1 FROM election_boards b
                                         WHERE b.jurisdiction_id = j.id
                                           AND b.status = 'active' AND b.deleted_at IS NULL)
                                AND NOT EXISTS (SELECT 1 FROM election_board_members m
                                                 JOIN election_boards b2 ON b2.id = m.election_board_id
                                                WHERE b2.jurisdiction_id = j.id
                                                  AND m.user_id IS NULL AND m.deleted_at IS NULL)",
            // EITHER space missing makes the jurisdiction a candidate — a
            // partially-provisioned place must be repaired, not skipped.
            'social_spaces' => "(SELECT count(*) FROM social_spaces s
                                  WHERE s.jurisdiction_id = j.id
                                    AND s.space_type IN ('public_square', 'halls')
                                    AND s.is_private = false AND s.deleted_at IS NULL) < 2",
            // THE MONEY PLANE (Wave 6): one public treasury per jurisdiction in
            // the founding currency (Art. II §9 creates the Treasury; Art. V §5
            // reserves the currency to the root).
            'treasuries' => "NOT EXISTS (SELECT 1 FROM treasury_accounts t
                                          WHERE t.owner_type = 'jurisdictions' AND t.owner_id = j.id
                                            AND t.currency_id = '{$this->currencyLiteral()}'::uuid
                                            AND t.deleted_at IS NULL)",
        };

        // THE ZERO RULE (operator ruling 2026-07-25). Under `real` population
        // binding an uninhabited jurisdiction gets NOTHING — empty boundary
        // rows are geodata, not polities. A place whose population reads 0
        // while it holds real constituents is a raster artefact, not an empty
        // place, so "has parts" also qualifies. Mirrors
        // InstitutionScaleService::tierFor(), pinned by the parity test.
        $inhabited = $this->binding() === InstitutionScaleService::BINDING_FREE
            ? 'true'
            : "(COALESCE(j.population, 0) >= 1
                OR EXISTS (SELECT 1 FROM jurisdictions c
                            WHERE c.parent_id = j.id AND c.deleted_at IS NULL))";

        if ($claimScoped) {
            return "SELECT j.id AS jid
                      FROM provision_ledger pl
                      JOIN jurisdictions j ON j.id = pl.jurisdiction_id AND j.deleted_at IS NULL
                     WHERE pl.claim_token = ?::uuid AND pl.status = 'running'
                       AND {$inhabited}
                       AND EXISTS (SELECT 1 FROM legislatures l
                                    WHERE l.jurisdiction_id = j.id AND l.deleted_at IS NULL)
                       AND {$missing}";
        }

        $keyset = $withKeyset ? 'AND j.id > ?' : '';

        return "SELECT j.id AS jid
                  FROM jurisdictions j
                 WHERE j.deleted_at IS NULL
                   {$keyset}
                   AND {$inhabited}
                   AND EXISTS (SELECT 1 FROM legislatures l
                                WHERE l.jurisdiction_id = j.id AND l.deleted_at IS NULL)
                   AND {$missing}";
    }

    /** The founding currency id as a validated literal (never a bind, so the bind list stays fixed). */
    private function currencyLiteral(): string
    {
        $id = $this->currencyId();
        if ($id === null || ! Str::isUuid($id)) {
            return self::ZERO_UUID;
        }

        return $id;
    }

    /**
     * ⚑ THE SQL MIRROR OF `InstitutionScaleService::tierFor()`.
     *
     * The PHP static is the REFERENCE IMPLEMENTATION; this is a mirror of it,
     * and `InstitutionProvisionMirrorParityTest` pins the two together across
     * every band and boundary. Requires `j` (a jurisdictions row) and `k.kids`
     * (its live child count) in scope. Kept for the parity pin and for callers
     * that report a place's tier; the bench no longer reads it (bench law).
     */
    private function tierSql(): string
    {
        if ($this->binding() === InstitutionScaleService::BINDING_FREE) {
            return "'".InstitutionScaleService::TIER_STANDARD."'";
        }

        $pop = 'COALESCE(j.population, 0)';

        return "CASE
            WHEN {$pop} < 1 THEN (CASE WHEN k.kids > 0 THEN 'minimal' ELSE 'none' END)
            WHEN k.kids >= 25 THEN (CASE
                WHEN {$pop} >= 250000 THEN 'full'
                WHEN {$pop} >= 1000   THEN 'extended'
                ELSE 'standard'
            END)
            ELSE (CASE
                WHEN {$pop} >= 10000000 THEN 'full'
                WHEN {$pop} >= 250000   THEN 'extended'
                WHEN {$pop} >= 1000     THEN 'standard'
                ELSE 'minimal'
            END)
        END";
    }

    /**
     * ⚑ THE BENCH LAW IN SQL (operator ruling 2026-09-05, bench-scaling-law B):
     * bench = max(floor, next odd >= type_a_seats / 10); a court with n
     * constituents takes it as a minimum multiple. The floor is the
     * jurisdiction's OWN judiciary_min_judges_per_race row, then the root's,
     * then 5. Requires `l.type_a_seats`, `own.judiciary_min_judges_per_race`
     * and `k.constituents` in scope (see the judiciaries insert). BenchLaw::sql
     * is the single arithmetic; BenchLawTest pins PHP and SQL together.
     */
    private function judgeCountSql(): string
    {
        $floor = 'COALESCE(own.judiciary_min_judges_per_race,
                           (SELECT cs.judiciary_min_judges_per_race
                              FROM constitutional_settings cs
                              JOIN jurisdictions r ON r.id = cs.jurisdiction_id
                             WHERE r.parent_id IS NULL AND r.deleted_at IS NULL
                             ORDER BY r.created_at LIMIT 1),
                           5)';

        return BenchLaw::sql('COALESCE(l.type_a_seats, 0)', $floor, 'COALESCE(k.constituents, 0)');
    }

    /**
     * The INSERT half, reading from the `batch` CTE. No bound id lists.
     */
    private function insertSql(string $step): string
    {
        return match ($step) {
            // Art. III — executives start as legislature-delegated committees.
            'executives' => "INSERT INTO executives
                    (id, jurisdiction_id, type, term_number, status, created_at, updated_at)
                 SELECT gen_random_uuid(), b.jid, 'committee', 1, 'forming', now(), now()
                   FROM batch b
                 ON CONFLICT DO NOTHING",

            // Art. IV §1 — appointed by default, 10-year terms, a bench sized
            // by THE BENCH LAW over the chamber's Type A seats and the floor
            // setting, a minimum multiple where constituents nominate.
            'judiciaries' => 'INSERT INTO judiciaries
                    (id, jurisdiction_id, court_name, type, min_judges, term_years, status, created_at, updated_at)
                 SELECT gen_random_uuid(), b.jid, \'Superior Court\', \'appointed\',
                        '.$this->judgeCountSql().', 10, \'forming\', now(), now()
                   FROM batch b
                   JOIN jurisdictions j ON j.id = b.jid AND j.deleted_at IS NULL
                   LEFT JOIN LATERAL (
                       SELECT l0.type_a_seats FROM legislatures l0
                        WHERE l0.jurisdiction_id = j.id AND l0.deleted_at IS NULL
                        ORDER BY l0.created_at LIMIT 1
                   ) l ON true
                   LEFT JOIN constitutional_settings own ON own.jurisdiction_id = j.id
                   LEFT JOIN LATERAL (
                       SELECT count(*) AS constituents FROM jurisdictions c
                         JOIN legislatures cl ON cl.jurisdiction_id = c.id
                                             AND cl.deleted_at IS NULL AND cl.status <> \'dissolved\'
                        WHERE c.parent_id = j.id AND c.deleted_at IS NULL
                   ) k ON true
                 ON CONFLICT DO NOTHING',

            // The bootstrap board (R-08 substrate) — retired by WF-ELE-10.
            'election_boards' => "INSERT INTO election_boards
                    (id, jurisdiction_id, legislature_id, is_bootstrap, status, created_at, updated_at)
                 SELECT gen_random_uuid(), b.jid,
                        (SELECT l.id FROM legislatures l
                          WHERE l.jurisdiction_id = b.jid AND l.deleted_at IS NULL LIMIT 1),
                        true, 'active', now(), now()
                   FROM batch b
                 ON CONFLICT DO NOTHING",

            // user_id NULL = THE SYSTEM ITSELF (B-2 schema), always seated.
            'board_members' => "INSERT INTO election_board_members
                    (id, election_board_id, user_id, status, created_at, updated_at)
                 SELECT gen_random_uuid(), eb.id, NULL, 'seated', now(), now()
                   FROM batch b
                   JOIN election_boards eb
                     ON eb.jurisdiction_id = b.jid
                    AND eb.status = 'active' AND eb.deleted_at IS NULL
                 ON CONFLICT DO NOTHING",

            // K-1 Plane A — the DURABLE civic record. Both spaces are
            // unconditional: gating the square on a headcount would be a
            // speech gate keyed to a count (Art. I).
            'social_spaces' => "INSERT INTO social_spaces
                    (id, jurisdiction_id, space_type, title, status, is_private, created_at, updated_at)
                 SELECT gen_random_uuid(), b.jid, v.space_type, v.title, 'open', false, now(), now()
                   FROM batch b
                   CROSS JOIN (VALUES ('public_square', 'Public Square'),
                                      ('halls', 'Halls of Governance')) AS v(space_type, title)
                 ON CONFLICT DO NOTHING",

            // Public money, public by construction (Art. III §4). Balance 0:
            // the mint and the stipend runs are the sim's and the chamber's.
            'treasuries' => "INSERT INTO treasury_accounts
                    (id, owner_type, owner_id, currency_id, balance, public, label, created_at, updated_at)
                 SELECT gen_random_uuid(), 'jurisdictions', b.jid, '{$this->currencyLiteral()}'::uuid,
                        0, true, 'Public Treasury', now(), now()
                   FROM batch b
                 ON CONFLICT DO NOTHING",
        };
    }
}
