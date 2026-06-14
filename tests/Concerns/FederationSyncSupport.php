<?php

namespace Tests\Concerns;

use App\Models\FederationPeer;
use App\Services\Federation\FederationSyncService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared harness for the F2 Full-Faith-&-Credit sync tests: a guarded live-pg
 * connection, a fake trusted peer whose Ed25519 secret the test holds, and a
 * tail builder/signer that produces (and can tamper) a peer tail exactly as the
 * real FederationSyncService would.
 */
trait FederationSyncSupport
{
    /** @var string raw Ed25519 secret key of the fake peer */
    protected string $peerSecret;

    protected function livePg(string $name): Connection
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql not loaded — live pins run inside the app container.');
        }

        config([
            'database.connections.'.$name => array_merge(
                config('database.connections.pgsql'),
                ['database' => env('LIVE_PG_DATABASE', 'fair_constitution')]
            ),
        ]);

        try {
            $connection = DB::connection($name);
            $connection->getPdo();

            return $connection;
        } catch (\Throwable $e) {
            $this->markTestSkipped('Live PostgreSQL unreachable — run inside the app container. ('.$e->getMessage().')');
        }
    }

    /** Create a trust_established peer; the test keeps its secret for signing. */
    protected function makeTrustedPeer(): FederationPeer
    {
        $keypair = sodium_crypto_sign_keypair();
        $this->peerSecret = sodium_crypto_sign_secretkey($keypair);
        $publicB64 = sodium_bin2base64(sodium_crypto_sign_publickey($keypair), SODIUM_BASE64_VARIANT_ORIGINAL);

        return FederationPeer::create([
            'server_id' => (string) Str::uuid(),
            'name' => 'Peer A (test)',
            'url' => 'http://host.docker.internal:9998',
            'public_key' => $publicB64,
            'status' => FederationPeer::STATUS_TRUST_ESTABLISHED,
            'trust_established_at' => now(),
            'metadata' => ['schema_version' => '1'],
        ]);
    }

    /** The last $count local audit rows, reshaped as a (valid) chain segment. */
    protected function localEntries(Connection $conn, int $count = 3): array
    {
        return $conn->table('audit_log')->orderByDesc('seq')->limit($count)->get()
            ->reverse()->values()
            ->map(fn ($r) => [
                'seq' => (int) $r->seq,
                'prev_hash' => $r->prev_hash,
                'hash' => $r->hash,
                'module' => $r->module,
                'event' => $r->event,
                'ref' => $r->ref,
                'jurisdiction_id' => $r->jurisdiction_id,
                'payload' => json_decode($r->payload, true) ?: [],
            ])->all();
    }

    /**
     * A public-record dict for a tail.
     *
     * @return array<string,mixed>
     */
    protected function record(?string $jurisdictionId, string $kind = 'act', ?string $id = null): array
    {
        return [
            'id' => $id ?? (string) Str::uuid(),
            'kind' => $kind,
            'title' => 'Peer A '.$kind.' '.Str::random(5),
            'body' => 'Recognized under Full Faith & Credit (throwaway).',
            'jurisdiction_id' => $jurisdictionId,
            'published_at' => (string) now(),
            'translations' => [],
        ];
    }

    /**
     * Build an UNSIGNED tail as if originated by $peer (entries = a real local
     * chain segment, records = supplied).
     *
     * @param  array<int,array<string,mixed>>  $records
     * @return array<string,mixed>
     */
    protected function craftTail(Connection $conn, FederationPeer $peer, array $records, int $entryCount = 3): array
    {
        $entries = $this->localEntries($conn, $entryCount);
        $last = $entries[array_key_last($entries)];

        return [
            'server_id' => $peer->server_id,
            'schema_version' => (string) config('cga.schema_version', '1'),
            'from_seq' => $entries[0]['seq'] - 1,
            'to_seq' => $last['seq'],
            'head_hash' => $last['hash'],
            'entries' => $entries,
            'records' => $records,
        ];
    }

    /**
     * Sign a tail with the fake peer's key (the origin signs its own tail).
     *
     * @param  array<string,mixed>  $tail
     * @return array<string,mixed>
     */
    protected function signTail(array $tail): array
    {
        $tail['signature'] = sodium_bin2base64(
            sodium_crypto_sign_detached(FederationSyncService::tailCanonical($tail), $this->peerSecret),
            SODIUM_BASE64_VARIANT_ORIGINAL
        );

        return $tail;
    }
}
