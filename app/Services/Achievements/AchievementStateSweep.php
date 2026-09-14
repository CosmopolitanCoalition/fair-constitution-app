<?php

namespace App\Services\Achievements;

use App\Domain\Achievements\AchievementCatalog as Catalog;
use App\Models\User;
use App\Services\AchievementService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * AC-1 — the EARNER_STATE backfill/repair sweep.
 *
 * No form fires an EARNER_STATE achievement: the proving fact is a seating,
 * membership or confirmation ROW, often written outside a handler (auto-seat
 * on certification, committee assignment, speaker set, board seats). This
 * service reads those fact tables and awards the matching ACH-* row to the
 * holder the row NAMES — the earner is resolved from the fact, never from an
 * actor (the ACH-CAN-005 lesson).
 *
 * WHY A SWEEP AND WHY IT IS SAFE (the "no award on rollback" rail):
 * an uncommitted seat leaves no fact row, so the sweep can only ever award off
 * COMMITTED state. Re-certification that vacates a seat cannot un-award (the
 * ledger is append-only), so each spec reads the SETTLED state (seated /
 * active / is_active), not a transient one.
 *
 * THE ETL PARADIGM (bounded, resumable, visible): every key pages its fact
 * table by KEYSET (id > cursor ORDER BY id LIMIT chunk), commits per chunk,
 * and persists the last id to `achievement_sweep_cursors` so a killed run
 * resumes rather than rescanning. Chunk size derives from the host
 * (HostCapacity), env-overridable. Award writes are idempotent
 * (AchievementService::awardState → hasEarned + insertOrIgnore), so a re-run
 * over already-swept rows writes nothing.
 *
 * SCOPE (operator ruling 2026-09-13, achievement-wiring-scope = A): personal
 * EARNER_STATE keys whose holder is resolvable from a fact table by a direct
 * column or one member→user join. Keys needing multi-table semantic joins or
 * an ambiguous definition (CIV-006/007 association depth, VOT-003/004
 * cross-race day coverage, CAN-006 seated-with-zero-endorsements, BOG-001/004/
 * 005 board/appointment class, FED-001/002 which carry no user) are a tracked
 * follow-up — see docs/plans/education/K2_ACHIEVEMENT_WIRING.md. The ECO-*
 * state keys stay awaiting_ui (no economy screens exist yet).
 */
class AchievementStateSweep
{
    public function __construct(private readonly AchievementService $achievements)
    {
    }

    /**
     * State keys wired to the sweep, in a stable order. Each has a spec in
     * queryFor(); every key here is EARNER_STATE in the catalogue.
     *
     * @var list<string>
     */
    public const KEYS = [
        'ACH-CIV-005',
        'ACH-VOT-001', 'ACH-VOT-002',
        'ACH-LEG-009', 'ACH-LEG-011', 'ACH-LEG-012', 'ACH-LEG-013',
        'ACH-EXE-001', 'ACH-EXE-002', 'ACH-EXE-003', 'ACH-EXE-004',
        'ACH-BOG-001',
        'ACH-JUD-006', 'ACH-JUD-007', 'ACH-JUD-008',
        'ACH-ORG-002', 'ACH-ORG-003', 'ACH-ORG-004',
        'ACH-ORG-008', 'ACH-ORG-009', 'ACH-ORG-010',
        'ACH-ELB-001',
    ];

