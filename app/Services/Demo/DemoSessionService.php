<?php

namespace App\Services\Demo;

use App\Services\AuditService;
use App\Support\DemoMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * DEMO SESSIONS — the compensating purge (DemoMode ruling C).
 *
 * Lifecycle of one demo session:
 *   1. currentId() — on a demo box, an HTTP session's first constitutional
 *      filing opens a demo_sessions row keyed to the Laravel session id and
 *      stores the row id in the session (DemoMode::SESSION_KEY).
 *   2. The engine sets the PostgreSQL setting `cga.demo_session` (transaction-
 *      local) around the handler's mutation; the row-capture trigger
 *      (installCapture) records every INSERT / UPDATE / DELETE on captured
 *      tables into demo_session_writes with the row's primary key and its
 *      before and after images.
 *   3. void() — at logout (endCurrent) or expiry (voidExpired), the writes are
 *      reversed newest-first: an inserted row is soft-deleted (tables with
 *      deleted_at) or deleted only when unchanged and unreferenced. Updates
 *      are restored only when the current row matches the captured after
 *      image. Conflicts are preserved and recorded. Each write commits with
 *      its outcome, so interrupted cleanup resumes without repeating work.
 *      One `demo.session.voided` entry closes the session on the chain.
 *
 * The reversal itself runs with the setting cleared, so it is never captured.
 */
class DemoSessionService
{
    public function __construct(private readonly AuditService $audit) {}

    // ------------------------------------------------------------ session

    /**
     * The current HTTP session's demo session id, opened on first use.
     * Null off a demo box, in console, or without a started session.
     */
    public function currentId(?string $userId): ?string
    {
        if (! DemoMode::active() || ! app()->bound('session')) {
            return null;
        }
        $session = app('session');
        if (! $session->isStarted()) {
            return null;
        }

        $sid = (string) $session->getId();
        $id = $session->get(DemoMode::SESSION_KEY);

        if (is_string($id) && $id !== '') {
            $row = DB::table('demo_sessions')->where('id', $id)->whereNull('voided_at')
                ->whereNull('void_started_at')->first(['id', 'session_id']);
            if ($row !== null) {
                // Keep the Laravel session id current (it rotates at login).
                DB::table('demo_sessions')->where('id', $id)->update(array_filter([
                    'session_id' => $row->session_id !== $sid ? $sid : null,
                    'last_seen_at' => now(),
                ], fn ($v) => $v !== null));

                return $id;
            }
        }

        $id = (string) Str::uuid();
        DB::table('demo_sessions')->insert([
            'id' => $id,
            'session_id' => $sid,
            'user_id' => $userId,
            'started_at' => now(),
            'last_seen_at' => now(),
        ]);
        $session->put(DemoMode::SESSION_KEY, $id);

        return $id;
    }

    /** Void the current HTTP session's demo session, if it has one (logout). */
    public function endCurrent(string $reason = 'logout'): ?array
    {
        if (! app()->bound('session')) {
            return null;
        }
        $id = app('session')->get(DemoMode::SESSION_KEY);
        app('session')->forget(DemoMode::SESSION_KEY);

        return is_string($id) && $id !== '' ? $this->void($id, $reason) : null;
    }

    /**
     * Void every open demo session whose Laravel session no longer exists
     * (expired or garbage-collected). Driver-agnostic: the session handler's
     * read() returns '' for an expired id on the database and redis drivers.
     *
     * @return int sessions voided
     */
    public function voidExpired(): int
    {
        if (! DemoMode::active() || ! Schema::hasTable('demo_sessions')) {
            return 0;
        }
        $handler = app('session')->driver()->getHandler();
        $n = 0;
        foreach (DB::table('demo_sessions')->whereNull('voided_at')->orderBy('started_at')->cursor() as $row) {
            $alive = (string) $handler->read((string) $row->session_id) !== '';
            if ($alive && $row->void_started_at === null) {
                continue;
            }
            $this->void((string) $row->id, 'expired');
            $n++;
        }

        return $n;
    }

    // ------------------------------------------------------------ the purge

