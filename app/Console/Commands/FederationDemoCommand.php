<?php

namespace App\Console\Commands;

use App\Models\AuthorityClaim;
use App\Models\FederationPeer;
use App\Models\PartitionExport;
use App\Services\AuditService;
use App\Services\Federation\AuthorityFlipService;
use App\Services\Federation\FederationSyncService;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * institutions:demo-f / federation:demo — a STANDING browsable federation state,
 * so /federation renders like the A–E demos. Drives the REAL services: a
 * trust_established synthetic peer, a real FF&C sync history (applied + an
 * authoritative-wins conflict), a published checkpoint, and a county flipped to
 * the peer (authority_claim + partition_export).
 *
 * Idempotent; `--fresh` retires ONLY demo-tagged peers/claims/exports (the
 * append-only sync_log + audit_checkpoints are NEVER deleted — the standing
 * history accretes, exactly like audit_log).
 */
class FederationDemoCommand extends Command
{
    protected $signature = 'federation:demo {--fresh : Retire demo-tagged peers/claims/exports first}';

    protected $description = 'Stand up a browsable demo federation peer + sync history + a flipped partition';

    private const PEER_NAME = '[PhaseF-Demo] Peer Astra';

    public function handle(
        InstanceIdentityService $identity,
        FederationSyncService $sync,
        AuthorityFlipService $flips,
        AuditService $audit,
    ): int {
        $identity->ensureIdentity();
        $identity->setEnabled(true);

        if ($this->option('fresh')) {
            $this->teardown();
        }

        // ── A trusted synthetic peer (we hold its key so we can sign tails) ──
        $keypair = sodium_crypto_sign_keypair();
        $peerSecret = sodium_crypto_sign_secretkey($keypair);
        $peerPublic = sodium_bin2base64(sodium_crypto_sign_publickey($keypair), SODIUM_BASE64_VARIANT_ORIGINAL);

        $peer = FederationPeer::query()->where('name', self::PEER_NAME)->whereNull('deleted_at')->first()
            ?? new FederationPeer(['server_id' => (string) Str::uuid()]);
        $peer->fill([
            'name' => self::PEER_NAME,
            'url' => 'http://host.docker.internal:8080',
            'public_key' => $peerPublic,
            'status' => FederationPeer::STATUS_TRUST_ESTABLISHED,
            'trust_established_at' => now(),
            'metadata' => ['schema_version' => (string) config('cga.schema_version', '1'), 'demo' => true],
        ]);
        $peer->save();
        $this->line('  peer            : '.$peer->name.' ('.$peer->status.')');

        // ── A real FF&C sync: one applied record + one authoritative-wins ────
        $weOwn = (string) DB::table('jurisdictions')->whereNull('deleted_at')->whereNull('authoritative_server_id')->value('id');
        $peerOwns = (string) DB::table('jurisdictions')->whereNull('deleted_at')->where('id', '!=', $weOwn)->value('id');

        // Delegate $peerOwns to the peer so its record mirrors (the rest stays ours).
        DB::table('jurisdictions')->where('id', $peerOwns)->update(['authoritative_server_id' => $peer->server_id]);

        $records = [
            $this->record($peerOwns, 'act', 'Peer Astra enacts a local ordinance'),      // applies
            $this->record($weOwn, 'act', 'Peer Astra claims one of our jurisdictions'),  // authoritative-wins
        ];
        $tail = $this->signedTail($peer, $peerSecret, $records, $sync);
        $log = $sync->ingestTail($peer, $tail);
        $this->line('  sync            : '.$log->result.' (records: applied '.count($log->detail['applied'] ?? []).', conflict '.count($log->detail['conflicts'] ?? []).')');

        // ── A county flipped to the peer (authority transfer on the record) ──
        $county = $this->smallSubtreeRoot();
        if ($county !== null) {
            $export = $flips->exportFlip($county, $peer);
            $this->line('  authority flip  : '.$export->jurisdiction_id.' → '.$peer->name.' ('.$export->status.')');
        }

        $audit->append('federation', 'demo.seeded', ['peer_server_id' => $peer->server_id], 'WF-JUR-06');

        $this->info('Federation demo standing — visit /federation.');

        return self::SUCCESS;
    }

