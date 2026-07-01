<?php

namespace App\Services\Federation;

use App\Models\FederationPeer;
use App\Models\FoundationSyncCursor;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * JOINER side of the paginated foundation drain (seed redesign) — the geodata-foundation
 * counterpart of {@see ColdSyncService} (the audit tail). Pulls each foundation table from a
 * host one SIGNED keyset page at a time and UPSERTs it, advancing a resumable
 * {@see FoundationSyncCursor} INSIDE the same per-page transaction. It replaces the opaque,
 * non-resumable `pg_restore` seed: progress is visible (the committed rows_applied/total_rows
 * drive a per-table bar), a crash resumes from next_from_key, and a finished table short-circuits.
 *
 * NON-DESTRUCTIVE by construction: it never clears a table — it `INSERT … ON CONFLICT (pk) DO
 * NOTHING`. So the identity-safe cosmic detach/re-point dance and the append-only ledger
 * TRUNCATE/DELETE hazard the tarball path had to navigate simply never arise here (those tables
 * are never cleared), and a resume that re-applies a page is a no-op.
 *
 * FK ordering: tables drain parent → child (FoundationServeService::tables()), so intra-foundation
 * forward FKs (constitutional_settings → jurisdictions) are satisfied as we go. The only FKs a
 * keyset (random-uuid-order) load would violate are SELF-REFS (cosmic_addresses.parent_id,
 * jurisdictions.parent_id) and FKs OUT of the foundation set (constitutional_settings → laws,
 * which laws replay later or are null in the base seed). Those — and only those — are dropped for
 * the duration of the table's cold drain and re-added (NOT VALID then VALIDATE) on completion,
 * recorded in the cursor so an interrupted drain restores them on resume.
 *
 * Trust mirrors the cold sync: every page is verified against the host's PINNED key before a row
 * is touched; a tampered or mis-signed page aborts the cursor and applies nothing.
 */
class FoundationDrainService
{
    public function __construct(private readonly MultiplexClient $multiplex) {}

    /**
     * Drain the whole foundation from $host, table by table in FK-safe order. Stops the chain on
     * the first aborted table (fail-closed — a later table may FK-depend on the aborted one).
     *
     * @return array<string,array{status:string,rows_applied:int,total_rows:?int}>
     */
    public function drain(FederationPeer $host, int $maxPagesPerTable = 0): array
    {
        $summary = [];
        foreach (FoundationServeService::tables() as $table) {
            $cursor = $this->drainTable($host, $table, $maxPagesPerTable);
            $summary[$table] = [
                'status' => $cursor->status,
                'rows_applied' => (int) $cursor->rows_applied,
                'total_rows' => $cursor->total_rows !== null ? (int) $cursor->total_rows : null,
            ];
            if ($cursor->status === FoundationSyncCursor::STATUS_ABORTED) {
                break;
            }
        }

        return $summary;
    }

    /** Drain (or resume) one foundation table to completion (or $maxPages, 0 = unbounded). */
    public function drainTable(FederationPeer $host, string $table, int $maxPages = 0): FoundationSyncCursor
    {
        $cursor = $this->openCursor($host, $table);

        if ($cursor->status === FoundationSyncCursor::STATUS_COMPLETE) {
            // Idempotent finish — rebuild any indexes / re-add any FKs an interrupted finalize left undone.
            $this->finalizeColdLoad($cursor, $table);

            return $cursor;
        }

        $pages = 0;
        while ($cursor->status === FoundationSyncCursor::STATUS_OPEN) {
            if (! $this->pullOnePage($host, $cursor, $table)) {
                break; // complete or aborted
            }
            $pages++;
            if ($maxPages > 0 && $pages >= $maxPages) {
                break;
            }
        }

        if ($cursor->status === FoundationSyncCursor::STATUS_COMPLETE) {
            $this->finalizeColdLoad($cursor, $table);
        }

        return $cursor->refresh();
    }

