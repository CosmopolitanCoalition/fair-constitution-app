<?php

namespace Tests\Constitutional;

use App\Domain\Ballots\BallotCrypto;
use App\Http\Controllers\Federation\FlipController;
use App\Models\Election;
use App\Models\ElectionBallotKeyRewrap;
use App\Models\FederationPeer;
use App\Models\Legislature;
use App\Models\LocalAutonomyProcess;
use App\Models\MultiJurisdictionVote;
use App\Models\OperationalPartitionExport;
use App\Models\User;
use App\Services\Federation\BallotRewrapFailed;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\OperationalBundleService;
use App\Services\Jurisdictions\LocalAutonomyService;
use App\Services\MultiJurisdictionVoteService;
use App\Services\TabulationRecorder;
use App\Services\VoteCountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CountedRaceFixture;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G5/G5a wired into the G6 governed flip). The
 * operator-locked decision #1: when authority over a subtree flips to a gaining
 * cluster, that subtree's per-election ballot keys travel WITH authority — sealed
 * to the gaining cluster (G5) and re-wrapped under its KEK, fail-closed (G5a) — so
 * its elections stay re-countable. Election keys must never be stranded on the
 * relinquishing instance.
 *
 * Before this wiring, LocalAutonomyService::finalize flipped authoritative_server_id
 * but never invoked the sealed bundle or the re-wrap, so the keys did NOT move. The
 * pins:
 *  1. a governed dual-passage flip SEALS the subtree's election keys to the gaining
 *     peer (OUTBOUND bundle), and the sealed blob is OPAQUE (k_e never in the clear);
 *  2. the gaining side opens the flip bundle, re-wraps each key under its own KEK,
 *     and REPRODUCES the certified record_hash — the election is countable again;
 *  3. a corrupted flip bundle FAILS CLOSED on the gaining side — the election is
 *     left exactly as it was, no key moves, the abort is ledgered;
 *  4. the /api/federation/flip/operational endpoint applies a real flip bundle and
 *     reports a re-wrap failure as a fail-closed 422 (never a 500, never a partial).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class AutonomyFlipRewrapsKeysTest extends TestCase
{
    use CountedRaceFixture;
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_autonomy_rewrap';

    public function test_a_governed_flip_seals_the_subtree_election_keys_to_the_gaining_peer(): void
    {
        $this->onLivePg(function () {
            [$ctx] = [$this->arrangeGovernedFlip()];

            $result = app(LocalAutonomyService::class)->finalize($ctx['process']);

            // The authority flipped to the gaining cluster (the existing G6 behaviour).
            $this->assertSame(LocalAutonomyProcess::STATUS_PASSED, $result->process->status);
            $this->assertSame(
                $ctx['gaining'],
                (string) DB::table('jurisdictions')->where('id', $ctx['jurisdiction_id'])->value('authoritative_server_id'),
                'dual passage flips the subtree authority to the gaining cluster'
            );

            // NEW: the per-election keys were SEALED to the gaining peer (the G5 half).
            $this->assertNotNull($result->sealedOperationalBundle, 'finalize must seal the subtree election keys to the gaining peer');
            $this->assertNotNull($result->bundleExport);
            $this->assertSame(1, $result->bundleExport->election_count, 'the one subtree election is in the bundle');
            $this->assertSame(OperationalPartitionExport::DIRECTION_OUTBOUND, $result->bundleExport->direction);

            // The sealed bundle is OPAQUE — k_e never appears in the clear.
            $rawKe = BallotCrypto::unwrapDataKey(
                (string) $ctx['election']->fresh()->ballot_key_wrapped,
                BallotCrypto::kekFromAppKey((string) config('app.key'))
            );
            $this->assertStringNotContainsString(base64_encode($rawKe), $result->sealedOperationalBundle, 'the sealed bundle must not leak k_e');
            $this->assertStringNotContainsString(bin2hex($rawKe), strtolower($result->sealedOperationalBundle));
        });
    }

    public function test_the_gaining_side_rewraps_and_reproduces_the_certified_count(): void
    {
        $this->onLivePg(function () {
            $ctx = $this->arrangeGovernedFlip();
            $sealed = app(LocalAutonomyService::class)->finalize($ctx['process'])->sealedOperationalBundle;
            $this->assertNotNull($sealed);

            // Simulate the gaining cluster receiving the election under a DIFFERENT
            // app key: strand ballot_key_wrapped under a foreign KEK it cannot read.
            $rawKe = BallotCrypto::unwrapDataKey(
                (string) $ctx['election']->fresh()->ballot_key_wrapped,
                BallotCrypto::kekFromAppKey((string) config('app.key'))
            );
            $foreignKek = BallotCrypto::kekFromAppKey('base64:'.base64_encode(random_bytes(32)));
            $ctx['election']->forceFill(['ballot_key_wrapped' => BallotCrypto::wrapDataKey($rawKe, $foreignKek)])->save();

            // Apply the flip bundle — re-wrap under OUR KEK, fail-closed verification.
            $export = app(OperationalBundleService::class)->openAndApply($sealed, $ctx['gaining'], (string) Str::uuid());

            $this->assertSame(OperationalPartitionExport::STATUS_APPLIED, $export->status);
            $this->assertSame(1, $export->applied_count);
            $this->assertSame(1, ElectionBallotKeyRewrap::query()->where('election_id', $ctx['election']->id)->count());

            // The gaining cluster reproduces the certified count EXACTLY — the
            // subtree's election is re-countable after authority moved.
            $reproduced = app(VoteCountingService::class)
                ->countStv(app(TabulationRecorder::class)->countInput($ctx['race']->fresh()))
                ->recordHash();
            $this->assertSame($ctx['certified_hash'], $reproduced, 'the re-wrapped key reproduces the certified record_hash on the gaining cluster');
        });
    }

    public function test_a_corrupted_flip_bundle_fails_closed_on_the_gaining_side(): void
    {
        $this->onLivePg(function () {
            $ctx = $this->arrangeGovernedFlip();
            app(LocalAutonomyService::class)->finalize($ctx['process']); // perform the real flip

            $rawKe = BallotCrypto::unwrapDataKey(
                (string) $ctx['election']->fresh()->ballot_key_wrapped,
                BallotCrypto::kekFromAppKey((string) config('app.key'))
            );

            // The election arrives stranded under a foreign KEK.
            $foreignKek = BallotCrypto::kekFromAppKey('base64:'.base64_encode(random_bytes(32)));
            $strandedWrap = BallotCrypto::wrapDataKey($rawKe, $foreignKek);
            $ctx['election']->forceFill(['ballot_key_wrapped' => $strandedWrap])->save();

            $sealed = $this->sealCorruptedBundleToSelf($ctx['identity'], $ctx['election'], $rawKe);

            $threw = false;
            try {
                app(OperationalBundleService::class)->openAndApply($sealed);
            } catch (BallotRewrapFailed) {
                $threw = true;
            }
            $this->assertTrue($threw, 'a corrupted flip bundle must fail the apply closed');

            // ATOMIC fail-closed: the election is untouched, no key moved, the abort
            // is ledgered.
            $this->assertSame($strandedWrap, (string) Election::query()->find($ctx['election']->id)->ballot_key_wrapped);
            $this->assertSame(0, ElectionBallotKeyRewrap::query()->where('election_id', $ctx['election']->id)->count());
            $this->assertSame(
                1,
                OperationalPartitionExport::query()
                    ->where('status', OperationalPartitionExport::STATUS_FAILED)
                    ->where('direction', OperationalPartitionExport::DIRECTION_INBOUND)->count(),
                'the aborted flip-bundle apply is recorded as a failed inbound bundle'
            );
        });
    }

    public function test_a_wrong_length_key_in_the_flip_bundle_fails_closed_not_500(): void
    {
        $this->onLivePg(function () {
            $ctx = $this->arrangeGovernedFlip();
            app(LocalAutonomyService::class)->finalize($ctx['process']);

            $rawKe = BallotCrypto::unwrapDataKey(
                (string) $ctx['election']->fresh()->ballot_key_wrapped,
                BallotCrypto::kekFromAppKey((string) config('app.key'))
            );
            $foreignKek = BallotCrypto::kekFromAppKey('base64:'.base64_encode(random_bytes(32)));
            $strandedWrap = BallotCrypto::wrapDataKey($rawKe, $foreignKek);
            $ctx['election']->forceFill(['ballot_key_wrapped' => $strandedWrap])->save();

            // A k_e one byte short — valid base64 (not `false`), so it clears the
            // decode check, but wrapDataKey would throw InvalidArgumentException BEFORE
            // adopt()'s transaction. The apply must still fail CLOSED (a clean
            // BallotRewrapFailed + ledgered abort), never a raw 500 with no record.
            $shortKey = substr($rawKe, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES - 1);
            $payload = (string) json_encode([
                'schema'               => 'cga.operational-bundle.v1',
                'root_jurisdiction_id' => (string) $ctx['election']->jurisdiction_id,
                'from_server_id'       => $ctx['identity']->serverId(),
                'to_server_id'         => $ctx['identity']->serverId(),
                'election_keys'        => [
                    ['election_id' => (string) $ctx['election']->id, 'k_e' => base64_encode($shortKey)],
                ],
            ], JSON_UNESCAPED_SLASHES);
            $sealed = InstanceIdentityService::sealTo($ctx['identity']->publicKey(), $payload);

            $threw = false;
            try {
                app(OperationalBundleService::class)->openAndApply($sealed);
            } catch (BallotRewrapFailed) {
                $threw = true;
            }
            $this->assertTrue($threw, 'a wrong-length k_e must fail closed as BallotRewrapFailed, never a raw 500');

            $this->assertSame($strandedWrap, (string) Election::query()->find($ctx['election']->id)->ballot_key_wrapped);
            $this->assertSame(0, ElectionBallotKeyRewrap::query()->where('election_id', $ctx['election']->id)->count());
            $this->assertSame(
                1,
                OperationalPartitionExport::query()
                    ->where('status', OperationalPartitionExport::STATUS_FAILED)
                    ->where('direction', OperationalPartitionExport::DIRECTION_INBOUND)->count(),
                'the wrong-length-key abort is ledgered as a failed inbound bundle, not dropped'
            );
        });
    }

    public function test_the_receive_operational_endpoint_applies_a_flip_bundle_and_fails_closed(): void
    {
        $this->onLivePg(function () {
            $ctx = $this->arrangeGovernedFlip();
            $sealed = app(LocalAutonomyService::class)->finalize($ctx['process'])->sealedOperationalBundle;
            $this->assertNotNull($sealed);

            // Strand under a foreign KEK so the re-wrap has real work to do.
            $rawKe = BallotCrypto::unwrapDataKey(
                (string) $ctx['election']->fresh()->ballot_key_wrapped,
                BallotCrypto::kekFromAppKey((string) config('app.key'))
            );
            $foreignKek = BallotCrypto::kekFromAppKey('base64:'.base64_encode(random_bytes(32)));
            $ctx['election']->forceFill(['ballot_key_wrapped' => BallotCrypto::wrapDataKey($rawKe, $foreignKek)])->save();

            // HAPPY PATH — the endpoint applies the sealed flip bundle.
            $ok = app(FlipController::class)->receiveOperational(
                $this->signedRequest(['sealed' => $sealed], $ctx['peer']),
                app(OperationalBundleService::class),
            );
            $this->assertSame(200, $ok->getStatusCode());
            $this->assertSame(OperationalPartitionExport::STATUS_APPLIED, $ok->getData()->status);
            $this->assertSame(1, $ok->getData()->applied_count);

            // FAIL-CLOSED PATH — a corrupted bundle returns 422 (never 500, never partial).
            $ctx['election']->forceFill(['ballot_key_wrapped' => BallotCrypto::wrapDataKey($rawKe, $foreignKek)])->save();
            $bad = $this->sealCorruptedBundleToSelf($ctx['identity'], $ctx['election'], $rawKe);

            $refused = app(FlipController::class)->receiveOperational(
                $this->signedRequest(['sealed' => $bad], $ctx['peer']),
                app(OperationalBundleService::class),
            );
            $this->assertSame(422, $refused->getStatusCode());
            $this->assertSame('rewrap_failed', $refused->getData()->status);
        });
    }

    /**
     * Arrange a real, dual-passed governed autonomy flip: a counted STV election in
     * a board-free leaf with a parent + a seated government + residents, a
     * self-addressed gaining peer (so the in-test gaining side can open the bundle),
     * both meters passed. Returns the open process ready to finalize() + handles.
     *
     * @return array{process: LocalAutonomyProcess, election: Election, race: \App\Models\ElectionRace, certified_hash: string, jurisdiction_id: string, gaining: string, peer: FederationPeer, identity: InstanceIdentityService}
     */
    private function arrangeGovernedFlip(): array
    {
        $jurisdictionId = $this->flippableLeaf();
        [$election, $race, $certifiedHash] = $this->buildCountedRace($jurisdictionId);

        // Seat the government (buildCountedRace left the legislature FORMING).
        $legislature = Legislature::query()->where('jurisdiction_id', $jurisdictionId)->whereNull('deleted_at')->firstOrFail();
        $legislature->forceFill(['status' => Legislature::STATUS_ACTIVE])->save();

        $this->seedResidents($jurisdictionId, 3);

        $identity = app(InstanceIdentityService::class);
        $identity->ensureIdentity();

        $gaining = (string) Str::uuid();
        $peer = FederationPeer::create([
            'server_id'  => $gaining,
            'name'       => 'gaining-cluster',
            'url'        => 'https://gaining.test',
            'public_key' => $identity->publicKey(),
            'status'     => FederationPeer::STATUS_TRUST_ESTABLISHED,
            'relation'   => FederationPeer::RELATION_SOVEREIGN,
        ]);

        $svc = app(LocalAutonomyService::class);
        $process = $svc->open($legislature, $gaining);
        $svc->markPromotingSupermajority($process, 3); // population 3 → supermajority 2 → met
        $this->consentParent($process);                 // parent MJV passes (unanimity of 1)

        return [
            'process'        => $process->refresh(),
            'election'       => $election,
            'race'           => $race,
            'certified_hash' => $certifiedHash,
            'jurisdiction_id' => $jurisdictionId,
            'gaining'        => $gaining,
            'peer'           => $peer,
            'identity'       => $identity,
        ];
    }

    /** Hand-seal a flip bundle to OURSELVES carrying a CORRUPTED k_e (one bit flipped). */
    private function sealCorruptedBundleToSelf(InstanceIdentityService $identity, Election $election, string $rawKe): string
    {
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

        return InstanceIdentityService::sealTo($identity->publicKey(), $payload);
    }

    /** Build a federation.signed-shaped request with the peer already resolved. */
    private function signedRequest(array $body, FederationPeer $peer): Request
    {
        $request = Request::create('/api/federation/flip/operational', 'POST', content: (string) json_encode($body));
        $request->attributes->set('peer', $peer);

        return $request;
    }

    /** A board-free leaf with a parent and no legislature — room to seat a government + an election board. */
    private function flippableLeaf(): string
    {
        $id = DB::table('jurisdictions as j')
            ->whereNotNull('j.parent_id')
            ->whereNull('j.deleted_at')
            ->whereNotExists(fn ($q) => $q->from('jurisdictions as c')->whereColumn('c.parent_id', 'j.id')->whereNull('c.deleted_at'))
            ->whereNotExists(fn ($q) => $q->from('legislatures as l')->whereColumn('l.jurisdiction_id', 'j.id')->whereNull('l.deleted_at'))
            ->whereNotExists(fn ($q) => $q->from('election_boards as b')->whereColumn('b.jurisdiction_id', 'j.id')->where('b.status', 'active')->whereNull('b.deleted_at'))
            ->value('j.id');

        if ($id === null) {
            $this->markTestSkipped('Live DB has no board-free leaf with a parent and no legislature.');
        }

        return (string) $id;
    }

    private function seedResidents(string $jurisdictionId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $user = User::create([
                'name'              => 'Autonomy Resident '.Str::uuid(),
                'email'             => 'autonomy-'.Str::uuid().'@test.invalid',
                'password'          => Str::random(32),
                'terms_accepted_at' => now(),
            ]);

            DB::table('residency_confirmations')->insert([
                'id'              => (string) Str::uuid(),
                'user_id'         => $user->id,
                'jurisdiction_id' => $jurisdictionId,
                'days_confirmed'  => 30,
                'confirmed_at'    => now(),
                'is_active'       => true,
                'depth'           => 0,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    private function consentParent(LocalAutonomyProcess $process): void
    {
        $mjv = MultiJurisdictionVote::query()->findOrFail($process->parent_process_id);
        app(MultiJurisdictionVoteService::class)->recordConsent($mjv, (string) $process->parent_jurisdiction_id, true);
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