    /**
     * Reverse one demo session's writes, newest first, and close it on the chain.
     *
     * @return array{demo_session_id:string, writes:int, reversed:int, skipped:list<array{seq:int,table:string,op:string,error:string}>}
     */
    public function void(string $demoSessionId, string $reason = 'logout'): array
    {
        // Closing is durable before the first reversal. The capture trigger
        // locks this same session row and refuses new writes once closing starts.
        DB::transaction(function () use ($demoSessionId, $reason): void {
            $session = DB::table('demo_sessions')->where('id', $demoSessionId)->lockForUpdate()->first();
            if ($session !== null && $session->voided_at === null && $session->void_started_at === null) {
                DB::table('demo_sessions')->where('id', $demoSessionId)->update([
                    'void_started_at' => now(),
                    'void_reason' => $reason,
                ]);
            }
        });

        // One row per committed unit bounds memory and makes each outcome
        // resumable. Session locks also serialize simultaneous logout/expiry.
        while (true) {
            $result = DB::transaction(function () use ($demoSessionId): ?array {
                DB::statement("SELECT set_config(?, '', true)", [DemoMode::GUC]);
                $session = DB::table('demo_sessions')->where('id', $demoSessionId)->lockForUpdate()->first();
                if ($session === null) {
                    return ['demo_session_id' => $demoSessionId, 'writes' => 0, 'reversed' => 0, 'skipped' => []];
                }
                if ($session->voided_at !== null) {
                    return $this->voidReport($session, true);
                }

                $w = DB::table('demo_session_writes')->where('demo_session_id', $demoSessionId)
                    ->whereNull('resolution')->orderByDesc('seq')->first();
                if ($w !== null) {
                    $error = null;
                    try {
                        // Roll back failed SQL before persisting the skip outcome.
                        DB::transaction(fn () => $this->reverse($w));
                    } catch (\Throwable $e) {
                        // QueryException messages contain bindings, which can include
                        // private row contents. Only controlled reasons reach the chain.
                        $error = $e instanceof \Illuminate\Database\QueryException
                            ? 'database_rejected: '.($e->errorInfo[0] ?? 'unknown')
                            : ($e instanceof \RuntimeException ? Str::limit($e->getMessage(), 200) : 'reversal_failed');
                    }
                    DB::table('demo_session_writes')->where('seq', $w->seq)->update([
                        'resolution' => $error === null ? 'reversed' : 'skipped',
                        'reversal_error' => $error,
                        'resolved_at' => now(),
                    ]);

                    return null;
                }

                $report = $this->voidReport($session);
                $this->audit->append(
                    module: 'demo',
                    event: 'demo.session.voided',
                    payload: $report + [
                        'user_id' => $session->user_id,
                        'started_at' => (string) $session->started_at,
                        'reason' => $session->void_reason,
                        'tables' => DB::table('demo_session_writes')->where('demo_session_id', $demoSessionId)
                            ->selectRaw('table_name, count(*) AS total')->groupBy('table_name')->pluck('total', 'table_name')->all(),
                    ],
                    ref: 'DEMO-VOID',
                    actorId: is_string($session->user_id) ? $session->user_id : null,
                );
                DB::table('demo_sessions')->where('id', $demoSessionId)->update([
                    'voided_at' => now(), 'writes' => $report['writes'], 'reversed' => $report['reversed'],
                ]);

                return $report;
            });
            if ($result !== null) {
                return $result;
            }
        }
    }

    private function voidReport(object $session, bool $alreadyVoided = false): array
    {
        $writes = DB::table('demo_session_writes')->where('demo_session_id', $session->id);

        return [
            'demo_session_id' => (string) $session->id,
            'writes' => (clone $writes)->count(),
            'reversed' => $alreadyVoided ? 0 : (clone $writes)->where('resolution', 'reversed')->count(),
            'skipped' => (clone $writes)->where('resolution', 'skipped')->orderByDesc('seq')
                ->get(['seq', 'table_name', 'op', 'reversal_error'])->map(fn ($w) => [
                    'seq' => (int) $w->seq, 'table' => $w->table_name, 'op' => $w->op, 'error' => $w->reversal_error,
                ])->all(),
        ] + ($alreadyVoided ? ['already_voided' => true] : []);
    }

