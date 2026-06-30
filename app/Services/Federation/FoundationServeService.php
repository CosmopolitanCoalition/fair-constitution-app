<?php

namespace App\Services\Federation;

use App\Models\InstanceSettings;
use App\Services\AuditService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * DONOR side of the paginated foundation drain (seed redesign).
 *
 * The old seed transport exported the foundation to a tarball and the joiner ran ONE opaque
 * `pg_restore --data-only` — invisible until commit, non-resumable mid-table, slow. This serves
 * the SAME foundation a row at a time: each call returns a bounded, signed KEYSET page of one
 * table that the joiner's {@see FoundationDrainService} UPSERTs and advances a cursor over —
 * structurally the audit-tail cold sync ({@see ColdSyncService}) applied to the geodata
 * foundation, with the ETL's byte/row-aware batching.
 *
 * Drift-proof projection: columns and their types are read from the LIVE catalog per page, so a
 * geometry column (jurisdictions has TWO — geom + centroid) or a raster column (worldpop_rasters.rast)
 * is detected automatically and encoded losslessly — geometry as EWKB hex (SRID-preserving), raster as
 * its bytea hex. Every other column rides as the driver returns it. The joiner decodes by the same
 * column/type lists this page carries, so encode and decode cannot drift.
 *
 * Trust mirrors the audit tail: the page is SIGNED by this instance over its canonical form, and the
 * joiner verifies it against our PINNED key (not the relayer's). federation.signed (pinned) gates the
 * request. The foundation carries NO identity (instance_settings is never a foundation table) and NO
 * institutional rows (those replay over the audit tail) — only geodata + base constitutional_settings.
 */
class FoundationServeService
{
    /**
     * The key column(s) each foundation table is paged by — its PRIMARY KEY. Almost all are a single
     * uuid; geoboundary_metadata is the lone composite. Stored as a JSON array on both the page and the
     * cursor so one shape carries both. These are PKs — they never change — so the one hardcoded part of
     * the projection is stable; everything else is read from the catalog.
     *
     * @var array<string,list<string>>
     */
    private const KEY_COLUMNS = [
        'cosmic_addresses' => ['id'],
        'jurisdictions' => ['id'],
        'worldpop_rasters' => ['id'],
        'geoboundary_metadata' => ['iso_code', 'adm_level'],
        'constitutional_settings' => ['id'],
    ];

    /**
     * The UPSERT conflict target — usually the PK (= the keyset key), but cosmic_addresses dedupes on
     * SLUG, not id. The Multiverse→Earth cosmic tree is seeded IDENTICALLY (same slugs) on every node
     * by migration, with node-LOCAL uuids. A uuid-keyed UPSERT would try to insert the donor's
     * slug-identical rows under new uuids and trip the slug UNIQUE constraint; conflicting on slug
     * instead keeps THIS node's cosmic rows (so instance_settings.cosmic_address_id stays valid — no
     * identity dance) and adds only cosmic nodes whose slug we lack. Nothing references cosmic uuids
     * cross-node (jurisdictions have no FK to it), so divergent uuids are harmless.
     *
     * @var array<string,list<string>>
     */
    private const CONFLICT_COLUMNS = [
        'cosmic_addresses' => ['slug'],
    ];

    /**
     * Per-table page row cap. Rasters are heavy (a tile can be tens of KB–MB) so they page few rows;
     * jurisdiction geoms range from a few bytes (leaf ADM2) to multi-MB (ADM0 coastlines), so the
     * byte-aware trim ({@see FoundationServeService::trimToBytes}) is the real bound there. Settings and
     * cosmic rows are small.
     *
     * @var array<string,int>
     */
    private const PAGE_ROWS = [
        'cosmic_addresses' => 1000,
        'jurisdictions' => 250,
        'worldpop_rasters' => 8,
        'geoboundary_metadata' => 1000,
        'constitutional_settings' => 1000,
    ];

    public function __construct(private readonly InstanceIdentityService $identity) {}

    /** The ordered, FK-safe foundation table allowlist (parent → child). */
    public static function tables(): array
    {
        return GeodataSeedTransportService::FOUNDATION_TABLES;
    }

    public static function keyColumns(string $table): array
    {
        return self::KEY_COLUMNS[$table] ?? ['id'];
    }

    /**
     * Build one signed keyset page of $table for rows whose key is strictly greater than $fromKey
     * (null = the table head). The page carries the column/type lists the joiner needs to decode and
     * UPSERT, the next keyset watermark, the donor's row total (the bar denominator), and a `complete`
     * flag the joiner trusts (a byte-trimmed page is short but NOT complete — only a genuinely caught-up
     * page is).
     *
     * @param  list<scalar>|null  $fromKey  the last applied row's key values, in keyColumns() order
     * @return array<string,mixed>
     */
    public function buildFoundationPage(string $table, ?array $fromKey, int $pageSize): array
    {
        if (! in_array($table, self::tables(), true)) {
            throw new InvalidArgumentException("'{$table}' is not a foundation table.");
        }

        $keyCols = self::KEY_COLUMNS[$table];
        $cap = max(1, (int) (self::PAGE_ROWS[$table] ?? 250));
        $pageSize = $pageSize > 0 ? min($pageSize, $cap) : $cap;

        [$columns, $geometryCols, $rasterCols] = $this->describe($table);

        // Projection — geometry → EWKB hex (lossless + SRID), raster → bytea hex, everything else raw.
        $select = [];
        foreach ($columns as $c) {
            $q = '"'.$c.'"';
            if (in_array($c, $geometryCols, true)) {
                $select[] = "encode(ST_AsEWKB({$q}), 'hex') AS {$q}";
            } elseif (in_array($c, $rasterCols, true)) {
                $select[] = "encode({$q}::bytea, 'hex') AS {$q}";
            } else {
                $select[] = $q;
            }
        }

        $bind = [];
        $where = '';
        if ($fromKey !== null && $fromKey !== []) {
            // Row-value comparator keyset — (k1,k2,…) > (?,?,…). Stable under donor inserts mid-drain
            // (an offset window would shift); the cursor IS the resume token.
            $lhs = '('.implode(', ', array_map(static fn ($c) => '"'.$c.'"', $keyCols)).')';
            $rhs = '('.implode(', ', array_fill(0, count($keyCols), '?')).')';
            $where = "WHERE {$lhs} > {$rhs}";
            $bind = array_values($fromKey);
        }
        $order = implode(', ', array_map(static fn ($c) => '"'.$c.'" ASC', $keyCols));

        $sql = 'SELECT '.implode(', ', $select)." FROM \"{$table}\" {$where} ORDER BY {$order} LIMIT ".($pageSize);
        $fetched = array_map(static fn ($r) => (array) $r, DB::select($sql, $bind));

        // Byte-aware trim: keep at least one row, then stop before the JSON page would exceed the cap so
        // a handful of giant geoms can never blow the federation HTTP body limit.
        $rows = $this->trimToBytes($fetched, $this->pageMaxBytes());

        // `complete` ONLY when the table is genuinely exhausted: fewer rows than asked AND nothing was
        // byte-trimmed. A byte-trimmed page is short but has more after next_from_key — never "done".
        $complete = count($fetched) < $pageSize && count($rows) === count($fetched);

        $nextFromKey = null;
        if ($rows !== []) {
            $last = $rows[array_key_last($rows)];
            $nextFromKey = array_map(static fn ($c) => $last[$c], $keyCols);
        }

        $page = [
            'server_id' => $this->identity->serverId(),
            'schema_version' => (string) config('cga.schema_version', '1'),
            // G-VER — the authenticated version claim, symmetric with the audit tail.
            'constitutional_version' => InstanceSettings::current()->constitutionalVersion(),
            'table' => $table,
            'key_columns' => $keyCols,
            'conflict_columns' => self::CONFLICT_COLUMNS[$table] ?? $keyCols,
            'columns' => $columns,
            'geometry_columns' => array_values($geometryCols),
            'raster_columns' => array_values($rasterCols),
            'rows' => array_map(fn (array $r) => $this->orderRow($r, $columns), $rows),
            'from_key' => $fromKey !== null ? array_values($fromKey) : null,
            'next_from_key' => $nextFromKey,
            'total_rows' => $this->totalRows($table),
            'complete' => $complete,
        ];

        $page['signature'] = $this->identity->sign(self::pageCanonical($page));

        return $page;
    }

    /**
     * The canonical string both sides sign/verify (the page minus its own signature). Reuses the
     * audit-chain canonicalizer so cross-instance hashes agree byte-for-byte.
     *
     * @param  array<string,mixed>  $page
     */
    public static function pageCanonical(array $page): string
    {
        unset($page['signature']);

        return AuditService::canonicalJson($page);
    }

    // ── internals ────────────────────────────────────────────────────────────────

    /**
     * Read the table's ordered columns + which are geometry / raster, from the live catalog. PostGIS
     * geometry and raster both surface as udt_name 'geometry' / 'raster', so a new geometry column is
     * picked up automatically — the projection never has to be edited when the schema grows.
     *
     * @return array{0:list<string>,1:list<string>,2:list<string>}
     */
    private function describe(string $table): array
    {
        $cols = DB::select(
            'SELECT column_name, udt_name FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = ?
             ORDER BY ordinal_position',
            [$table]
        );

        $columns = $geometry = $raster = [];
        foreach ($cols as $c) {
            $columns[] = $c->column_name;
            if ($c->udt_name === 'geometry') {
                $geometry[] = $c->column_name;
            } elseif ($c->udt_name === 'raster') {
                $raster[] = $c->column_name;
            }
        }

        if ($columns === []) {
            throw new InvalidArgumentException("Foundation table '{$table}' has no columns (not migrated?).");
        }

        return [$columns, $geometry, $raster];
    }

    /**
     * Drop rows from the tail until the JSON-encoded page fits the byte cap, always keeping ≥1 row so a
     * single oversized row still makes forward progress (its key becomes next_from_key).
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function trimToBytes(array $rows, int $cap): array
    {
        $out = [];
        $acc = 0;
        foreach ($rows as $r) {
            $sz = strlen((string) json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if ($out !== [] && $acc + $sz > $cap) {
                break;
            }
            $out[] = $r;
            $acc += $sz;
        }

        return $out;
    }

    /**
     * Project a row to exactly the page's column order (so the joiner's positional INSERT lines up) —
     * defensive against any driver column reordering.
     *
     * @param  array<string,mixed>  $row
     * @param  list<string>  $columns
     * @return array<string,mixed>
     */
    private function orderRow(array $row, array $columns): array
    {
        $out = [];
        foreach ($columns as $c) {
            $out[$c] = $row[$c] ?? null;
        }

        return $out;
    }

    private function totalRows(string $table): int
    {
        try {
            return (int) Cache::remember("foundation:count:{$table}", 300, static fn () => (int) DB::table($table)->count());
        } catch (\Throwable) {
            return 0; // a count hiccup must never fail a page; the bar just lacks a denominator briefly
        }
    }

    private function pageMaxBytes(): int
    {
        return max(64 * 1024, (int) config('cga.federation_foundation_page_max_bytes', 16 * 1024 * 1024));
    }
}