    /** Fetch + verify + apply one page; advance the cursor. Returns false when the table is done/aborted. */
    public function pullOnePage(FederationPeer $host, FoundationSyncCursor $cursor, string $table): bool
    {
        $response = $this->fetchPageWithRetry($host, $table, $cursor->next_from_key, (int) $cursor->page_size);
        $page = (array) $response->json();

        if (! $this->verifyPage($host, $page, $table)) {
            $this->abort($cursor, 'page_invalid');

            return false;
        }

        $rows = (array) ($page['rows'] ?? []);
        $total = array_key_exists('total_rows', $page) ? $page['total_rows'] : null;
        $complete = (bool) ($page['complete'] ?? false);

        if ($rows === []) {
            $this->markComplete($cursor, $total); // caught up (donor said complete) or an empty table

            return false;
        }

        // Prepare the cold load once, right before the first row lands (skipped entirely for an empty
        // table): drop the unsafe FKs AND the heavy secondary indexes so the bulk UPSERT doesn't pay
        // per-row index maintenance (the 447 MB geom GiST is the dominant cost) — rebuilt on completion.
        $this->prepareColdLoad($cursor, $table);

        $nextKey = $page['next_from_key'] ?? null;
        $applied = count($rows);

        // Apply the page AND advance the cursor atomically — a crash before commit re-fetches the
        // SAME page on resume (no gap, no dup); a crash after commit never re-fetches it.
        DB::transaction(function () use ($table, $page, $cursor, $nextKey, $total, $applied) {
            $this->applyPage($table, $page);
            $cursor->forceFill([
                'from_key' => $cursor->next_from_key,
                'next_from_key' => $nextKey,
                'pages_applied' => (int) $cursor->pages_applied + 1,
                'rows_applied' => (int) $cursor->rows_applied + $applied,
                'total_rows' => $total !== null ? (int) $total : $cursor->total_rows,
            ])->save();
        });

        if ($complete) {
            $this->markComplete($cursor, $total);

            return false;
        }

        return true;
    }

    /**
     * UPSERT one decoded page into $table. Geometry columns arrive as EWKB hex (decoded with
     * ST_GeomFromEWKB — SRID preserved), raster columns as bytea hex (decoded + cast to raster),
     * everything else binds as text (Postgres coerces). ON CONFLICT (pk) DO NOTHING makes a
     * re-applied page a no-op. Returns the number of rows in the page (attempted).
     *
     * @param  array<string,mixed>  $page
     */
    public function applyPage(string $table, array $page): int
    {
        $columns = array_values((array) ($page['columns'] ?? []));
        $rows = (array) ($page['rows'] ?? []);
        if ($columns === [] || $rows === []) {
            return 0;
        }

        $geometry = array_flip((array) ($page['geometry_columns'] ?? []));
        $raster = array_flip((array) ($page['raster_columns'] ?? []));
        // The UPSERT conflict target — usually the PK, but cosmic_addresses dedupes on its slug
        // (slug-identical, uuid-divergent across nodes). Falls back to the keyset key.
        $conflictCols = (array) ($page['conflict_columns'] ?? $page['key_columns'] ?? FoundationServeService::keyColumns($table));

        $colList = implode(', ', array_map(static fn ($c) => '"'.$c.'"', $columns));

        $groups = [];
        $bind = [];
        foreach ($rows as $row) {
            $exprs = [];
            foreach ($columns as $c) {
                if (isset($geometry[$c])) {
                    $exprs[] = "ST_GeomFromEWKB(decode(?, 'hex'))";
                } elseif (isset($raster[$c])) {
                    // There is NO bytea→raster cast; the inverse of the donor's rast::bytea (raster WKB)
                    // is ST_RastFromHexWKB, which takes the hex text directly.
                    $exprs[] = 'ST_RastFromHexWKB(?)';
                } else {
                    $exprs[] = '?';
                }
                $bind[] = $row[$c] ?? null;
            }
            $groups[] = '('.implode(', ', $exprs).')';
        }

        $conflict = implode(', ', array_map(static fn ($c) => '"'.$c.'"', $conflictCols));
        $sql = "INSERT INTO \"{$table}\" ({$colList}) VALUES ".implode(', ', $groups)." ON CONFLICT ({$conflict}) DO NOTHING";

        DB::insert($sql, $bind);

        return count($rows);
    }

    // ── verification ───────────────────────────────────────────────────────────

    /** A page is trusted only if it names this table + host and verifies against the host's PINNED key. */
    private function verifyPage(FederationPeer $host, array $page, string $table): bool
    {
        if (($page['table'] ?? null) !== $table) {
            return false;
        }
        if ((string) ($page['server_id'] ?? '') !== (string) $host->server_id) {
            return false;
        }
        if ((string) ($page['schema_version'] ?? '') !== (string) config('cga.schema_version', '1')) {
            return false;
        }

        $sig = (string) ($page['signature'] ?? '');

        return $sig !== '' && InstanceIdentityService::verify(
            (string) $host->public_key,
            FoundationServeService::pageCanonical($page),
            $sig,
        );
    }

