<?php

namespace Tests\Constitutional;

use App\Models\AuditCheckpoint;
use App\Models\SyncLogEntry;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase F federation ledgers are APPEND-ONLY, and the
 * federation identity signs/verifies soundly (Art. V §2 Full Faith & Credit).
 *
 * The sync ledger (`sync_log`) and the head checkpoints (`audit_checkpoints`)
 * are the tamper-evident record of what crossed the mesh. Like `audit_log`,
 * they are immutable by construction: no soft delete, no updated_at, a BEFORE
 * UPDATE/DELETE trigger + a TRUNCATE block at the DB layer, and an Eloquent
 * guard that throws before a query is attempted. A federation exchange that
 * "didn't happen" cannot be un-logged.
 *
 * Identity: the Ed25519 sign/verify round-trip is the basis for peer-signature
 * verification (a forged or mutated peer payload must fail verification) — the
 * cryptographic floor under VerifyPeerSignature + the FF&C tamper rejection.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class SyncLogAppendOnlyTest extends TestCase
{
    private const LIVE_CONNECTION = 'pgsql_sync_log';

    // ======================================================================
    // 1. Identity sign/verify (DB-free — pure crypto floor)
    // ======================================================================

    public function test_verify_accepts_a_valid_signature_and_rejects_every_tamper(): void
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('ext-sodium not loaded.');
        }

        $keypair = sodium_crypto_sign_keypair();
        $publicB64 = sodium_bin2base64(sodium_crypto_sign_publickey($keypair), SODIUM_BASE64_VARIANT_ORIGINAL);
        $secret = sodium_crypto_sign_secretkey($keypair);

        $message = 'POST\n/api/federation/sync\n1750000000\n'.hash('sha256', '{"tail":true}');
        $signatureB64 = sodium_bin2base64(sodium_crypto_sign_detached($message, $secret), SODIUM_BASE64_VARIANT_ORIGINAL);

        $this->assertTrue(
            InstanceIdentityService::verify($publicB64, $message, $signatureB64),
            'A valid Ed25519 signature verifies.'
        );

        // Tampered message → fail.
        $this->assertFalse(
            InstanceIdentityService::verify($publicB64, $message.'X', $signatureB64),
            'A mutated message must fail verification (the FF&C tamper floor).'
        );

        // Tampered signature → fail.
        $badSig = sodium_bin2base64(random_bytes(SODIUM_CRYPTO_SIGN_BYTES), SODIUM_BASE64_VARIANT_ORIGINAL);
        $this->assertFalse(
            InstanceIdentityService::verify($publicB64, $message, $badSig),
            'A forged signature must fail verification.'
        );

        // Wrong key → fail.
        $otherPublic = sodium_bin2base64(
            sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()),
            SODIUM_BASE64_VARIANT_ORIGINAL
        );
        $this->assertFalse(
            InstanceIdentityService::verify($otherPublic, $message, $signatureB64),
            'A signature from another instance must fail against our key.'
        );

        // Malformed input → false, never an exception.
        $this->assertFalse(InstanceIdentityService::verify('not-base64!!', $message, $signatureB64));
        $this->assertFalse(InstanceIdentityService::verify($publicB64, $message, 'not-base64!!'));
    }

    // ======================================================================
    // 2. Schema pins (read-only information_schema — live pg, guarded)
    // ======================================================================

    public function test_both_ledgers_are_append_only_by_construction(): void
    {
        $pg = $this->livePg();

        foreach (['sync_log', 'audit_checkpoints'] as $tableName) {
            $columns = array_map(
                fn ($row) => $row->column_name,
                $pg->select(
                    "SELECT column_name FROM information_schema.columns
                     WHERE table_schema = 'public' AND table_name = ?",
                    [$tableName]
                )
            );

            $this->assertNotEmpty($columns, "{$tableName} exists");
            $this->assertNotContains('updated_at', $columns, "{$tableName} has no update timestamp — rows never change");
            $this->assertNotContains('deleted_at', $columns, "{$tableName} has no soft-delete column — rows never leave");

            $triggers = array_map(
                fn ($t) => $t->tgname,
                $pg->select(
                    'SELECT tgname FROM pg_trigger WHERE tgrelid = ?::regclass AND NOT tgisinternal',
                    [$tableName]
                )
            );
            $this->assertContains("{$tableName}_immutable", $triggers, "{$tableName} blocks UPDATE/DELETE");
            $this->assertContains("{$tableName}_no_truncate", $triggers, "{$tableName} blocks TRUNCATE");
        }
    }

    // ======================================================================
    // 3. Trigger behavior + model guards (live, rolled back)
    // ======================================================================

    public function test_sync_log_rows_are_immutable_at_every_layer(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $conn->beginTransaction();

        try {
            $entry = SyncLogEntry::create([
                'peer_id' => null,
                'direction' => SyncLogEntry::DIRECTION_INBOUND,
                'payload_hash' => str_repeat('a', 64),
                'result' => SyncLogEntry::RESULT_APPLIED,
                'detail' => ['records' => 3],
            ]);
            $entry->refresh();
            $this->assertNotNull($entry->seq, 'the ledger row gets a bigserial seq');

            // Raw UPDATE raises (savepoint so the outer transaction survives).
            try {
                DB::transaction(function () use ($conn, $entry) {
                    $conn->statement("UPDATE sync_log SET result = 'rejected_tamper' WHERE seq = ?", [(int) $entry->seq]);
                });
                $this->fail('UPDATE on sync_log must raise.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }

            // Raw DELETE raises.
            try {
                DB::transaction(function () use ($conn, $entry) {
                    $conn->statement('DELETE FROM sync_log WHERE seq = ?', [(int) $entry->seq]);
                });
                $this->fail('DELETE on sync_log must raise.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }

            // The Eloquent model forbids update/delete before a query is run.
            try {
                $entry->forceFill(['result' => SyncLogEntry::RESULT_REJECTED_TAMPER])->save();
                $this->fail('Model update must throw.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }

            try {
                $entry->delete();
                $this->fail('Model delete must throw.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }

            // The row is byte-identical after every attempt.
            $row = $conn->table('sync_log')->where('seq', (int) $entry->seq)->first();
            $this->assertSame('applied', $row->result);
        } finally {
            $conn->rollBack();
            DB::setDefaultConnection($originalDefault);
        }
    }

    public function test_audit_checkpoints_are_immutable_at_every_layer(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $conn->beginTransaction();

        try {
            $checkpoint = AuditCheckpoint::create([
                'audit_seq' => 1,
                'head_hash' => str_repeat('b', 64),
                'published_to' => [],
                'signature' => 'sig-'.Str::random(8),
            ]);
            $checkpoint->refresh();

            try {
                DB::transaction(function () use ($conn, $checkpoint) {
                    $conn->statement('UPDATE audit_checkpoints SET head_hash = ? WHERE seq = ?', [str_repeat('c', 64), (int) $checkpoint->seq]);
                });
                $this->fail('UPDATE on audit_checkpoints must raise.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }

            try {
                $checkpoint->forceFill(['head_hash' => str_repeat('d', 64)])->save();
                $this->fail('Model update must throw.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }

            $row = $conn->table('audit_checkpoints')->where('seq', (int) $checkpoint->seq)->first();
            $this->assertSame(str_repeat('b', 64), $row->head_hash);
        } finally {
            $conn->rollBack();
            DB::setDefaultConnection($originalDefault);
        }
    }

    // ======================================================================
    // 4. Identity round-trip through the service (live — mint + sign + verify)
    // ======================================================================

    public function test_minted_identity_signs_messages_its_own_public_key_verifies(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $conn->beginTransaction();

        try {
            $identity = app(InstanceIdentityService::class);
            $settings = $identity->ensureIdentity();

            $this->assertNotNull($settings->server_id, 'a server_id is minted');
            $this->assertNotNull($settings->public_key, 'a public key is minted');
            $this->assertNotNull($settings->private_key_encrypted, 'the private key is stored (encrypted)');

            $message = 'federation-identity-roundtrip-'.Str::random(12);
            $signature = $identity->sign($message);

            $this->assertTrue(
                InstanceIdentityService::verify($identity->publicKey(), $message, $signature),
                'A message signed by the instance verifies against its published public key.'
            );
            $this->assertFalse(
                InstanceIdentityService::verify($identity->publicKey(), $message.'!', $signature),
                'A tampered message fails against the instance public key.'
            );
        } finally {
            $conn->rollBack();
            DB::setDefaultConnection($originalDefault);
        }
    }

    private function livePg(): \Illuminate\Database\Connection
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql not loaded — live pins run inside the app container.');
        }

        config([
            'database.connections.'.self::LIVE_CONNECTION => array_merge(
                config('database.connections.pgsql'),
                ['database' => env('LIVE_PG_DATABASE', 'fair_constitution')]
            ),
        ]);

        try {
            $connection = DB::connection(self::LIVE_CONNECTION);
            $connection->getPdo();

            return $connection;
        } catch (\Throwable $e) {
            $this->markTestSkipped('Live PostgreSQL unreachable — run inside the app container. ('.$e->getMessage().')');
        }
    }
}
