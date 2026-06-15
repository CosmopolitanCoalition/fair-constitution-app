<?php

namespace Tests\Constitutional;

use App\Domain\Ballots\BallotCrypto;
use App\Models\ElectionBallotKeyRewrap;
use App\Models\FederationPeer;
use App\Models\OperationalPartitionExport;
use App\Services\Federation\BallotRewrapFailed;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\OperationalBundleService;
use App\Services\TabulationRecorder;
use App\Services\VoteCountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CountedRaceFixture;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G5 operational seed). The private rows that ride an
 * autonomy flip — first the per-election keys k_e — travel in a SEALED, point-to-
 * point bundle, never the routine sync tail. The pins:
 *  1. the sealed bundle is OPAQUE (k_e never appears in the clear) and only the
 *     addressed cluster can open it;
 *  2. opening + applying a bundle re-wraps each election's key to THIS cluster (via
 *     the G5a fail-closed adopt) so its certified count reproduces locally;
 *  3. a bundle carrying a corrupted key aborts the apply ATOMICALLY and fails
 *     closed — no election is left half-flipped.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class OperationalBundleSealedTest extends TestCase
{
    use CountedRaceFixture;
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_operational_bundle';

    public function test_a_sealed_bundle_is_opaque_and_only_the_recipient_can_open_it(): void
    {
        $this->onLivePg(function () {
            [$election] = $this->buildCountedRace();

            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $rawKe = BallotCrypto::unwrapDataKey(
                (string) $election->ballot_key_wrapped,
                BallotCrypto::kekFromAppKey((string) config('app.key'))
            );

            $result = app(OperationalBundleService::class)
                ->buildSealedFor((string) $election->jurisdiction_id, $this->selfAddressedPeer($identity));

            // OPAQUE — the raw key (base64 or hex) never appears in the sealed blob.
            $this->assertStringNotContainsString(base64_encode($rawKe), $result['sealed'], 'the sealed bundle must not leak k_e');
            $this->assertStringNotContainsString(bin2hex($rawKe), strtolower($result['sealed']));
            $this->assertSame(1, $result['export']->election_count);
            $this->assertSame(OperationalPartitionExport::DIRECTION_OUTBOUND, $result['export']->direction);

            // A bundle sealed to ANOTHER instance cannot be opened here.
            $foreign = sodium_crypto_sign_keypair();
            $foreignPub = sodium_bin2base64(sodium_crypto_sign_publickey($foreign), SODIUM_BASE64_VARIANT_ORIGINAL);
            $notForUs = InstanceIdentityService::sealTo($foreignPub, 'secret payload');

            $rejected = false;
            try {
                $identity->openSealed($notForUs);
            } catch (\Throwable) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'a bundle sealed to another instance cannot be opened here');
        });
    }

    public function test_opening_and_applying_a_bundle_rewraps_keys_to_this_cluster(): void
    {
        $this->onLivePg(function () {
            [$election, $race, $certifiedHash] = $this->buildCountedRace();

            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $rawKe = BallotCrypto::unwrapDataKey(
                (string) $election->ballot_key_wrapped,
                BallotCrypto::kekFromAppKey((string) config('app.key'))
            );

            // Seal the subtree's keys to ourselves (we are the gaining cluster).
            $sealed = app(OperationalBundleService::class)
                ->buildSealedFor((string) $election->jurisdiction_id, $this->selfAddressedPeer($identity))['sealed'];

            // Simulate the gaining cluster cannot yet decrypt: strand under a foreign KEK.
            $foreignKek = BallotCrypto::kekFromAppKey('base64:'.base64_encode(random_bytes(32)));
            $election->forceFill(['ballot_key_wrapped' => BallotCrypto::wrapDataKey($rawKe, $foreignKek)])->save();

            $export = app(OperationalBundleService::class)
                ->openAndApply($sealed, (string) Str::uuid(), (string) Str::uuid());

            $this->assertSame(OperationalPartitionExport::STATUS_APPLIED, $export->status);
            $this->assertSame(1, $export->applied_count);

            // The election now reproduces its certified count locally.
            $reproduced = app(VoteCountingService::class)
                ->countStv(app(TabulationRecorder::class)->countInput($race->fresh()))
                ->recordHash();
            $this->assertSame($certifiedHash, $reproduced, 'the applied key reproduces the certified record_hash');
            $this->assertSame(1, ElectionBallotKeyRewrap::query()->where('election_id', $election->id)->count());
        });
    }

    public function test_a_bundle_with_a_corrupted_key_aborts_atomically_and_fails_closed(): void
    {
        $this->onLivePg(function () {
            [$election] = $this->buildCountedRace();

            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $rawKe = BallotCrypto::unwrapDataKey(
                (string) $election->ballot_key_wrapped,
                BallotCrypto::kekFromAppKey((string) config('app.key'))
            );

            // Strand the election under a foreign KEK (arrived, not yet decryptable).
            $foreignKek = BallotCrypto::kekFromAppKey('base64:'.base64_encode(random_bytes(32)));
            $strandedWrap = BallotCrypto::wrapDataKey($rawKe, $foreignKek);
            $election->forceFill(['ballot_key_wrapped' => $strandedWrap])->save();

            // Hand-seal a bundle to ourselves carrying a CORRUPTED k_e.
            $badKey = $rawKe;
            $badKey[0] = $badKey[0] ^ "\x01";

            $payload = (string) json_encode([
                'schema'               => 'cga.operational-bundle.v1',
                'root_jurisdiction_id' => (string) $election->jurisdiction_id,
                'from_server_id'       => $identity->serverId(),
                'to_server_id'         => $identity->serverId(),
                'election_keys'        => [
                    ['election_id' => (string) $election->id, 'k_e' => base64_encode($badKey)],
                ],
            ], JSON_UNESCAPED_SLASHES);
            $sealed = InstanceIdentityService::sealTo($identity->publicKey(), $payload);

            $threw = false;
            try {
                app(OperationalBundleService::class)->openAndApply($sealed);
            } catch (BallotRewrapFailed) {
                $threw = true;
            }
            $this->assertTrue($threw, 'a corrupted bundle key must fail the apply closed');

            // ATOMIC fail-closed: the election is untouched (still stranded), no
            // re-wrap committed, and the abort is ledgered.
            $this->assertSame($strandedWrap, (string) \App\Models\Election::query()->find($election->id)->ballot_key_wrapped);
            $this->assertSame(0, ElectionBallotKeyRewrap::query()->where('election_id', $election->id)->count());
            $this->assertSame(
                1,
                OperationalPartitionExport::query()
                    ->where('status', OperationalPartitionExport::STATUS_FAILED)
                    ->where('direction', OperationalPartitionExport::DIRECTION_INBOUND)->count(),
                'the aborted apply is recorded as a failed inbound bundle'
            );
        });
    }

    /** A peer row addressed to OURSELVES (public_key = our key) so we can open our own bundle in-test. */
    private function selfAddressedPeer(InstanceIdentityService $identity): FederationPeer
    {
        return FederationPeer::create([
            'server_id'   => (string) Str::uuid(),
            'name'        => 'gaining-cluster',
            'url'         => 'https://gaining.test',
            'public_key'  => $identity->publicKey(),
            'status'      => FederationPeer::STATUS_TRUST_ESTABLISHED,
            'relation'    => FederationPeer::RELATION_SOVEREIGN,
        ]);
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
