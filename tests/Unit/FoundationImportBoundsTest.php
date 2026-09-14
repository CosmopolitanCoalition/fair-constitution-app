<?php

namespace Tests\Unit;

use App\Services\Federation\FoundationDrainService;
use App\Services\Federation\FoundationServeService;
use App\Models\FoundationSyncCursor;
use App\Services\Mirror\MirrorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * M6 — bound foundation import finalization and progress totals.
 *
 * DB-free (sqlite :memory:, array cache — see phpunit.xml). Pins the boundedness contract:
 *
 *   • The authority stamp rides each drained page: one id-scoped UPDATE per page inside the page
 *     transaction — never a whole-table UPDATE (ETL paradigm, "never one planet-wide statement").
 *   • A mirror claims NO authority: the stamp writes the HOST server_id, never NULL, never its own;
 *     a row already owned by a third server is left unchanged.
 *   • Completion is evidenced by the committed page ledger (all cursors COMPLETE) PLUS one
 *     index-assisted exists() probe — seeded_at is refused, with a reason, while a NULL remains.
 *   • The progress denominator reads pg_class.reltuples on pgsql (bounded catalog read), count() on
 *     sqlite — no exact count(*) over the world on pgsql.
 *   • The legacy tarball stamp chunks by a derived keyset size, committing per chunk with progress.
 *   • The geodata pull-progress counts are cached 8 s — a second poll never re-scans the world.
 *
 * No live world is involved: only the two throwaway tables these paths touch are built in memory.
 */
class FoundationImportBoundsTest extends TestCase
{
    private const HOST = '11111111-1111-1111-1111-111111111111';   // the pinned host's server_id
    private const OWN = '22222222-2222-2222-2222-222222222222';    // this mirror's own id — must NEVER be stamped
    private const THIRD = '33333333-3333-3333-3333-333333333333';  // a third server the donor mirrored from

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('jurisdictions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('authoritative_server_id')->nullable();
            $t->integer('adm_level')->default(0);
            $t->bigInteger('population')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('jurisdictions');
        Mockery::close();
        parent::tearDown();
    }

    /** Build a decoded page shell the way FoundationServeService serves it. */
    private function page(array $ids): array
    {
        return [
            'table' => 'jurisdictions',
            'columns' => ['id', 'authoritative_server_id'],
            'rows' => array_map(fn ($id) => ['id' => $id, 'authoritative_server_id' => null], $ids),
        ];
    }

    private function seedRow(string $id, ?string $auth): void
    {
        DB::table('jurisdictions')->insert([
            'id' => $id,
            'authoritative_server_id' => $auth,
            'adm_level' => 2,
            'population' => 100,
        ]);
    }

    // ── per-page stamp rides the drain, no whole-table UPDATE ─────────────────────────────────

    public function test_draining_pages_stamps_only_that_pages_rows_inside_the_transaction_never_whole_table(): void
    {
        $drain = app(FoundationDrainService::class);

        // Two pages of donor-owned rows (NULL sentinel) plus one third-server row already present.
        $pageA = ['00000000-0000-0000-0000-0000000000a1', '00000000-0000-0000-0000-0000000000a2'];
        $pageB = ['00000000-0000-0000-0000-0000000000b1', '00000000-0000-0000-0000-0000000000b2'];
        foreach (array_merge($pageA, $pageB) as $id) {
            $this->seedRow($id, null);
        }
        $this->seedRow('00000000-0000-0000-0000-0000000000c9', self::THIRD);

        DB::enableQueryLog();

        foreach ([$pageA, $pageB] as $ids) {
            // Faithful to pullOnePage: the stamp runs INSIDE the per-page transaction.
            DB::transaction(fn () => $drain->stampPageAuthority('jurisdictions', $this->page($ids), self::HOST));
        }

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $updates = array_values(array_filter($log, fn ($q) => str_starts_with(strtolower(trim($q['query'])), 'update "jurisdictions"')));

        // Exactly one UPDATE per page — the stamp rides the drain page by page.
        $this->assertCount(2, $updates, 'one bounded UPDATE per drained page');

        foreach ($updates as $u) {
            $sql = strtolower($u['query']);
            // Bounded to the page's ids AND fail-closed to still-unowned rows only.
            $this->assertStringContainsString('"id" in (', $sql, 'stamp is scoped to the page ids');
            $this->assertStringContainsString('"authoritative_server_id" is null', $sql, 'stamp only claims donor-owned (NULL) rows');
            // Never a whole-table UPDATE.
            $this->assertStringContainsString('where', $sql, 'no unbounded whole-table UPDATE');
        }

        // Donor-owned rows now name the HOST — never NULL, never this mirror's own id.
        foreach (array_merge($pageA, $pageB) as $id) {
            $this->assertSame(self::HOST, DB::table('jurisdictions')->where('id', $id)->value('authoritative_server_id'));
        }
        $this->assertSame(0, DB::table('jurisdictions')->whereNull('authoritative_server_id')->count());
        $this->assertSame(0, DB::table('jurisdictions')->where('authoritative_server_id', self::OWN)->count());

        // The third-server row is preserved unchanged.
        $this->assertSame(self::THIRD, DB::table('jurisdictions')->where('id', '00000000-0000-0000-0000-0000000000c9')->value('authoritative_server_id'));
    }

