<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Lane 3 — planet-scale institution provisioning.
 *
 * Fleshes out every jurisdiction that has a legislature so a freshly loaded
 * world arrives with its institutions already standing: an executive, a court,
 * an election board with its system member, and the civic square and halls.
 *
 * ── Why this is set-based and not a per-jurisdiction pipeline ─────────────
 * ActivationService::activate() is the right thing for ONE jurisdiction and
 * the wrong thing for 955,130. Every audited filing takes
 * pg_advisory_xact_lock(0x4155444954) — a single global appender — so a
 * per-jurisdiction pass serialises the whole fleet behind one lock for hours
 * no matter how many workers run. This service instead does what
 * AutoscaleSizingJob does for legislatures: INSERT … SELECT … ON CONFLICT
 * DO NOTHING in bounded committed chunks, with ONE audit entry per chunk
 * carrying the chunk manifest. Tamper-evidence is preserved; the planet
 * finishes in minutes rather than hours.
 *
 * ── THE ETL RULE ─────────────────────────────────────────────────────────
 * Never one planet-wide statement. Keyset pagination over jurisdiction id,
 * each chunk its own committed transaction, per-chunk progress reported
 * through the $onProgress callback so a caller can render live bars and a
 * kill mid-run loses at most one chunk.
 *
 * ── What this deliberately does NOT create ───────────────────────────────
 * 1. `jurisdiction_activations` rows. Stamping a state here would forge the
 *    Art. II §1 consent crossing (hasReached('critical_population') would
 *    become true for a planet where nobody lives) AND would re-break CLK-06
 *    by satisfying its `state <> 'boundary_loaded'` predicate everywhere.
 *    CLK-06 and `jurisdiction:activate` are that table's only lawful writers.
 * 2. Committees, departments, boards of governors. Art. II §9 reserves those
 *    to legislatures — DepartmentService says so in terms ("NEVER
 *    auto-seeded"). They arrive through F-LEG-009 / F-LEG-016 / F-EXE-001
 *    once a chamber is seated and votes. A provisioning engine that minted
 *    them would be manufacturing acts of self-government.
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
    public const STEPS = ['executives', 'judiciaries', 'election_boards', 'board_members', 'social_spaces'];

    private ?string $binding = null;

    public function __construct(private readonly AuditService $audit) {}

    /**
     * The world's population binding — a FOUNDING property, read once per run.
     *
     * `real` = institutions scale to actual population (the zero rule applies).
     * `free` = population imposes nothing; every jurisdiction gets its set.
     * Never a per-jurisdiction dial: one place cannot exempt itself from the
     * world's physics.
     */
    public function binding(): string
    {
        return $this->binding ??= (string) (DB::table('instance_settings')
            ->whereNull('deleted_at')
            ->value('population_binding') ?: InstitutionScaleService::BINDING_REAL);
    }

    /**
     * How many jurisdictions the zero rule excludes — the "nothing for nobody"
     * count, reported so the operator sees what was deliberately skipped
     * rather than silently missing.
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
     * One step, keyset-chunked. Returns rows actually created.
     *
     * @param  callable|null  $onProgress  fn(string $step, int $done, int $total, int $created): void
     */
    public function provisionStep(string $step, ?callable $onProgress = null, bool $audit = true): int
    {
        if (! in_array($step, self::STEPS, true)) {
            throw new \InvalidArgumentException("Unknown provisioning step [{$step}].");
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

    /** How many jurisdictions still lack this step's row. */
    public function pendingTotal(string $step): int
    {
        return (int) DB::scalar(
            'SELECT count(*) FROM ('.$this->sourceSql($step, withKeyset: false).') s',
            []
        );
    }

    /**
     * One chunk: select the keyset page and insert from it in a SINGLE
     * statement.
     *
     * The ids never travel to PHP and back. An earlier cut of this pulled the
     * page into PHP and rebound it as `IN (?,?,?…)` — a 25,000-placeholder
     * list per chunk, which turned a 13 ms query into ~75 ms of parse and bind
     * PER JURISDICTION and projected to roughly twenty hours for the planet
     * against a two-hour whole-planet baseline. Keeping the batch inside the
     * statement as a CTE is both faster and simpler; the counts come back from
     * the same statement so there is still exact per-chunk progress.
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
                    -- no max(uuid) aggregate exists in PostgreSQL; uuids are
                    -- orderable, so take the last of the ordered page.
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
     * live, and does not already have the row.
     */
    private function sourceSql(string $step, bool $withKeyset): string
    {
        $keyset = $withKeyset ? 'AND j.id > ?' : '';

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
            // partially-provisioned place (halls but no square, or the
            // reverse) must be repaired, not skipped. ON CONFLICT DO NOTHING
            // handles whichever one already exists.
            'social_spaces' => "(SELECT count(*) FROM social_spaces s
                                  WHERE s.jurisdiction_id = j.id
                                    AND s.space_type IN ('public_square', 'halls')
                                    AND s.is_private = false AND s.deleted_at IS NULL) < 2",
        };

        // THE ZERO RULE (operator ruling 2026-07-25). Under `real` population
        // binding an uninhabited jurisdiction gets NOTHING — empty boundary
        // rows are geodata, not polities. A place whose population reads 0
        // while it holds real constituents is a raster artefact, not an empty
        // place, so "has parts" also qualifies: its constituents still need
        // somewhere to meet. Mirrors InstitutionScaleService::tierFor(), which
        // is the reference implementation and is pinned against this SQL.
        $inhabited = $this->binding() === InstitutionScaleService::BINDING_FREE
            ? 'true'
            : "(COALESCE(j.population, 0) >= 1
                OR EXISTS (SELECT 1 FROM jurisdictions c
                            WHERE c.parent_id = j.id AND c.deleted_at IS NULL))";

        return "SELECT j.id AS jid
                  FROM jurisdictions j
                 WHERE j.deleted_at IS NULL
                   {$keyset}
                   AND {$inhabited}
                   AND EXISTS (SELECT 1 FROM legislatures l
                                WHERE l.jurisdiction_id = j.id AND l.deleted_at IS NULL)
                   AND {$missing}";
    }

    /**
     * ⚑ THE SQL MIRROR OF `InstitutionScaleService::tierFor()`.
     *
     * The PHP static is the REFERENCE IMPLEMENTATION; this is a mirror of it,
     * and `InstitutionProvisionMirrorParityTest` pins the two together across
     * every band and boundary. That is the same contract `sourceSql()` already
     * states for the zero rule ("Mirrors InstitutionScaleService::tierFor(),
     * which is the reference implementation and is pinned against this SQL"),
     * and it is why this is a MIRROR and not a fork: provisioning is set-based
     * `INSERT … SELECT` over chunks of 25,000, so it cannot call a PHP static
     * per row. One formula, two call sites, a pin holding them together.
     *
     * Requires `j` (a jurisdictions row) and `k.kids` (its live child count) in
     * scope. Expects the binding to be resolved once per run, not per row — a
     * founding property, never a per-jurisdiction dial.
     */
    private function tierSql(): string
    {
        // `free` binding: population imposes nothing, everyone gets the standard
        // set. Resolved in PHP because it is one value for the whole run.
        if ($this->binding() === InstitutionScaleService::BINDING_FREE) {
            return "'".InstitutionScaleService::TIER_STANDARD."'";
        }

        $pop = 'COALESCE(j.population, 0)';

        // THE ZERO RULE, then the bands, then the depth promotion. A place whose
        // population reads 0 while it holds real constituents is a geodata
        // artefact, not an empty place — it still needs somewhere to meet.
        //
        // The `kids >= 25` branch IS the promotion (parts count as complexity in
        // their own right), written out rather than computed so the whole
        // decision stays one readable expression: minimal→standard,
        // standard→extended, extended→full, full→full.
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
     * The bench a place's court STARTS from — `judgeCount()`'s 5/5/7/9 in SQL.
     *
     * GREATEST(5, …) is belt-and-suspenders for the Art. IV §1 floor, which the
     * `judiciaries_min_judges_check` constraint also enforces: a `none`-tier
     * place would score 0, and although the zero rule already excludes it from
     * the candidate set, a bench below 5 must be impossible by construction and
     * not merely unreachable.
     */
    private function judgeCountSql(): string
    {
        return 'GREATEST(5, CASE '.$this->tierSql()."
            WHEN 'extended' THEN 7
            WHEN 'full'     THEN 9
            ELSE 5
        END)";
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

            // Art. IV §1 — appointed by default, 10-year terms, and a bench
            // SIZED TO THE PLACE: the service-scale formula's 5/5/7/9 tier bumps
            // (SERVICE_SCALE_FORMULA.md §4.3, operator-signed-off 2026-07-29,
            // §9-Q2 ruled KEEP those values). Before this, every court on the
            // planet was minted with a 5-judge bench — a nation and a hamlet got
            // the same court, and InstitutionScaleService::judgeCount() had no
            // caller anywhere in the app. The floor is still 5 and the ceiling
            // is still none (Art. IV §1); the tiers only decide where a bench
            // STARTS, and the constituent-per-judge rule in
            // JudiciaryFormationService remains the law where it applies.
            'judiciaries' => 'INSERT INTO judiciaries
                    (id, jurisdiction_id, court_name, type, min_judges, term_years, status, created_at, updated_at)
                 SELECT gen_random_uuid(), b.jid, \'Superior Court\', \'appointed\',
                        '.$this->judgeCountSql().', 10, \'forming\', now(), now()
                   FROM batch b
                   JOIN jurisdictions j ON j.id = b.jid AND j.deleted_at IS NULL
                   LEFT JOIN LATERAL (
                       SELECT count(*) AS kids FROM jurisdictions c
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

            // K-1 Plane A — the DURABLE civic record, in Postgres, and the
            // thing a citizen actually reads. Both spaces are unconditional:
            // gating the square on a headcount would be a speech gate keyed
            // to a count (Art. I). The Matrix room that fronts a space is a
            // separate, capacity-bound question and stays demand-driven.
            'social_spaces' => "INSERT INTO social_spaces
                    (id, jurisdiction_id, space_type, title, status, is_private, created_at, updated_at)
                 SELECT gen_random_uuid(), b.jid, v.space_type, v.title, 'open', false, now(), now()
                   FROM batch b
                   CROSS JOIN (VALUES ('public_square', 'Public Square'),
                                      ('halls', 'Halls of Governance')) AS v(space_type, title)
                 ON CONFLICT DO NOTHING",
        };
    }
}
