<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const OLD = 'public_records_actor_user_id_index';
    private const REPLACEMENT = 'profile_publication_seek_idx';
    private const LOCK = 0x505542494458; // PUBIDX; never the audit append lock

    public function up(): void
    {
        $this->change(false);
    }

    public function down(): void
    {
        $this->change(true);
    }

    private function change(bool $restore): void
    {
        $db = DB::connection();
        if ($db->getDriverName() !== 'pgsql') {
            return;
        }
        // Concurrent DDL must not run in a transaction. Use one PDO directly:
        // connection loss must fail, never reconnect and lose the session lock.
        $pdo = $db->getPdo();
        if ($db->transactionLevel() !== 0 || $pdo->inTransaction()) {
            throw new RuntimeException('Publication index maintenance requires no open transaction.');
        }
        $saved = $this->one($pdo, "SELECT current_setting('lock_timeout') AS lock_timeout,
            current_setting('statement_timeout') AS statement_timeout,
            (SELECT setting::bigint FROM pg_settings WHERE name='lock_timeout') AS lock_ms,
            (SELECT setting::bigint FROM pg_settings WHERE name='statement_timeout') AS statement_ms");
        $locked = false;
        try {
            // These are interruption budgets, not memory/worker sizing. Honor a
            // finite caller execution budget; otherwise use bounded defaults.
            // A rebuild scans the table; a drop does not. Its caller can provide
            // a longer finite budget when the host/table needs it for recovery.
            $this->setting($pdo, 'lock_timeout', min((int) $saved->lock_ms ?: 5000, 5000).'ms');
            $limit = $restore ? 900000 : 60000;
            $this->setting($pdo, 'statement_timeout', ((int) $saved->statement_ms ?: $limit).'ms');
            $locked = (bool) $this->one($pdo, 'SELECT pg_try_advisory_lock(?) AS owned', [self::LOCK])->owned;
            if (! $locked) {
                throw new RuntimeException('Another publication index migration owns this database. Retry after it finishes.');
            }

            $table = $this->one($pdo, "SELECT c.oid, c.relkind, c.relpersistence FROM pg_class c
                WHERE c.oid=to_regclass('public.public_records')");
            if (! $table || $table->relkind !== 'r' || $table->relpersistence !== 'p') {
                throw new RuntimeException('Expected the ordinary persistent public.public_records table.');
            }
            if (! $restore) {
                $replacement = $this->index($pdo, self::REPLACEMENT, $table->oid,
                    ['actor_user_id', 'seq'], '0 3');
                if (! $replacement || ! $replacement->indisvalid || ! $replacement->indisready || ! $replacement->indislive) {
                    throw new RuntimeException('The replacement publication actor index must exist and be valid, ready and live.');
                }
            }
            if ($this->one($pdo, 'SELECT pid FROM pg_stat_progress_create_index WHERE relid=? LIMIT 1', [$table->oid])) {
                throw new RuntimeException('A publication index build is in progress. Retry after it finishes.');
            }
            $old = $this->index($pdo, self::OLD, $table->oid, ['actor_user_id']);
            if ($old && $this->one($pdo, "SELECT 1 FROM pg_depend
                WHERE (refclassid='pg_class'::regclass AND refobjid=?)
                   OR (classid='pg_class'::regclass AND objid=? AND deptype IN ('e','i')) LIMIT 1", [$old->oid, $old->oid])) {
                throw new RuntimeException('The old publication actor index has dependencies; refusing to remove it.');
            }
            if ($restore && $old?->indisvalid && $old->indisready && $old->indislive) {
                return;
            }
            if ($old) {
                // RESTRICT is deliberate. A canceled concurrent drop/build can
                // leave the expected index invalid: the same operation is safe
                // to retry, with the healthy replacement checked again above.
                $pdo->exec('DROP INDEX CONCURRENTLY public.'.self::OLD.' RESTRICT');
            }
            if ($restore) {
                $pdo->exec('CREATE INDEX CONCURRENTLY '.self::OLD.' ON public.public_records (actor_user_id)');
                $rebuilt = $this->index($pdo, self::OLD, $table->oid, ['actor_user_id']);
                if (! $rebuilt?->indisvalid || ! $rebuilt->indisready || ! $rebuilt->indislive) {
                    throw new RuntimeException('The restored publication actor index is not ready. Retry the restoration.');
                }
            }
        } finally {
            // A dead connection releases its advisory lock server-side. Do not
            // hide the original DDL failure with an attempted cleanup reconnect.
            try {
                $this->setting($pdo, 'lock_timeout', $saved->lock_timeout);
                $this->setting($pdo, 'statement_timeout', $saved->statement_timeout);
            } catch (PDOException) {
            } finally {
                if ($locked) {
                    try { $this->one($pdo, 'SELECT pg_advisory_unlock(?)', [self::LOCK]); }
                    catch (PDOException) {}
                }
            }
        }
    }

    /** Reject same-name surprises; only validity flags may differ after interruption. */
    private function index(PDO $pdo, string $name, int $table, array $keys, string $options = '0'): ?object
    {
        $index = $this->one($pdo, "SELECT c.oid, c.relkind, i.*, am.amname,
                (SELECT json_agg(pg_get_indexdef(c.oid, n, false) ORDER BY n)
                 FROM generate_series(1, i.indnatts) n)::text AS keys,
                NOT EXISTS (SELECT 1 FROM unnest(i.indclass) k
                    JOIN pg_opclass op ON op.oid=k WHERE NOT op.opcdefault OR op.opcmethod<>c.relam) AS default_ops,
                NOT EXISTS (SELECT 1 FROM unnest(i.indkey, i.indcollation) k(attnum, collation_oid)
                    JOIN pg_attribute a ON a.attrelid=i.indrelid AND a.attnum=k.attnum
                    WHERE k.collation_oid<>a.attcollation) AS default_collations,
                EXISTS (SELECT 1 FROM pg_constraint WHERE conindid=c.oid) AS has_constraint
            FROM pg_class c LEFT JOIN pg_index i ON i.indexrelid=c.oid
            LEFT JOIN pg_am am ON am.oid=c.relam WHERE c.oid=to_regclass(?)", ['public.'.$name]);
        if ($index && ($index->relkind !== 'i' || $index->amname !== 'btree'
            || (int) $index->indrelid !== $table || (int) $index->indnkeyatts !== count($keys)
            || (int) $index->indnatts !== count($keys) || $index->indisunique || $index->indisprimary
            || $index->indisexclusion || $index->indisreplident || $index->indisclustered
            || $index->has_constraint || $index->indpred !== null || $index->indexprs !== null
            || ! $index->default_ops || ! $index->default_collations
            || (string) $index->indoption !== $options
            || json_decode($index->keys ?? '[]', true) !== $keys)) {
            throw new RuntimeException('Unexpected definition or protected use for publication index '.$name.'. No change made to that index.');
        }

        return $index;
    }

    private function one(PDO $pdo, string $sql, array $bindings = []): ?object
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($bindings);
        return $statement->fetch(PDO::FETCH_OBJ) ?: null;
    }

    private function setting(PDO $pdo, string $name, string $value): void
    {
        $this->one($pdo, 'SELECT set_config(?, ?, false)', [$name, $value]);
    }
};
