<?php

namespace App\Services\Federation;

use App\Models\AuditCheckpoint;
use App\Models\FederationPeer;
use App\Models\PublicRecord;
use App\Models\SyncLogEntry;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * Full Faith & Credit sync (Phase F, WF-JUR-06 · Art. V §2).
 *
 * An instance ships a SIGNED tail of its audit chain + the public records
 * published in that window. The receiver:
 *   1. verifies the tail signature against the peer's pinned key (authenticity);
 *   2. independently RECOMPUTES the foreign chain segment (the peer's internal
 *      integrity — a standalone walk, NOT verifyChain over local data);
 *   3. mirrors the peer's public records under AUTHORITATIVE-INSTANCE-WINS — a
 *      record for a jurisdiction we are authoritative for is never overwritten;
 *   4. records the exchange in the append-only sync_log + an audit checkpoint.
 *
 * What NEVER syncs: ballots, raw locations, user credentials — by construction
 * the tail carries audit entries (commitments/counts only) and public records.
 */
class FederationSyncService
{
    public function __construct(
        private readonly InstanceIdentityService $identity,
        private readonly FederationClient $client,
        private readonly AuditService $audit,
        private readonly AuthorityResolver $authority,
    ) {}

    // ──────────────────────────────────────────────────────────────────────
    // Outbound — build + ship our tail
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Build a signed tail of our audit chain (seq > fromSeq) plus the public
     * records published in that window. Only locally-originated records ship
     * (source_server_id IS NULL) — mirrored peer records never re-export.
     *
     * @return array<string,mixed>
     */
    public function buildAuditTail(int $fromSeq, int $limit = 0, ?int $capTo = null): array
    {
        $head = DB::selectOne('SELECT seq, hash FROM audit_log ORDER BY seq DESC LIMIT 1');
        $headSeq = (int) $head->seq;

        // `capTo` is a chunked pull's FROZEN anchor (the host's head at pull start)
        // — pages walk toward it even as the live head advances. It never exceeds
        // the live head. `limit=0` + `capTo=null` ⇒ the full tail (byte-identical
        // to the pre-chunking behaviour; the Phase F push path is unchanged).
        $cap = $capTo !== null ? min($capTo, $headSeq) : $headSeq;

        $entriesQuery = DB::table('audit_log')
            ->where('seq', '>', $fromSeq)
            ->where('seq', '<=', $cap)
            ->orderBy('seq');

        if ($limit > 0) {
            $entriesQuery->limit($limit);
        }

        $entries = $entriesQuery
            ->get(['seq', 'prev_hash', 'hash', 'module', 'event', 'ref', 'jurisdiction_id', 'payload'])
            ->map(fn ($r) => [
                'seq' => (int) $r->seq,
                'prev_hash' => $r->prev_hash,
                'hash' => $r->hash,
                'module' => $r->module,
                'event' => $r->event,
                'ref' => $r->ref,
                'jurisdiction_id' => $r->jurisdiction_id,
                'payload' => json_decode($r->payload, true) ?? [],
            ])->all();

        // A page's head IS its last entry (so verifyForeignSegment/ingestTail work
        // unchanged). An empty page makes no progress (to_seq=fromSeq) so the
        // puller stops; an empty FULL tail keeps the head (today's behaviour).
        if ($entries === []) {
            $toSeq = $limit > 0 ? $fromSeq : $cap;
            $headHash = $limit > 0 ? '' : (string) $head->hash;
        } else {
            $last = $entries[array_key_last($entries)];
            $toSeq = (int) $last['seq'];
            $headHash = (string) $last['hash'];
        }

        $records = DB::table('public_records')
            ->whereNull('source_server_id')
            ->whereNotNull('audit_seq')
            ->where('audit_seq', '>', $fromSeq)
            ->where('audit_seq', '<=', $toSeq)
            ->orderBy('seq')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'kind' => $r->kind,
                'title' => $r->title,
                'body' => $r->body,
                'actor_user_id' => $r->actor_user_id,
                'actor_display' => $r->actor_display,
                'jurisdiction_id' => $r->jurisdiction_id,
                'legislature_id' => $r->legislature_id,
                'via_form' => $r->via_form,
                'via_workflow' => $r->via_workflow,
                'via_clock' => $r->via_clock,
                'subject_type' => $r->subject_type,
                'subject_id' => $r->subject_id,
                'translations' => json_decode($r->translations, true) ?? [],
                'published_at' => (string) $r->published_at,
            ])->all();

        $tail = [
            'server_id' => $this->identity->serverId(),
            'schema_version' => (string) config('cga.schema_version', '1'),
            'from_seq' => $fromSeq,
            'to_seq' => $toSeq,
            'head_hash' => $headHash,
            'entries' => $entries,
            'records' => $records,
        ];

        $tail['signature'] = $this->identity->sign(self::tailCanonical($tail));

        return $tail;
    }

    /**
     * The canonical string both sides sign/verify (the tail minus its own
     * signature). Reuses the audit-chain canonicalizer so cross-instance hashes
     * agree byte-for-byte.
     *
     * @param  array<string,mixed>  $tail
     */
    public static function tailCanonical(array $tail): string
    {
        unset($tail['signature']);

        return AuditService::canonicalJson($tail);
    }

    /** Pin our current head as a signed checkpoint a peer can verify against. */
    public function publishCheckpoint(): AuditCheckpoint
    {
        return DB::transaction(function () {
            $head = DB::selectOne('SELECT seq, hash FROM audit_log ORDER BY seq DESC LIMIT 1 FOR UPDATE');
            $auditSeq = (int) $head->seq;
            $headHash = (string) $head->hash;

            return AuditCheckpoint::create([
                'audit_seq' => $auditSeq,
                'head_hash' => $headHash,
                'published_to' => [],
                'signature' => $this->identity->sign($headHash.'|'.$auditSeq),
            ]);
        });
    }

    /** Build our tail and push it to a peer's /sync; record the outbound result. */
    public function pushTo(FederationPeer $peer): SyncLogEntry
    {
        $fromSeq = (int) ($peer->last_synced_seq ?? 0);
        $tail = $this->buildAuditTail($fromSeq);

        $response = $this->client->post($peer->url, '/api/federation/sync', $tail);
        $ok = $response->successful();

        $log = DB::transaction(function () use ($peer, $tail, $ok, $response) {
            $audit = $this->audit->append('federation', 'sync.pushed', [
                'peer_server_id' => $peer->server_id,
                'to_seq' => $tail['to_seq'],
                'records' => count($tail['records']),
                'accepted' => $ok,
            ], 'WF-JUR-06');

            return SyncLogEntry::create([
                'peer_id' => $peer->id,
                'direction' => SyncLogEntry::DIRECTION_OUTBOUND,
                'payload_hash' => hash('sha256', self::tailCanonical($tail)),
                'peer_head_hash' => $tail['head_hash'],
                'from_seq' => $tail['from_seq'] ?: null,
                'to_seq' => $tail['to_seq'] ?: null,
                'result' => $ok ? SyncLogEntry::RESULT_APPLIED : SyncLogEntry::RESULT_REJECTED_TAMPER,
                'audit_seq' => $audit->seq,
                'detail' => ['http_status' => $response->status(), 'peer_result' => $response->json('result')],
            ]);
        });

        if ($ok) {
            $peer->last_synced_seq = (int) $tail['to_seq'];
            $peer->save();
        }

        return $log;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Inbound — verify + apply a peer's tail
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Verify a peer's tail and apply its public records under authoritative-wins.
     *
     * @param  array<string,mixed>  $tail
     */
    public function ingestTail(FederationPeer $peer, array $tail): SyncLogEntry
    {
        $fromSeq = (int) ($tail['from_seq'] ?? 0);
        $toSeq = (int) ($tail['to_seq'] ?? 0);
        $headHash = (string) ($tail['head_hash'] ?? '');
        $signature = (string) ($tail['signature'] ?? '');
        $entries = (array) ($tail['entries'] ?? []);
        $records = (array) ($tail['records'] ?? []);
        $payloadHash = hash('sha256', self::tailCanonical($tail));

        // 1. Schema-version agreement — canonical JSON must match byte-for-byte.
        if ((string) ($tail['schema_version'] ?? '') !== (string) config('cga.schema_version', '1')) {
            return $this->recordSync($peer, SyncLogEntry::RESULT_REJECTED_TAMPER, $headHash, $fromSeq, $toSeq, $payloadHash,
                ['reason' => 'schema_version_mismatch', 'peer' => $tail['schema_version'] ?? null]);
        }

        // 2. Tail signature — authenticity against the PINNED peer key.
        if ($peer->public_key === null
            || ! InstanceIdentityService::verify((string) $peer->public_key, self::tailCanonical($tail), $signature)) {
            return $this->recordSync($peer, SyncLogEntry::RESULT_REJECTED_TAMPER, $headHash, $fromSeq, $toSeq, $payloadHash,
                ['reason' => 'signature_invalid']);
        }

        // 3. Foreign chain integrity — recompute the segment independently.
        if (! $this->verifyForeignSegment($entries)
            || ($entries !== [] && (string) end($entries)['hash'] !== $headHash)) {
            return $this->recordSync($peer, SyncLogEntry::RESULT_REJECTED_TAMPER, $headHash, $fromSeq, $toSeq, $payloadHash,
                ['reason' => 'chain_recompute_failed']);
        }

        // 4. Apply records under authoritative-instance-wins (atomic).
        return DB::transaction(function () use ($peer, $records, $headHash, $fromSeq, $toSeq, $payloadHash, $entries) {
            $applied = $conflicts = $nonAuthoritative = $skipped = [];

            foreach ($records as $rec) {
                $id = $rec['id'] ?? null;
                if ($id === null) {
                    continue;
                }
                if (PublicRecord::query()->where('id', $id)->exists()) {
                    $skipped[] = $id; // idempotent: already mirrored

                    continue;
                }

                match ($this->authorityDisposition($rec['jurisdiction_id'] ?? null, $peer)) {
                    'apply' => $this->mirrorRecord($rec, $peer, $applied),
                    'conflict' => $conflicts[] = $id,
                    default => $nonAuthoritative[] = $id,
                };
            }

            $result = SyncLogEntry::RESULT_APPLIED;
            if ($applied === [] && $conflicts !== []) {
                $result = SyncLogEntry::RESULT_CONFLICT_AUTHORITATIVE_WINS;
            } elseif ($applied === [] && $conflicts === [] && $nonAuthoritative !== []) {
                $result = SyncLogEntry::RESULT_REJECTED_NON_AUTHORITATIVE;
            }

            $log = $this->recordSync($peer, $result, $headHash, $fromSeq, $toSeq, $payloadHash, [
                'applied' => $applied,
                'conflicts' => $conflicts,
                'non_authoritative' => $nonAuthoritative,
                'skipped' => $skipped,
                'entries' => count($entries),
            ]);

            if ($toSeq > 0) {
                $peer->peer_head_seq = max((int) $peer->peer_head_seq, $toSeq);
                $peer->status = FederationPeer::STATUS_TRUST_ESTABLISHED;
                $peer->save();
            }

            // Pin our head after recording the sync (the checkpoint a peer
            // can later verify our derived state against).
            $this->publishCheckpoint();

            return $log;
        });
    }

    // ──────────────────────────────────────────────────────────────────────
    // Internals
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Recompute a foreign audit segment: each entry's hash must equal
     * sha256(prev_hash || canonical(payload)) and the links must chain. The
     * first entry's prev_hash is the trusted anchor (matching verifyChain's
     * fromSeq anchoring). Runs over the SUBMITTED segment only — never local.
     *
     * @param  array<int,array<string,mixed>>  $entries
     */
    private function verifyForeignSegment(array $entries): bool
    {
        if ($entries === []) {
            return true;
        }

        $expectedPrev = (string) ($entries[0]['prev_hash'] ?? '');

        foreach ($entries as $e) {
            if ((string) ($e['prev_hash'] ?? '') !== $expectedPrev) {
                return false;
            }

            $canonical = AuditService::canonicalJson((array) ($e['payload'] ?? []));
            if (AuditService::chainHash((string) $e['prev_hash'], $canonical) !== ($e['hash'] ?? null)) {
                return false;
            }

            $expectedPrev = (string) $e['hash'];
        }

        return true;
    }

    /**
     * Authoritative-instance-wins disposition for a record's jurisdiction:
     *   apply              — global record, unknown jurisdiction, or one this
     *                        peer is authoritative for → mirror it.
     *   conflict           — a jurisdiction WE are authoritative for → keep ours.
     *   non_authoritative  — a jurisdiction a THIRD party owns → refuse.
     *
     * The authority lookup is delegated to AuthorityResolver — the single source
     * of truth this also shares with WriteRouterService (G4). It reads only
     * `authoritative_server_id`, never leadership/cluster state, so this remains
     * an authority-path file clean of the cardinal grep pin.
     */
    private function authorityDisposition(?string $jurisdictionId, FederationPeer $peer): string
    {
        return match ($this->authority->authorityFor($jurisdictionId)) {
            AuthorityResolver::UNTRACKED => 'apply',          // global / a jurisdiction we don't track
            AuthorityResolver::OURS => 'conflict',            // we are authoritative — our copy wins
            (string) $peer->server_id => 'apply',             // the peer is authoritative for it
            default => 'non_authoritative',                   // a third party owns it
        };
    }

    /**
     * @param  array<string,mixed>  $rec
     * @param  array<int,string>  $applied
     */
    private function mirrorRecord(array $rec, FederationPeer $peer, array &$applied): void
    {
        PublicRecord::create([
            'id' => $rec['id'],
            'kind' => $rec['kind'] ?? 'other',
            'title' => $rec['title'] ?? '',
            'body' => $rec['body'] ?? null,
            'actor_user_id' => $rec['actor_user_id'] ?? null,
            'actor_display' => $rec['actor_display'] ?? null,
            'jurisdiction_id' => $rec['jurisdiction_id'] ?? null,
            'legislature_id' => $rec['legislature_id'] ?? null,
            'via_form' => $rec['via_form'] ?? null,
            'via_workflow' => $rec['via_workflow'] ?? null,
            'via_clock' => $rec['via_clock'] ?? null,
            'subject_type' => $rec['subject_type'] ?? null,
            'subject_id' => $rec['subject_id'] ?? null,
            'audit_seq' => null, // recognized foreign record — not in OUR chain
            'translations' => $rec['translations'] ?? [],
            'published_at' => $rec['published_at'] ?? now(),
            'source_server_id' => $peer->server_id,
        ]);

        $applied[] = $rec['id'];
    }

    /**
     * @param  array<string,mixed>  $detail
     */
    private function recordSync(
        FederationPeer $peer,
        string $result,
        ?string $headHash,
        int $fromSeq,
        int $toSeq,
        string $payloadHash,
        array $detail,
    ): SyncLogEntry {
        $rejected = $result === SyncLogEntry::RESULT_REJECTED_TAMPER
            || $result === SyncLogEntry::RESULT_REJECTED_NON_AUTHORITATIVE;

        return DB::transaction(function () use ($peer, $result, $headHash, $fromSeq, $toSeq, $payloadHash, $detail, $rejected) {
            $audit = $this->audit->append(
                'federation',
                $rejected ? 'sync.rejected' : 'sync.ingested',
                [
                    'peer_server_id' => $peer->server_id,
                    'result' => $result,
                    'from_seq' => $fromSeq,
                    'to_seq' => $toSeq,
                    'records_applied' => count($detail['applied'] ?? []),
                ],
                'WF-JUR-06',
                null,
                null,
                $rejected,
                $rejected ? 'Federation sync rejected: '.($detail['reason'] ?? $result) : null,
            );

            return SyncLogEntry::create([
                'peer_id' => $peer->id,
                'direction' => SyncLogEntry::DIRECTION_INBOUND,
                'payload_hash' => $payloadHash,
                'peer_head_hash' => $headHash ?: null,
                'from_seq' => $fromSeq ?: null,
                'to_seq' => $toSeq ?: null,
                'result' => $result,
                'audit_seq' => $audit->seq,
                'detail' => $detail,
            ]);
        });
    }
}