    public function test_stamp_is_idempotent_and_a_noop_on_already_owned_rows(): void
    {
        $drain = app(FoundationDrainService::class);
        $ids = ['00000000-0000-0000-0000-0000000000d1'];
        $this->seedRow($ids[0], null);

        $first = $drain->stampPageAuthority('jurisdictions', $this->page($ids), self::HOST);
        $second = $drain->stampPageAuthority('jurisdictions', $this->page($ids), self::HOST);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second, 're-applied page finds the row owned and stamps nothing');
        $this->assertSame(self::HOST, DB::table('jurisdictions')->where('id', $ids[0])->value('authoritative_server_id'));
    }

    public function test_stamp_is_a_noop_for_non_jurisdiction_tables(): void
    {
        $drain = app(FoundationDrainService::class);
        $this->assertSame(0, $drain->stampPageAuthority('constitutional_settings', $this->page(['x']), self::HOST));
    }

    // ── completion = ledger + probe, no whole-table COUNT ─────────────────────────────────────

    public function test_seed_ledger_complete_reads_the_committed_cursor_summary(): void
    {
        $drain = app(FoundationDrainService::class);

        $this->assertFalse($drain->seedLedgerComplete([]), 'an empty summary is not complete');
        $this->assertFalse($drain->seedLedgerComplete([
            'jurisdictions' => ['status' => FoundationSyncCursor::STATUS_OPEN, 'rows_applied' => 5, 'total_rows' => 9],
        ]));
        $this->assertTrue($drain->seedLedgerComplete([
            'cosmic_addresses' => ['status' => FoundationSyncCursor::STATUS_COMPLETE, 'rows_applied' => 3, 'total_rows' => 3],
            'jurisdictions' => ['status' => FoundationSyncCursor::STATUS_COMPLETE, 'rows_applied' => 4, 'total_rows' => 4],
        ]));
    }

    public function test_finalization_stamps_seeded_at_only_when_the_probe_is_clean_else_refuses_with_reason(): void
    {
        $mirror = app(MirrorService::class);
        $complete = [
            'jurisdictions' => ['status' => FoundationSyncCursor::STATUS_COMPLETE, 'rows_applied' => 1, 'total_rows' => 1],
        ];

        // A residual unowned jurisdiction blocks finalization with a visible reason.
        $this->seedRow('00000000-0000-0000-0000-0000000000e1', null);
        $blocker = $mirror->seedFinalizationBlocker($complete);
        $this->assertNotNull($blocker);
        $this->assertStringContainsString('unowned', $blocker);

        DB::enableQueryLog();
        $mirror->unownedJurisdictionReason();
        $probe = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertStringContainsStringIgnoringCase('exists', strtolower($probe[0]['query']), 'probe is an index-assisted exists(), not a count');
        $this->assertStringNotContainsString('count(', strtolower($probe[0]['query']));

        // Stamp it to the host → the probe is clean → finalization proceeds.
        DB::table('jurisdictions')->whereNull('authoritative_server_id')->update(['authoritative_server_id' => self::HOST]);
        $this->assertNull($mirror->seedFinalizationBlocker($complete));

        // An incomplete ledger blocks regardless of the probe.
        $incomplete = ['jurisdictions' => ['status' => FoundationSyncCursor::STATUS_ABORTED, 'rows_applied' => 0, 'total_rows' => 1]];
        $this->assertStringContainsString('did not complete', (string) $mirror->seedFinalizationBlocker($incomplete));
    }

    // ── donor denominator: reltuples on pgsql, count on sqlite ────────────────────────────────

    public function test_total_rows_uses_the_reltuples_catalog_path_on_pgsql(): void
    {
        $svc = app(FoundationServeService::class);   // build BEFORE the DB facade is mocked
        $realDb = DB::getFacadeRoot();               // keep the real manager to restore afterward

        try {
            $captured = null;
            $conn = Mockery::mock();
            $conn->shouldReceive('getDriverName')->andReturn('pgsql');
            $conn->shouldReceive('selectOne')->andReturnUsing(function ($sql, $binds) use (&$captured) {
                $captured = $sql;
                return (object) ['n' => 951234];
            });
            // A driver double standing in for the pgsql connection (the live DB is never touched).
            DB::shouldReceive('connection')->andReturn($conn);

            $n = $svc->estimatedRowCount('jurisdictions');

            $this->assertSame(951234, $n);
            $this->assertStringContainsString('reltuples', strtolower((string) $captured));
            $this->assertStringContainsString('pg_class', strtolower((string) $captured));
            $this->assertStringNotContainsString('count(', strtolower((string) $captured), 'no exact count(*) on pgsql');
        } finally {
            // swap() rebinds the container 'db' instance; restore the real manager so teardown's
            // Schema drop (and any later test) uses the real connection, not the driver double.
            DB::swap($realDb);
        }
    }

    public function test_reltuples_minus_one_on_a_never_analyzed_table_clamps_to_zero(): void
    {
        $svc = app(FoundationServeService::class);
        $realDb = DB::getFacadeRoot();

        try {
            $conn = Mockery::mock();
            $conn->shouldReceive('getDriverName')->andReturn('pgsql');
            // PostgreSQL 14+ reports reltuples = -1 for a table that has never been analyzed/vacuumed.
            $conn->shouldReceive('selectOne')->andReturn((object) ['n' => -1]);
            DB::shouldReceive('connection')->andReturn($conn);

            $n = $svc->estimatedRowCount('jurisdictions');

            $this->assertSame(0, $n, 'a -1 reltuples estimate clamps to 0 — never a negative denominator');
        } finally {
            DB::swap($realDb);
        }
    }

    public function test_total_rows_falls_back_to_count_on_sqlite(): void
    {
        $svc = app(FoundationServeService::class);
        $this->seedRow('00000000-0000-0000-0000-0000000000f1', self::HOST);
        $this->seedRow('00000000-0000-0000-0000-0000000000f2', self::HOST);

        DB::enableQueryLog();
        $n = $svc->estimatedRowCount('jurisdictions');
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(2, $n);
        $this->assertStringContainsString('count(', strtolower($log[0]['query']), 'sqlite has no catalog estimate → exact count');
    }

    // ── legacy tarball stamp: chunked keyset, bounded, host-derived ───────────────────────────

    public function test_legacy_tarball_stamp_chunks_by_the_derived_size_and_stamps_only_null_rows(): void
    {
        $mirror = app(MirrorService::class);

        // Five donor-owned rows + one third-server row; a chunk size of 2 forces multiple chunks.
        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $id = sprintf('00000000-0000-0000-0000-00000000%04d', $i);
            $ids[] = $id;
            $this->seedRow($id, null);
        }
        $this->seedRow('00000000-0000-0000-0000-000000009999', self::THIRD);

        DB::enableQueryLog();
        $stamped = $mirror->stampSeedAuthorityChunked(self::HOST, 2);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(5, $stamped);

        $updates = array_values(array_filter($log, fn ($q) => str_starts_with(strtolower(trim($q['query'])), 'update "jurisdictions"')));
        $this->assertGreaterThan(1, count($updates), 'more than one chunk (chunk size 2 over 6 rows)');
        foreach ($updates as $u) {
            $sql = strtolower($u['query']);
            $this->assertStringContainsString('"id" in (', $sql, 'each chunk UPDATE is id-scoped, never whole-table');
            $this->assertStringContainsString('"authoritative_server_id" is null', $sql);
        }
        // Roster reads are bounded keyset selects (id > cursor, limited) — never a single planet-wide scan.
        $selects = array_values(array_filter($log, fn ($q) => str_contains(strtolower($q['query']), 'select "id" from "jurisdictions"')));
        $this->assertNotEmpty($selects);
        foreach ($selects as $s) {
            $this->assertStringContainsString('limit', strtolower($s['query']), 'roster fetch is bounded by LIMIT');
        }
        // jurisdictions.id is a native pgsql uuid; no valid uuid sorts before all others, so the FIRST
        // roster read must carry no `id > ?` predicate (an '' bind would throw invalid uuid syntax on
        // pgsql). The keyset predicate appears only from the second chunk onward.
        $this->assertStringNotContainsString('"id" >', strtolower($selects[0]['query']), 'first roster read is unconditional — no before-all uuid sentinel');
        $this->assertStringContainsString('"id" >', strtolower($selects[1]['query']), 'later chunks resume from the committed cursor');

        foreach ($ids as $id) {
            $this->assertSame(self::HOST, DB::table('jurisdictions')->where('id', $id)->value('authoritative_server_id'));
        }
        $this->assertSame(self::THIRD, DB::table('jurisdictions')->where('id', '00000000-0000-0000-0000-000000009999')->value('authoritative_server_id'));
        $this->assertSame(0, DB::table('jurisdictions')->where('authoritative_server_id', self::OWN)->count());
    }

    public function test_legacy_tarball_stamp_derives_a_positive_default_chunk_size(): void
    {
        $mirror = app(MirrorService::class);
        $this->seedRow('00000000-0000-0000-0000-00000000aaaa', null);

        // No explicit chunk size → the host-derived default is used; it must complete the stamp.
        $stamped = $mirror->stampSeedAuthorityChunked(self::HOST);
        $this->assertSame(1, $stamped);
        $this->assertSame(self::HOST, DB::table('jurisdictions')->where('id', '00000000-0000-0000-0000-00000000aaaa')->value('authoritative_server_id'));
    }

    // ── geodata pull counts: served from cache on the second poll ─────────────────────────────

    /**
     * M6 asked that the geodata counts never re-scan the world on a poll. The
     * merged design (G3's SetupProgressRollup) goes further than the 8-second
     * cache this test first pinned: the poll reads a warmed snapshot and never
     * issues the GROUP BY itself. Once the snapshot is warm, a poll touches no
     * world table at all.
     */
    public function test_geodata_jurisdictions_counts_never_scan_on_the_poll_path(): void
    {
        $this->seedRow('00000000-0000-0000-0000-00000000dd01', self::HOST);
        $this->seedRow('00000000-0000-0000-0000-00000000dd02', self::HOST);

        $controller = app(\App\Http\Controllers\SetupController::class);
        $m = new ReflectionMethod($controller, 'jurisdictionsCounts');
        $m->setAccessible(true);

        $m->invoke($controller); // cold miss: computing skeleton, warm queued off the request path

        DB::enableQueryLog();
        $warm = $m->invoke($controller);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertArrayHasKey('snapshot_state', $warm);
        $scans = array_values(array_filter($log, fn ($q) => str_contains(strtolower($q['query']), 'from "jurisdictions"')));
        $this->assertCount(0, $scans, 'a poll never scans the world table; the snapshot owner does that off the request path');
    }
}
