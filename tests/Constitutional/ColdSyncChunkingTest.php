<?php

namespace Tests\Constitutional;

use App\Models\FederationPeer;
use App\Models\SyncCursor;
use App\Services\Federation\ColdSyncService;
use App\Services\Federation\FederationSyncService;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G cold sync. A fresh mirror pulls a peer's full
 * corpus in BOUNDED, signed PAGES, never one multi-MB body (the body-size
 * failure the live two-instance demo hit). The pins:
 *  1. a page IS a tail — limit=0 stays byte-identical to Phase F (the push path
 *     is unchanged), and every page's signature + foreign-chain recompute hold;
 *  2. CROSS-PAGE CONTINUITY — page N+1's first entry chains from page N's head;
 *     a spliced page aborts the cursor and applies nothing;
 *  3. the pull is RESUMABLE + IDEMPOTENT — a bounded/crashed run continues from
 *     the persisted cursor; re-running is a no-op.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class ColdSyncChunkingTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_cold_sync';

    public function test_limit_zero_full_tail_is_unchanged_and_pages_cover_the_chain_with_continuity(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $sync = app(FederationSyncService::class);
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();

            $head = (int) DB::table('audit_log')->max('seq');
            // Anchor ~200 ROWS back (seq RANGE is gappy — rolled-back tests burn seqs).
            $start = (int) (DB::table('audit_log')->orderByDesc('seq')->offset(200)->limit(1)->value('seq') ?? 0);
            $K = 40;

            // ── limit=0 full tail: to_seq == head, head_hash == head hash ────
            $full = $sync->buildAuditTail($start);
            $this->assertSame($head, (int) $full['to_seq'], 'a full tail still runs to the live head');
            $this->assertSame(
                (string) DB::table('audit_log')->where('seq', $head)->value('hash'),
                (string) $full['head_hash'],
                'a full tail head_hash is the head row hash (Phase F unchanged)'
            );

            // ── page through the SAME window in steps of K ───────────────────
            $cursor = $start;
            $prevHeadHash = null;
            $seenSeqs = [];
            $pages = 0;

            while (true) {
                $page = $sync->buildAuditTail($cursor, $K);
                $entries = $page['entries'];
                if ($entries === []) {
                    break;
                }
                $pages++;

                $this->assertLessThanOrEqual($K, count($entries), 'a page never exceeds the requested size');
                $this->assertTrue(
                    InstanceIdentityService::verify($identity->publicKey(), FederationSyncService::tailCanonical($page), $page['signature']),
                    'every page is independently signed + verifiable'
                );

                // (2) cross-page continuity: this page chains from the previous page's head.
                if ($prevHeadHash !== null) {
                    $this->assertSame($prevHeadHash, (string) $entries[0]['prev_hash'], 'page N+1 chains from page N head');
                }

                foreach ($entries as $e) {
                    $seenSeqs[] = (int) $e['seq'];
                }
                $prevHeadHash = (string) $page['head_hash'];
                $cursor = (int) $page['to_seq'];
                if ($cursor >= $head) {
                    break;
                }
            }

            // The union of the pages == the full window's entries, in order, no gaps/dupes.
            $fullSeqs = array_map(fn ($e) => (int) $e['seq'], $sync->buildAuditTail($start)['entries']);
            $this->assertSame($fullSeqs, $seenSeqs, 'paging covers exactly the full tail — no gap, no overlap');
            $this->assertGreaterThan(1, $pages, 'the window genuinely paginated');
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
        }
    }

    public function test_cold_pull_is_resumable_idempotent_and_aborts_on_a_spliced_page(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            config(['cga.federation_sync_page_size' => 20]);

            // The "host" is us, pinned under a peer row (pages we sign verify
            // against this peer's key). Anchor the cursor ~60 ROWS back (seq
            // RANGE is gappy — rolled-back tests burn seq values).
            $head = (int) DB::table('audit_log')->max('seq');
            $start = (int) (DB::table('audit_log')->orderByDesc('seq')->offset(60)->limit(1)->value('seq') ?? 0);
            $peer = FederationPeer::create([
                'server_id' => (string) Str::uuid(),
                'name' => 'Cold-sync host (self)',
                'url' => 'http://host.docker.internal:9990',
                'public_key' => $identity->publicKey(),
                'status' => FederationPeer::STATUS_TRUST_ESTABLISHED,
                'trust_established_at' => now(),
                'peer_head_seq' => $start,
            ]);

            $cold = app(ColdSyncService::class);
            $sync = app(FederationSyncService::class);

            // Faithful host: each GET returns a real signed page from our chain.
            $this->fakeHost($sync);

            // Resume: a bounded run leaves the cursor OPEN with progress.
            $cursor = $cold->pull($peer, 1);
            $this->assertSame(SyncCursor::STATUS_OPEN, $cursor->status, 'a bounded run pauses mid-pull');
            $this->assertSame(1, $cursor->pages_applied);
            $firstWatermark = (int) $cursor->next_from_seq;
            $this->assertGreaterThan($start, $firstWatermark, 'the cursor advanced');

            // Continue → completes; the watermark only moves forward.
            $cursor = $cold->pull($peer);
            $this->assertSame(SyncCursor::STATUS_COMPLETE, $cursor->status, 'resume drains to caught-up');
            $this->assertGreaterThan($firstWatermark, (int) $cursor->next_from_seq);

            // Idempotent: re-pulling opens a fresh cursor that immediately completes.
            $again = $cold->pull($peer);
            $this->assertSame(SyncCursor::STATUS_COMPLETE, $again->status, 'a caught-up re-pull is a no-op');

            // ── A spliced page aborts the cursor (continuity break) ──────────
            // Deterministic: a cursor whose recorded last_page_hash does NOT match
            // the next page's first prev_hash is a splice — pull one page, abort.
            $splicePeer = FederationPeer::create([
                'server_id' => (string) Str::uuid(),
                'name' => 'Spliced host',
                'url' => 'http://host.docker.internal:9991',
                'public_key' => $identity->publicKey(),
                'status' => FederationPeer::STATUS_TRUST_ESTABLISHED,
                'trust_established_at' => now(),
            ]);
            $spliceCursor = SyncCursor::create([
                'peer_id' => $splicePeer->id,
                'direction' => SyncCursor::DIRECTION_INBOUND,
                'mode' => SyncCursor::MODE_COLD,
                'from_seq' => $start,
                'next_from_seq' => $start,
                'page_size' => 20,
                'last_page_hash' => str_repeat('a', 64), // a hash the real page will NOT chain from
                'status' => SyncCursor::STATUS_OPEN,
            ]);
            $this->fakeHost($sync); // a genuine, well-formed page
            $applied = $cold->pullOnePage($splicePeer, $spliceCursor);
            $this->assertFalse($applied, 'a discontinuous page is refused');
            $this->assertSame(SyncCursor::STATUS_ABORTED, $spliceCursor->refresh()->status, 'a spliced page aborts the cursor');
            $this->assertSame('continuity_break', $spliceCursor->abort_reason);
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
        }
    }

    /** Fake the host's GET /audit-tail to return real signed pages from our chain. */
    private function fakeHost(FederationSyncService $sync, int $spliceAfterPage = 0): void
    {
        $pageCount = 0;
        Http::fake([
            '*/api/federation/audit-tail*' => function ($request) use ($sync, &$pageCount, $spliceAfterPage) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
                $page = $sync->buildAuditTail((int) ($q['from_seq'] ?? 0), (int) ($q['page_size'] ?? 500));
                $pageCount++;
                if ($spliceAfterPage > 0 && $pageCount === $spliceAfterPage + 1 && $page['entries'] !== []) {
                    // Corrupt the continuity link (prev_hash no longer matches the
                    // previous page's head) and re-sign so only the cross-page
                    // check — not the in-page signature — can catch it.
                    $page['entries'][0]['prev_hash'] = str_repeat('0', 64);
                    $page['signature'] = app(InstanceIdentityService::class)->sign(FederationSyncService::tailCanonical($page));
                }

                return Http::response($page, 200);
            },
        ]);
    }
}