    // ── FK management ────────────────────────────────────────────────────────────

    /**
     * The outbound FKs a keyset load of $table would violate: self-refs and FKs pointing OUTSIDE
     * the foundation set. Intra-foundation forward FKs are satisfied by drain order and kept.
     *
     * @return list<array{conname:string,def:string}>
     */
    public function unsafeFks(string $table): array
    {
        $rows = DB::select(
            "SELECT conname, confrelid::regclass::text AS reftbl, pg_get_constraintdef(oid) AS def
             FROM pg_constraint
             WHERE contype = 'f' AND conrelid = ?::regclass",
            [$table]
        );

        $foundation = FoundationServeService::tables();
        $out = [];
        foreach ($rows as $r) {
            $ref = preg_replace('/^[a-z_]+\./', '', (string) $r->reftbl); // strip any schema qualifier
            if ($ref === $table || ! in_array($ref, $foundation, true)) {
                $out[] = ['conname' => (string) $r->conname, 'def' => (string) $r->def];
            }
        }

        return $out;
    }

    /**
     * The heavy SECONDARY indexes safe to drop for a bulk load: every non-unique, non-primary,
     * valid index (the geom + centroid GiST and the secondary btrees). The PK and any UNIQUE index
     * are kept — the UPSERT's ON CONFLICT target needs them. Each is recreated verbatim afterward
     * from its own pg_get_indexdef DDL, so this can never drift from the schema.
     *
     * @return list<array{name:string,def:string}>
     */
    public function droppableIndexes(string $table): array
    {
        $rows = DB::select(
            "SELECT i.relname AS name, pg_get_indexdef(ix.indexrelid) AS def
             FROM pg_index ix
             JOIN pg_class i ON i.oid = ix.indexrelid
             JOIN pg_class t ON t.oid = ix.indrelid
             WHERE t.relname = ? AND ix.indisprimary = false AND ix.indisunique = false AND ix.indisvalid = true",
            [$table]
        );

        return array_map(static fn ($r) => ['name' => (string) $r->name, 'def' => (string) $r->def], $rows);
    }

    /**
     * Prepare a fresh COLD drain once (on the first applied page): drop the unsafe FKs AND the heavy
     * secondary indexes, recording both on the cursor so an interrupted drain can finish the rebuild on
     * resume. A populated/resuming table is left untouched. Honour the operability toggle
     * `federation_foundation_drop_indexes` (an operator can disable the index optimisation).
     */
    private function prepareColdLoad(FoundationSyncCursor $cursor, string $table): void
    {
        $detail = (array) ($cursor->detail ?? []);
        if ((int) $cursor->pages_applied > 0
            || array_key_exists('dropped_constraints', $detail)
            || array_key_exists('dropped_indexes', $detail)) {
            return; // resuming — already prepared
        }

        $droppedFks = [];
        foreach ($this->unsafeFks($table) as $fk) {
            DB::statement("ALTER TABLE \"{$table}\" DROP CONSTRAINT IF EXISTS \"{$fk['conname']}\"");
            $droppedFks[$fk['conname']] = $fk['def'];
        }

        $droppedIdx = [];
        if ((bool) config('cga.federation_foundation_drop_indexes', true)) {
            foreach ($this->droppableIndexes($table) as $idx) {
                DB::statement("DROP INDEX IF EXISTS \"{$idx['name']}\"");
                $droppedIdx[$idx['name']] = $idx['def'];
            }
        }

        $detail['dropped_constraints'] = $droppedFks;
        $detail['dropped_indexes'] = $droppedIdx;
        $cursor->forceFill(['detail' => $detail])->save();
    }

