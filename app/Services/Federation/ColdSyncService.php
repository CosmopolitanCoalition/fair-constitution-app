<?php

namespace App\Services\Federation;

use App\Models\AuditChainReconciliation;
use App\Models\FederationPeer;
use App\Models\InstanceSettings;
use App\Models\SyncCursor;
use App\Models\SyncLogEntry;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Cold sync (Phase G) — pull a peer's full corpus in bounded, resumable, signed
 * PAGES. A page IS a tail (its head_hash is the last entry's hash), so each page
 * is verified + applied by the UNCHANGED FederationSyncService::ingestTail. Two
 * things ingestTail does NOT do that this layer adds:
 *   1. CROSS-PAGE CONTINUITY — page N+1's first entry must chain from page N's
 *      head (ingestTail only trusts the first entry's prev_hash WITHIN a page);
 *      a spliced page aborts the cursor and applies nothing.
 *   2. RESUMABLE progress — the SyncCursor persists the watermark so a crashed
 *      or heartbeat-drained pull continues exactly where it stopped.
 *
 * PULL-ONLY (GET) — so it never round-trips the body-mutating inbound middleware.
 */
class ColdSyncService
{
    public function __construct(
        private readonly MultiplexClient $multiplex,
        private readonly FederationSyncService $sync,
    ) {}

    /** Drain a peer's corpus, up to $maxPages (0 = until caught up or aborted). */
    public function pull(FederationPeer $peer, int $maxPages = 0): SyncCursor
    {
        $cursor = $this->openCursor($peer);
        $pages = 0;

        while ($cursor->status === SyncCursor::STATUS_OPEN) {
            if (! $this->pullOnePage($peer, $cursor)) {
                break; // caught up or aborted
            }
            $pages++;
            if ($maxPages > 0 && $pages >= $maxPages) {
                break;
            }
        }

        return $cursor->refresh();
    }

    /** Fetch + verify + apply one page; advance the cursor. Returns false when done/aborted. */
    public function pullOnePage(FederationPeer $peer, SyncCursor $cursor): bool
    {
        // G-VER (Meter C / fail-closed): never pull counted pages from a peer counting
        // under a DIFFERENT constitutional_version. ingestTail's gate 2b would reject
        // every page anyway; aborting BEFORE the fetch fails fast and names the precise
        // reason (vs. the generic 'page_rejected'), symmetric with pushTo's skip. A
        // peer that declares no version (pre-G-VER) is grandfathered.
        if ($peer->constitutional_version !== null
            && $peer->constitutional_version !== InstanceSettings::current()->constitutionalVersion()) {
            $this->abort($cursor, 'constitutional_version_mismatch');

            return false;
        }

        $pageSize = (int) $cursor->page_size;
        $from = (int) $cursor->next_from_seq;

        $response = $this->fetchPageWithRetry($peer, $from, $pageSize);

        $page = (array) $response->json();
        $entries = (array) ($page['entries'] ?? []);

        // (1) Cross-page continuity — the only check ingestTail can't make. A break
        // that lands on a page boundary is tolerated ONLY when we hold a matching
        // constitutional acknowledgement for it (else it aborts, as before).
        if ($cursor->last_page_hash !== null && $entries !== []
            && (string) ($entries[0]['prev_hash'] ?? '') !== (string) $cursor->last_page_hash
            && (AuditChainReconciliation::blessedMap()[(int) ($entries[0]['seq'] ?? 0)] ?? null) !== (string) ($entries[0]['prev_hash'] ?? '')) {
            $this->abort($cursor, 'continuity_break');

            return false;
        }

        $toSeq = (int) ($page['to_seq'] ?? $from);
        if ($toSeq <= $from) {
            $this->complete($cursor); // no progress ⇒ caught up

            return false;
        }

        $log = $this->sync->ingestTail($peer, $page);
        if ($log->result === SyncLogEntry::RESULT_REJECTED_TAMPER) {
            $this->abort($cursor, 'page_rejected');

            return false;
        }

        $cursor->forceFill([
            'from_seq' => $from,
            'next_from_seq' => $toSeq,
            'last_page_hash' => (string) ($page['head_hash'] ?? ''),
            'pages_applied' => (int) $cursor->pages_applied + 1,
            'records_applied' => (int) $cursor->records_applied + count($log->detail['applied'] ?? []),
        ])->save();

        if (count($entries) < $pageSize) {
            $this->complete($cursor); // a short page ⇒ caught up to the head

            return false;
        }

        return true;
    }

    /**
     * Fetch one page (idempotent GET) over the multiplex ladder (G8b), retrying
     * transient WAN failures — every transport down (NoSurvivingTransport) or a 5xx —
     * with exponential backoff before giving up. The multiplex itself fails over across
     * a peer's transports per attempt; this loop adds the cross-attempt retry for a
     * brief total blip so it never aborts a multi-hour backfill. A 4xx is a definitive
     * answer (misconfig/auth) and is never retried.
     */
    private function fetchPageWithRetry(FederationPeer $peer, int $from, int $pageSize): Response
    {
        $attempts = max(1, (int) config('cga.federation_cold_retry_attempts', 3));
        $backoffMs = max(0, (int) config('cga.federation_cold_retry_backoff_ms', 500));
        $last = 'no attempt';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->multiplex->reach((string) $peer->server_id, 'GET', '/api/federation/audit-tail', [
                    'from_seq' => $from,
                    'page_size' => $pageSize,
                ]);

                if ($response->successful()) {
                    return $response;
                }

                // A 4xx is a real answer (misconfig / auth / not-trusted) — retrying
                // cannot help; fail immediately, as the pre-G8b code did.
                if ($response->status() < 500) {
                    throw new RuntimeException("Cold-sync page fetch refused (HTTP {$response->status()}).");
                }

                $last = "HTTP {$response->status()}";
            } catch (NoSurvivingTransport $e) {
                // A peer with NO dialable rung at all is a permanent misconfiguration
                // (e.g. onion-only with no SOCKS proxy), not a transient blip — fail fast
                // instead of sleeping through the whole backoff budget for nothing.
                if ($e->undialable) {
                    throw new RuntimeException("Cold-sync page fetch: {$e->getMessage()}");
                }
                $last = 'no surviving transport: '.$e->getMessage();
            }

            if ($attempt < $attempts && $backoffMs > 0) {
                usleep($backoffMs * (2 ** ($attempt - 1)) * 1000);
            }
        }

        throw new RuntimeException("Cold-sync page fetch failed after {$attempts} attempt(s) (last: {$last}).");
    }

    /** Resume the open cold cursor for a peer, or open one at the peer watermark. */
    private function openCursor(FederationPeer $peer): SyncCursor
    {
        $cursor = SyncCursor::query()
            ->where('peer_id', $peer->id)
            ->where('direction', SyncCursor::DIRECTION_INBOUND)
            ->where('mode', SyncCursor::MODE_COLD)
            ->where('status', SyncCursor::STATUS_OPEN)
            ->first();

        if ($cursor !== null) {
            return $cursor;
        }

        return SyncCursor::create([
            'peer_id' => $peer->id,
            'direction' => SyncCursor::DIRECTION_INBOUND,
            'mode' => SyncCursor::MODE_COLD,
            'from_seq' => (int) ($peer->peer_head_seq ?? 0),
            'next_from_seq' => (int) ($peer->peer_head_seq ?? 0),
            'page_size' => (int) config('cga.federation_sync_page_size', 500),
            'status' => SyncCursor::STATUS_OPEN,
        ]);
    }

    private function complete(SyncCursor $cursor): void
    {
        $cursor->forceFill(['status' => SyncCursor::STATUS_COMPLETE])->save();
    }

    private function abort(SyncCursor $cursor, string $reason): void
    {
        $cursor->forceFill(['status' => SyncCursor::STATUS_ABORTED, 'abort_reason' => $reason])->save();
    }
}