    /** Reverse a single captured write. Throws on failure (the caller records it). */
    private function reverse(object $w): void
    {
        $table = (string) $w->table_name;
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $table) || ! Schema::hasTable($table)) {
            throw new \RuntimeException("table {$table} is not a plain public table");
        }
        $pk = json_decode((string) $w->pk, true);
        if (! is_array($pk) || $pk === []) {
            throw new \RuntimeException('no primary key captured');
        }
        [$where, $bindings] = $this->pkWhere($pk);

        if (in_array((string) $w->op, ['INSERT', 'UPDATE'], true)) {
            if ($w->after === null) {
                throw new \RuntimeException('missing_after_image: legacy write preserved');
            }
            // Compare in PostgreSQL to preserve JSON types and numeric precision.
            // FOR UPDATE also blocks new foreign-key references during removal.
            $current = DB::selectOne(
                "SELECT to_jsonb(t) = ?::jsonb AS matches FROM \"{$table}\" t WHERE {$where} FOR UPDATE",
                array_merge([$w->after], $bindings)
            );
            if ($current === null || ! $current->matches) {
                throw new \RuntimeException('row_changed: current row differs from captured after image');
            }
        }

        switch ((string) $w->op) {
            case 'INSERT':
                $this->assertUnreferenced($table, $w->after);
                if (Schema::hasColumn($table, 'deleted_at')) {
                    DB::update("UPDATE \"{$table}\" SET deleted_at = now() WHERE {$where} AND deleted_at IS NULL", $bindings);
                } else {
                    DB::delete("DELETE FROM \"{$table}\" WHERE {$where}", $bindings);
                }
                break;

            case 'UPDATE':
                // Restore the before image column by column (the row keeps its identity).
                $before = json_decode((string) $w->before, true);
                if (! is_array($before)) {
                    throw new \RuntimeException('no before image captured');
                }
                foreach ($pk as $column => $value) {
                    if (($before[$column] ?? null) !== $value) {
                        throw new \RuntimeException('primary_key_changed: row identity preserved');
                    }
                }
                $cols = array_values(array_diff(array_keys($before), array_keys($pk)));
                if ($cols === []) {
                    return;
                }
                $this->assertUnreferenced($table, $w->after, $w->before);
                $set = implode(', ', array_map(fn ($c) => "\"{$c}\" = r.\"{$c}\"", $cols));
                DB::update(
                    "UPDATE \"{$table}\" t SET {$set} FROM jsonb_populate_record(NULL::\"{$table}\", ?::jsonb) r WHERE "
                    .preg_replace('/"([a-z0-9_]+)" = \?/', 't."$1" = ?', $where),
                    array_merge([$w->before], $bindings)
                );
                break;

            case 'DELETE':
                $before = json_decode((string) $w->before, true);
                if (! is_array($before)) {
                    throw new \RuntimeException('no before image captured');
                }
                $restored = DB::affectingStatement(
                    "INSERT INTO \"{$table}\" SELECT * FROM jsonb_populate_record(NULL::\"{$table}\", ?::jsonb) ON CONFLICT DO NOTHING",
                    [$w->before]
                );
                if ($restored !== 1) {
                    throw new \RuntimeException('row_replaced: existing row preserved');
                }
                break;

            default:
                throw new \RuntimeException("unknown op {$w->op}");
        }
    }

    /** Protect live dependencies even where soft delete or CASCADE would hide them. */
    private function assertUnreferenced(string $table, string $after, ?string $before = null): void
    {
        $sql = <<<'SQL'
SELECT ns.nspname AS schema_name, child.relname AS table_name,
       string_agg(format('c.%I = p.%I', ca.attname, pa.attname), ' AND ' ORDER BY ck.ord) AS predicate
FROM pg_constraint fk
JOIN pg_class child ON child.oid = fk.conrelid
JOIN pg_namespace ns ON ns.oid = child.relnamespace
CROSS JOIN LATERAL unnest(fk.conkey) WITH ORDINALITY ck(attnum, ord)
JOIN LATERAL unnest(fk.confkey) WITH ORDINALITY pk(attnum, ord) ON pk.ord = ck.ord
JOIN pg_attribute ca ON ca.attrelid = fk.conrelid AND ca.attnum = ck.attnum
JOIN pg_attribute pa ON pa.attrelid = fk.confrelid AND pa.attnum = pk.attnum
WHERE fk.contype = 'f' AND fk.confrelid = ?::regclass
GROUP BY fk.oid, ns.nspname, child.relname
SQL;
        $bindings = ['public.'.$table];
        if ($before !== null) {
            // Restoring an updated referenced key could cascade into another
            // user's row. Ordinary edits to non-key fields do not need this gate.
            $sql .= ' HAVING bool_or((?::jsonb -> pa.attname) IS DISTINCT FROM (?::jsonb -> pa.attname))';
            array_push($bindings, $after, $before);
        }
        $references = DB::select($sql, $bindings);
        foreach ($references as $ref) {
            $child = '"'.str_replace('"', '""', $ref->schema_name).'"."'.str_replace('"', '""', $ref->table_name).'"';
            // A hard delete or referenced-key update can cascade into archived
            // rows too. Only soft-deleting the parent may ignore archived links.
            $active = $before === null && Schema::hasColumn($table, 'deleted_at')
                && Schema::hasColumn($ref->schema_name.'.'.$ref->table_name, 'deleted_at')
                ? ' AND c.deleted_at IS NULL' : '';
            $found = DB::selectOne(
                "SELECT 1 FROM {$child} c, jsonb_populate_record(NULL::\"{$table}\", ?::jsonb) p WHERE {$ref->predicate}{$active} LIMIT 1",
                [$after]
            );
            if ($found !== null) {
                throw new \RuntimeException('row_referenced: '.$ref->schema_name.'.'.$ref->table_name);
            }
        }
    }

    /** @return array{0:string,1:list<mixed>} */
    private function pkWhere(array $pk): array
    {
        $parts = [];
        $bind = [];
        foreach ($pk as $col => $val) {
            if (! preg_match('/^[a-z_][a-z0-9_]*$/', (string) $col)) {
                throw new \RuntimeException("bad pk column {$col}");
            }
            $parts[] = "\"{$col}\" = ?";
            $bind[] = $val;
        }

        return [implode(' AND ', $parts), $bind];
    }

    // ------------------------------------------------------------ install

    /**
     * (Re)install the row-capture trigger on every capturable table: a plain
     * public base table with a primary key, not in DemoMode::CAPTURE_EXCLUDED.
     * Idempotent; safe to run at every deploy (new tables pick it up).
     *
     * @return array{installed:int, skipped_no_pk:list<string>, excluded:int}
     */
    public function installCapture(bool $installTriggers = true): array
    {
        // The original baseline migration calls this before the additive
        // evidence migration. Preserve that installation order on fresh boxes.
        $hasEvidence = Schema::hasColumn('demo_session_writes', 'after');
        $sessionGuard = $hasEvidence ? <<<'SQL'
    PERFORM 1 FROM demo_sessions
     WHERE id = sid::uuid AND void_started_at IS NULL AND voided_at IS NULL
     FOR SHARE;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'demo session is closing or closed' USING ERRCODE = '55000';
    END IF;
SQL : '';
        $afterColumn = $hasEvidence ? ', "after"' : '';
        $afterValue = $hasEvidence ? ", CASE WHEN TG_OP = 'DELETE' THEN NULL ELSE to_jsonb(NEW) END" : '';

        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION cga_demo_capture() RETURNS trigger
LANGUAGE plpgsql AS \$fn\$
DECLARE
    sid  text := current_setting('cga.demo_session', true);
    rec  jsonb;
    keys jsonb;
BEGIN
    IF sid IS NULL OR sid = '' THEN
        RETURN NULL;
    END IF;
{$sessionGuard}
    rec := CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END;
    SELECT jsonb_object_agg(k, rec -> k) INTO keys FROM unnest(TG_ARGV) AS k;
    INSERT INTO demo_session_writes (demo_session_id, table_name, pk, op, before, created_at{$afterColumn})
    VALUES (sid::uuid, TG_TABLE_NAME, keys, TG_OP,
            CASE WHEN TG_OP = 'INSERT' THEN NULL ELSE to_jsonb(OLD) END, now(){$afterValue});
    RETURN NULL;
END
\$fn\$;
SQL);

        if (! $installTriggers) {
            return ['installed' => 0, 'skipped_no_pk' => [], 'excluded' => 0];
        }

        $tables = DB::select(<<<'SQL'
SELECT t.table_name,
       (SELECT string_agg(quote_literal(kcu.column_name), ',' ORDER BY kcu.ordinal_position)
          FROM information_schema.table_constraints tc
          JOIN information_schema.key_column_usage kcu
            ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema
         WHERE tc.table_schema = 'public' AND tc.table_name = t.table_name AND tc.constraint_type = 'PRIMARY KEY') AS pk_args
  FROM information_schema.tables t
 WHERE t.table_schema = 'public' AND t.table_type = 'BASE TABLE'
 ORDER BY 1
SQL);

        $installed = 0;
        $noPk = [];
        $excluded = 0;
        foreach ($tables as $t) {
            $name = (string) $t->table_name;
            if (in_array($name, DemoMode::CAPTURE_EXCLUDED, true)) {
                $excluded++;
                DB::unprepared("DROP TRIGGER IF EXISTS cga_demo_capture ON \"{$name}\"");

                continue;
            }
            if ($t->pk_args === null) {
                $noPk[] = $name;

                continue;
            }
            DB::unprepared("DROP TRIGGER IF EXISTS cga_demo_capture ON \"{$name}\"");
            DB::unprepared(
                "CREATE TRIGGER cga_demo_capture AFTER INSERT OR UPDATE OR DELETE ON \"{$name}\" FOR EACH ROW "
                ."WHEN (current_setting('cga.demo_session', true) IS NOT NULL AND current_setting('cga.demo_session', true) <> '') "
                ."EXECUTE FUNCTION cga_demo_capture({$t->pk_args})"
            );
            $installed++;
        }

        return ['installed' => $installed, 'skipped_no_pk' => $noPk, 'excluded' => $excluded];
    }
}