    /**
     * Finalize a completed table: rebuild the dropped indexes, then re-add the dropped FKs (NOT VALID,
     * then VALIDATE). Idempotent and crash-safe — each step is skipped when the object already exists,
     * so an interrupted finalize completes on the next call. A rebuild/validate that fails is logged,
     * not fatal: a slow-but-correct mirror beats a refused join.
     */
    private function finalizeColdLoad(FoundationSyncCursor $cursor, string $table): void
    {
        $detail = (array) ($cursor->detail ?? []);
        if (! array_key_exists('dropped_indexes', $detail) && ! array_key_exists('dropped_constraints', $detail)) {
            return;
        }

        // Rebuild indexes first (so the re-validated FKs and downstream queries find them).
        foreach ((array) ($detail['dropped_indexes'] ?? []) as $name => $def) {
            $exists = DB::selectOne("SELECT 1 AS ok FROM pg_class WHERE relname = ? AND relkind = 'i'", [$name]);
            if ($exists !== null) {
                continue;
            }
            try {
                DB::statement((string) $def);
            } catch (\Throwable $e) {
                Log::warning("foundation drain: index {$name} on {$table} could not be rebuilt", ['error' => $e->getMessage()]);
            }
        }

        foreach ((array) ($detail['dropped_constraints'] ?? []) as $conname => $def) {
            $exists = DB::selectOne(
                'SELECT 1 AS ok FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass',
                [$conname, $table]
            );
            if ($exists !== null) {
                continue;
            }
            try {
                DB::statement("ALTER TABLE \"{$table}\" ADD CONSTRAINT \"{$conname}\" {$def} NOT VALID");
                DB::statement("ALTER TABLE \"{$table}\" VALIDATE CONSTRAINT \"{$conname}\"");
            } catch (\Throwable $e) {
                // Leave it NOT VALID (still enforces new writes) rather than fail the whole join.
                Log::warning("foundation drain: FK {$conname} on {$table} could not be fully restored", ['error' => $e->getMessage()]);
            }
        }

        unset($detail['dropped_indexes'], $detail['dropped_constraints']);
        $cursor->forceFill(['detail' => $detail])->save();
    }

    // ── transport / cursor ───────────────────────────────────────────────────────

    /**
     * Fetch one foundation page (idempotent GET) over the multiplex ladder, retrying transient WAN
     * failures with exponential backoff — mirrors {@see ColdSyncService::fetchPageWithRetry} so a
     * brief blip never aborts a multi-hour foundation load. A 4xx is definitive and never retried.
     *
     * @param  list<scalar>|null  $fromKey
     */
    private function fetchPageWithRetry(FederationPeer $host, string $table, ?array $fromKey, int $pageSize): Response
    {
        $attempts = max(1, (int) config('cga.federation_cold_retry_attempts', 3));
        $backoffMs = max(0, (int) config('cga.federation_cold_retry_backoff_ms', 500));

        $query = ['table' => $table, 'page_size' => $pageSize];
        if ($fromKey !== null && $fromKey !== []) {
            $query['from_key'] = json_encode(array_values($fromKey));
        }

        $last = 'no attempt';
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->multiplex->reach((string) $host->server_id, 'GET', '/api/federation/foundation/page', $query);

                if ($response->successful()) {
                    return $response;
                }
                if ($response->status() < 500) {
                    throw new RuntimeException("Foundation page fetch refused (HTTP {$response->status()}).");
                }
                $last = "HTTP {$response->status()}";
            } catch (NoSurvivingTransport $e) {
                if ($e->undialable) {
                    throw new RuntimeException("Foundation page fetch: {$e->getMessage()}");
                }
                $last = 'no surviving transport: '.$e->getMessage();
            }

            if ($attempt < $attempts && $backoffMs > 0) {
                usleep($backoffMs * (2 ** ($attempt - 1)) * 1000);
            }
        }

        throw new RuntimeException("Foundation page fetch failed after {$attempts} attempt(s) (last: {$last}).");
    }

    /** Resume the open cursor for (host, table), or open one at the table head. */
    private function openCursor(FederationPeer $host, string $table): FoundationSyncCursor
    {
        $cursor = FoundationSyncCursor::query()
            ->where('peer_id', $host->id)
            ->where('table_name', $table)
            ->whereIn('status', [FoundationSyncCursor::STATUS_OPEN, FoundationSyncCursor::STATUS_COMPLETE])
            ->latest('updated_at')
            ->first();

        if ($cursor !== null) {
            return $cursor;
        }

        return FoundationSyncCursor::create([
            'peer_id' => $host->id,
            'table_name' => $table,
            'from_key' => null,
            'next_from_key' => null,
            // Ask large; the donor caps to each table's optimal page size + byte-trims.
            'page_size' => (int) config('cga.federation_foundation_page_rows', 1000),
            'status' => FoundationSyncCursor::STATUS_OPEN,
        ]);
    }

    private function markComplete(FoundationSyncCursor $cursor, mixed $total): void
    {
        $cursor->forceFill([
            'status' => FoundationSyncCursor::STATUS_COMPLETE,
            'total_rows' => $total !== null ? (int) $total : $cursor->total_rows,
        ])->save();
    }

    private function abort(FoundationSyncCursor $cursor, string $reason): void
    {
        $cursor->forceFill(['status' => FoundationSyncCursor::STATUS_ABORTED, 'abort_reason' => $reason])->save();
    }
}
