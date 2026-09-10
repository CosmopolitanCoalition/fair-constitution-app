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
 *      tables into demo_session_writes with the row's primary key and, for
 *      updates and deletes, the row's BEFORE image.
 *   3. void() — at logout (endCurrent) or expiry (voidExpired), the writes are
 *      reversed newest-first: an inserted row is soft-deleted (tables with
 *      deleted_at) or deleted; an updated row gets its before image back; a
 *      deleted row is re-inserted. A write that cannot be reversed (a foreign
 *      key from another live session, a vanished table) is skipped and named
 *      in the audit entry, never retried in a loop. One `demo.session.voided`
 *      entry closes the session on the chain.
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
        $id  = $session->get(DemoMode::SESSION_KEY);

        if (is_string($id) && $id !== '') {
            $row = DB::table('demo_sessions')->where('id', $id)->whereNull('voided_at')->first(['id', 'session_id']);
            if ($row !== null) {
                // Keep the Laravel session id current (it rotates at login).
                DB::table('demo_sessions')->where('id', $id)->update(array_filter([
                    'session_id'   => $row->session_id !== $sid ? $sid : null,
                    'last_seen_at' => now(),
                ], fn ($v) => $v !== null));

                return $id;
            }
        }

        $id = (string) Str::uuid();
        DB::table('demo_sessions')->insert([
            'id'           => $id,
            'session_id'   => $sid,
            'user_id'      => $userId,
            'started_at'   => now(),
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
            if ($alive) {
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
        $session = DB::table('demo_sessions')->where('id', $demoSessionId)->first();
        if ($session === null) {
            return ['demo_session_id' => $demoSessionId, 'writes' => 0, 'reversed' => 0, 'skipped' => []];
        }
        if ($session->voided_at !== null) {
            return [
                'demo_session_id' => $demoSessionId,
                'writes' => (int) DB::table('demo_session_writes')->where('demo_session_id', $demoSessionId)->count(),
                'reversed' => 0, 'skipped' => [], 'already_voided' => true,
            ];
        }

        return DB::transaction(function () use ($demoSessionId, $session, $reason): array {
            // Never capture the purge itself.
            DB::statement("SELECT set_config(?, '', true)", [DemoMode::GUC]);
            // One purge per session: lock the row.
            DB::table('demo_sessions')->where('id', $demoSessionId)->lockForUpdate()->first(['id']);

            $writes = DB::table('demo_session_writes')
                ->where('demo_session_id', $demoSessionId)
                ->orderByDesc('seq')
                ->get();

            $reversed = 0;
            $skipped  = [];
            foreach ($writes as $w) {
                try {
                    // A savepoint per write, so one failure never poisons the rest.
                    DB::transaction(fn () => $this->reverse($w));
                    $reversed++;
                } catch (\Throwable $e) {
                    $skipped[] = [
                        'seq'   => (int) $w->seq,
                        'table' => (string) $w->table_name,
                        'op'    => (string) $w->op,
                        'error' => Str::limit($e->getMessage(), 200),
                    ];
                }
            }

            DB::table('demo_sessions')->where('id', $demoSessionId)->update([
                'voided_at'    => now(),
                'void_reason'  => $reason,
                'writes'       => $writes->count(),
                'reversed'     => $reversed,
            ]);

            $this->audit->append(
                module: 'demo',
                event: 'demo.session.voided',
                payload: [
                    'demo_session_id' => $demoSessionId,
                    'user_id'         => $session->user_id,
                    'started_at'      => (string) $session->started_at,
                    'reason'          => $reason,
                    'writes'          => $writes->count(),
                    'reversed'        => $reversed,
                    'skipped'         => $skipped,
                    'tables'          => $writes->groupBy('table_name')->map->count()->all(),
                ],
                ref: 'DEMO-VOID',
                actorId: is_string($session->user_id) ? $session->user_id : null,
            );

            return [
                'demo_session_id' => $demoSessionId,
                'writes'          => $writes->count(),
                'reversed'        => $reversed,
                'skipped'         => $skipped,
            ];
        });
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

        switch ((string) $w->op) {
            case 'INSERT':
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
                $cols = array_values(array_diff(array_keys($before), array_keys($pk)));
                if ($cols === []) {
                    return;
                }
                $set = implode(', ', array_map(fn ($c) => "\"{$c}\" = r.\"{$c}\"", $cols));
                DB::update(
                    "UPDATE \"{$table}\" t SET {$set} FROM jsonb_populate_record(NULL::\"{$table}\", ?::jsonb) r WHERE "
                    . preg_replace('/"([a-z0-9_]+)" = \?/', 't."$1" = ?', $where),
                    array_merge([json_encode($before)], $bindings)
                );
                break;

            case 'DELETE':
                $before = json_decode((string) $w->before, true);
                if (! is_array($before)) {
                    throw new \RuntimeException('no before image captured');
                }
                DB::insert(
                    "INSERT INTO \"{$table}\" SELECT * FROM jsonb_populate_record(NULL::\"{$table}\", ?::jsonb) ON CONFLICT DO NOTHING",
                    [json_encode($before)]
                );
                break;

            default:
                throw new \RuntimeException("unknown op {$w->op}");
        }
    }

    /** @return array{0:string,1:list<mixed>} */
    private function pkWhere(array $pk): array
    {
        $parts = [];
        $bind  = [];
        foreach ($pk as $col => $val) {
            if (! preg_match('/^[a-z_][a-z0-9_]*$/', (string) $col)) {
                throw new \RuntimeException("bad pk column {$col}");
            }
            $parts[] = "\"{$col}\" = ?";
            $bind[]  = $val;
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
    public function installCapture(): array
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION cga_demo_capture() RETURNS trigger
LANGUAGE plpgsql AS $fn$
DECLARE
    sid  text := current_setting('cga.demo_session', true);
    rec  jsonb;
    keys jsonb;
BEGIN
    IF sid IS NULL OR sid = '' THEN
        RETURN NULL;
    END IF;
    rec := CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END;
    SELECT jsonb_object_agg(k, rec -> k) INTO keys FROM unnest(TG_ARGV) AS k;
    INSERT INTO demo_session_writes (demo_session_id, table_name, pk, op, before, created_at)
    VALUES (sid::uuid, TG_TABLE_NAME, keys, TG_OP,
            CASE WHEN TG_OP = 'INSERT' THEN NULL ELSE to_jsonb(OLD) END, now());
    RETURN NULL;
END
$fn$;
SQL);

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
                . "WHEN (current_setting('cga.demo_session', true) IS NOT NULL AND current_setting('cga.demo_session', true) <> '') "
                . "EXECUTE FUNCTION cga_demo_capture({$t->pk_args})"
            );
            $installed++;
        }

        return ['installed' => $installed, 'skipped_no_pk' => $noPk, 'excluded' => $excluded];
    }
}