    /** True when this key has a sweep spec. */
    public static function handles(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    /**
     * A fresh keyset query for one state key: selects the keyset id as `k_id`
     * and the holder as `holder_id`, ordered by the keyset column ascending.
     * The caller adds `where k_id > cursor` and `limit`.
     *
     * @return array{0: Builder, 1: string}|null  [base query, keyset column]
     */
    public function queryFor(string $key): ?array
    {
        return match ($key) {
            'ACH-CIV-005' => [
                DB::table('residency_confirmations')
                    ->where('is_active', true)
                    ->select('id as k_id', 'user_id as holder_id'),
                'id',
            ],
            'ACH-VOT-001' => [
                DB::table('ballot_envelopes')->where('kind', 'ranked')
                    ->select('id as k_id', 'user_id as holder_id'),
                'id',
            ],
            'ACH-VOT-002' => [
                DB::table('ballot_envelopes')->where('kind', 'referendum')
                    ->select('id as k_id', 'user_id as holder_id'),
                'id',
            ],
            'ACH-LEG-009' => [
                DB::table('committee_seats as cs')
                    ->join('legislature_members as lm', 'lm.id', '=', 'cs.member_id')
                    ->where('cs.status', 'seated')
                    ->whereNull('lm.deleted_at')
                    ->select('cs.id as k_id', 'lm.user_id as holder_id'),
                'cs.id',
            ],
            'ACH-LEG-011' => [
                DB::table('committees as c')
                    ->join('legislature_members as lm', 'lm.id', '=', 'c.chair_member_id')
                    ->whereNull('c.deleted_at')
                    ->whereNull('lm.deleted_at')
                    ->select('c.id as k_id', 'lm.user_id as holder_id'),
                'c.id',
            ],
            'ACH-LEG-012' => [
                DB::table('committees as c')
                    ->join('legislature_members as lm', 'lm.id', '=', 'c.alternate_member_id')
                    ->whereNull('c.deleted_at')
                    ->whereNull('lm.deleted_at')
                    ->select('c.id as k_id', 'lm.user_id as holder_id'),
                'c.id',
            ],
            'ACH-LEG-013' => [
                DB::table('legislature_members')
                    ->where('is_speaker', true)
                    ->where('status', 'seated')
                    ->whereNull('deleted_at')
                    ->select('id as k_id', 'user_id as holder_id'),
                'id',
            ],
            'ACH-EXE-001' => [
                DB::table('executive_members')
                    ->where('role', 'principal')->where('selection', 'delegated_proportional')
                    ->where('status', 'seated')->whereNull('deleted_at')
                    ->select('id as k_id', 'user_id as holder_id'),
                'id',
            ],
            'ACH-EXE-002' => [
                DB::table('executive_members')
                    ->where('role', 'principal')->where('selection', 'elected_stv')
                    ->where('status', 'seated')->whereNull('deleted_at')
                    ->select('id as k_id', 'user_id as holder_id'),
                'id',
            ],
            'ACH-EXE-003' => [
                DB::table('executive_members')
                    ->where('role', 'principal')->where('selection', 'elected_rcv')
                    ->where('status', 'seated')->whereNull('deleted_at')
                    ->select('id as k_id', 'user_id as holder_id'),
                'id',
            ],
            'ACH-EXE-004' => [
                DB::table('executive_members')
                    ->where('role', 'advisor')->where('status', 'seated')->whereNull('deleted_at')
                    ->select('id as k_id', 'user_id as holder_id'),
                'id',
            ],
            'ACH-BOG-001' => [
                // A department's board of governors: board_seats whose board is
                // governed by a department. `boards.boardable_type` holds the
                // owner's table name ('departments'); see Board::BOARDABLE_DEPARTMENTS.
                DB::table('board_seats as bs')
                    ->join('boards as b', 'b.id', '=', 'bs.board_id')
                    ->where('bs.status', 'seated')
                    ->where('b.boardable_type', \App\Models\Board::BOARDABLE_DEPARTMENTS)
                    ->whereNotNull('bs.holder_user_id')
                    ->whereNull('bs.deleted_at')
                    ->select('bs.id as k_id', 'bs.holder_user_id as holder_id'),
                'bs.id',
            ],
            'ACH-JUD-006' => [
                DB::table('jury_members')->whereNull('deleted_at')
                    ->select('id as k_id', 'user_id as holder_id'),
                'id',
            ],
            'ACH-JUD-007' => [
                DB::table('jury_members')->where('screening_status', 'empaneled')->whereNull('deleted_at')
                    ->select('id as k_id', 'user_id as holder_id'),
                'id',
            ],
            'ACH-JUD-008' => [
                DB::table('judicial_seats')->where('status', 'seated')->whereNull('deleted_at')
                    ->select('id as k_id', 'user_id as holder_id'),
                'id',
            ],
            'ACH-ORG-002' => [
                DB::table('organizations')->whereNotNull('agent_user_id')->whereNull('deleted_at')
                    ->select('id as k_id', 'agent_user_id as holder_id'),
                'id',
            ],
            'ACH-ORG-003' => [
                DB::table('org_memberships')->where('status', 'active')->whereNull('deleted_at')
                    ->select('id as k_id', 'user_id as holder_id'),
                'id',
            ],
            'ACH-ORG-004' => [
                DB::table('org_workers')->where('status', 'active')->whereNull('deleted_at')
                    ->select('id as k_id', 'user_id as holder_id'),
                'id',
            ],
            'ACH-ORG-008' => [
                DB::table('board_seats')->where('seat_class', 'owner_elected')->where('status', 'seated')
                    ->whereNotNull('holder_user_id')->whereNull('deleted_at')
                    ->select('id as k_id', 'holder_user_id as holder_id'),
                'id',
            ],
            'ACH-ORG-009' => [
                DB::table('board_seats')->where('seat_class', 'worker_elected')->where('status', 'seated')
                    ->whereNotNull('holder_user_id')->whereNull('deleted_at')
                    ->select('id as k_id', 'holder_user_id as holder_id'),
                'id',
            ],
            'ACH-ORG-010' => [
                DB::table('board_seats')->where('is_chair', true)->where('status', 'seated')
                    ->whereNotNull('holder_user_id')->whereNull('deleted_at')
                    ->select('id as k_id', 'holder_user_id as holder_id'),
                'id',
            ],
            'ACH-ELB-001' => [
                DB::table('election_board_members')->whereNull('deleted_at')
                    ->select('id as k_id', 'user_id as holder_id'),
                'id',
            ],
            default => null,
        };
    }

    /**
     * Sweep one state key. Pages by keyset from the persisted cursor, awards
     * the holder of each row (idempotent), commits the cursor per chunk, and
     * reports each chunk through $progress.
     *
     * @param  callable(array{key:string,scanned:int,awarded:int,cursor:?string,chunk:int}):void  $progress
     * @return array{scanned:int, awarded:int}
     */
    public function runKey(string $key, int $chunk, callable $progress): array
    {
        $spec = $this->queryFor($key);
        if ($spec === null) {
            return ['scanned' => 0, 'awarded' => 0];
        }

        // EARNER_STATE only — awardState refuses a mis-moded key, so this also
        // guards against a KEYS drift.
        if (Catalog::get($key)['earner'] !== Catalog::EARNER_STATE) {
            return ['scanned' => 0, 'awarded' => 0];
        }

        [, $keyset] = $spec;
        $cursor = $this->cursor($key);
        $scanned = 0;
        $awarded = 0;

        while (true) {
            [$base] = $this->queryFor($key); // fresh builder each chunk
            /** @var Builder $base */
            $q = $base->orderBy($keyset);
            if ($cursor !== null) {
                $q->where($keyset, '>', $cursor);
            }
            $rows = $q->limit($chunk)->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $scanned++;
                $cursor = (string) $row->k_id;
                $holderId = $row->holder_id === null ? null : (string) $row->holder_id;
                if ($holderId === null) {
                    continue;
                }
                $holder = User::find($holderId);
                if ($holder === null) {
                    continue;
                }
                if ($this->achievements->awardState($holder, $key)) {
                    $awarded++;
                }
            }

            // Commit the cursor per chunk: a kill mid-run costs one chunk.
            $this->saveCursor($key, $cursor);

            $progress([
                'key' => $key,
                'scanned' => $scanned,
                'awarded' => $awarded,
                'cursor' => $cursor,
                'chunk' => $rows->count(),
            ]);

            if ($rows->count() < $chunk) {
                break;
            }
        }

        // Completed the ordered scan: clear the cursor so the NEXT scheduled run
        // rescans every fact row from the start. The keyset id is a random
        // gen_random_uuid(), so a persisted high-water id would skip any holder
        // created afterward whose id sorts below it (near-certain), making the
        // nightly sweep a no-op after the first backfill. awardState is
        // idempotent (hasEarned + insertOrIgnore), so a full rescan writes
        // nothing for already-awarded holders. The per-chunk cursor above still
        // serves its purpose: a run KILLED mid-scan resumes from the last
        // committed chunk. Recovery keeps the cursor; completion clears it.
        $this->saveCursor($key, null);

        return ['scanned' => $scanned, 'awarded' => $awarded];
    }

    /**
     * Total rows this key's spec matches (for an honest ETA). One bounded
     * aggregate over the filtered fact table, not a planet-wide join.
     * Returns null when the count cannot be taken so the caller shows "n/a"
     * rather than a fabricated figure.
     */
    public function countFor(string $key): ?int
    {
        $spec = $this->queryFor($key);
        if ($spec === null) {
            return null;
        }

        try {
            [$base, $keyset] = $spec;

            return (int) $base->reorder()->count($keyset);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Persisted keyset cursor for a key, or null to start from the beginning. */
    public function cursor(string $key): ?string
    {
        $v = DB::table('achievement_sweep_cursors')->where('key', $key)->value('cursor');

        return $v === null ? null : (string) $v;
    }

    private function saveCursor(string $key, ?string $cursor): void
    {
        DB::table('achievement_sweep_cursors')->updateOrInsert(
            ['key' => $key],
            ['cursor' => $cursor, 'updated_at' => now()],
        );
    }
}