    private function teardown(): void
    {
        $peers = FederationPeer::query()->where('name', 'like', '[PhaseF-Demo]%')->get();
        foreach ($peers as $peer) {
            // Restore any flipped jurisdictions, then retire claims/exports.
            $exports = PartitionExport::query()->where('peer_id', $peer->id)->get();
            foreach ($exports as $export) {
                $descendants = array_map('strval', (array) ($export->manifest['descendant_ids'] ?? []));
                DB::table('jurisdictions')->whereIn('id', $descendants)
                    ->update(['authoritative_server_id' => null, 'authoritative_server_url' => null]);
                $export->delete();
            }
            AuthorityClaim::query()->where('claimed_by_peer_id', $peer->id)->get()->each->delete();
            // Un-delegate anything we delegated to this peer.
            DB::table('jurisdictions')->where('authoritative_server_id', $peer->server_id)
                ->update(['authoritative_server_id' => null, 'authoritative_server_url' => null]);
            $peer->delete();
        }
        $this->line('  (--fresh: retired demo peers; append-only sync_log/checkpoints preserved)');
    }

    /** @param  array<int,array<string,mixed>>  $records */
    private function signedTail(FederationPeer $peer, string $secret, array $records, FederationSyncService $sync): array
    {
        $head = DB::selectOne('SELECT seq, hash FROM audit_log ORDER BY seq DESC LIMIT 1');
        $entries = DB::table('audit_log')->orderByDesc('seq')->limit(3)->get()->reverse()->values()
            ->map(fn ($r) => [
                'seq' => (int) $r->seq, 'prev_hash' => $r->prev_hash, 'hash' => $r->hash,
                'module' => $r->module, 'event' => $r->event, 'ref' => $r->ref,
                'jurisdiction_id' => $r->jurisdiction_id, 'payload' => json_decode($r->payload, true) ?: [],
            ])->all();
        $last = $entries[array_key_last($entries)];

        $tail = [
            'server_id' => $peer->server_id,
            'schema_version' => (string) config('cga.schema_version', '1'),
            'from_seq' => $entries[0]['seq'] - 1,
            'to_seq' => $last['seq'],
            'head_hash' => $last['hash'],
            'entries' => $entries,
            'records' => $records,
        ];
        $tail['signature'] = sodium_bin2base64(
            sodium_crypto_sign_detached(FederationSyncService::tailCanonical($tail), $secret),
            SODIUM_BASE64_VARIANT_ORIGINAL
        );

        return $tail;
    }

    /** @return array<string,mixed> */
    private function record(?string $jurisdictionId, string $kind, string $title): array
    {
        return [
            'id' => (string) Str::uuid(),
            'kind' => $kind,
            'title' => $title,
            'body' => 'Recognized under Full Faith & Credit — Phase F demo.',
            'jurisdiction_id' => $jurisdictionId,
            'published_at' => (string) now(),
            'translations' => [],
        ];
    }

    private function smallSubtreeRoot(): ?string
    {
        $row = DB::selectOne(
            'SELECT p.id FROM jurisdictions p
             WHERE p.deleted_at IS NULL AND p.authoritative_server_id IS NULL
               AND EXISTS (SELECT 1 FROM jurisdictions c WHERE c.parent_id = p.id AND c.deleted_at IS NULL)
               AND NOT EXISTS (
                   SELECT 1 FROM jurisdictions c JOIN jurisdictions gc ON gc.parent_id = c.id AND gc.deleted_at IS NULL
                   WHERE c.parent_id = p.id AND c.deleted_at IS NULL)
             LIMIT 1'
        );

        return $row !== null ? (string) $row->id : null;
    }
}
