<?php

namespace Tests\Constitutional;

use App\Domain\Ballots\BallotCrypto;
use App\Models\Ballot;
use App\Models\BallotEnvelope;
use App\Models\Election;
use App\Models\ElectionBallotKeyRewrap;
use App\Models\ElectionRace;
use App\Models\Tabulation;
use App\Services\Federation\BallotKeyRewrapService;
use App\Services\Federation\BallotRewrapFailed;
use App\Services\TabulationRecorder;
use App\Services\VoteCountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CountedRaceFixture;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G5a ballot re-wrap). On an autonomy flip, an
 * election's per-election data key k_e is re-wrapped to the gaining cluster so its
 * ballots stay re-countable. The re-wrap re-encrypts ONLY k_e — never a ballot,
 * never a ballot_envelope — and it is FAIL-CLOSED: the gaining cluster must
 * reproduce the certified record_hash from the re-wrapped key BEFORE it commits.
 * The pins:
 *  1. a good re-wrap reproduces the certified count, commits, and leaves the
 *     ballots + voter envelopes byte-identical (only k_e's wrapper changed);
 *  2. a BAD re-wrap (corrupted k_e) fails closed — it throws, writes no ledger
 *     row, and never changes the stored ballot_key_wrapped or the certified count;
 *  3. the re-wrap service never references the voter-envelope table (secrecy).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class BallotRewrapFailsClosedTest extends TestCase
{
    use CountedRaceFixture;
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_ballot_rewrap';

    public function test_a_good_rewrap_reproduces_the_certified_count_and_commits(): void
    {
        $this->onLivePg(function () {
            [$election, $race, $certifiedHash] = $this->buildCountedRace();

            $liveKek = BallotCrypto::kekFromAppKey((string) config('app.key'));
            $rawDataKey = BallotCrypto::unwrapDataKey((string) $election->ballot_key_wrapped, $liveKek);

            // Simulate the election arriving from the OLD owner: k_e wrapped under a
            // FOREIGN KEK this gaining cluster cannot reproduce.
            $foreignKek = BallotCrypto::kekFromAppKey('base64:'.base64_encode(random_bytes(32)));
            $election->forceFill(['ballot_key_wrapped' => BallotCrypto::wrapDataKey($rawDataKey, $foreignKek)])->save();

            // Precondition: stranded — the gaining cluster cannot decrypt yet.
            $stranded = false;
            try {
                app(TabulationRecorder::class)->countInput($race->fresh());
            } catch (\Throwable) {
                $stranded = true;
            }
            $this->assertTrue($stranded, 'before the re-wrap the gaining cluster cannot decrypt the foreign-wrapped ballots');

            $ballotsBefore = $this->ballotFingerprint($race);
            $envelopesBefore = BallotEnvelope::query()->where('race_id', $race->id)->count();

            // Re-wrap under the LOCAL KEK (k_e delivered via the G5 bundle) + verify.
            $rewrap = app(BallotKeyRewrapService::class)->adopt(
                $election->fresh(),
                $rawDataKey,
                toClusterId: (string) Str::uuid(),
                fromClusterId: (string) Str::uuid(),
            );

            $this->assertSame(1, $rewrap->races_verified);
            $this->assertNotNull($rewrap->verified_at);

            // The gaining cluster now reproduces the certified count EXACTLY.
            $reproduced = app(VoteCountingService::class)
                ->countStv(app(TabulationRecorder::class)->countInput($race->fresh()))
                ->recordHash();
            $this->assertSame($certifiedHash, $reproduced, 'the re-wrapped key reproduces the certified record_hash');

            // The secrecy boundary was untouched — ballots + envelopes identical.
            $this->assertSame($ballotsBefore, $this->ballotFingerprint($race), 'ballots are never re-encrypted — only k_e is re-wrapped');
            $this->assertSame($envelopesBefore, BallotEnvelope::query()->where('race_id', $race->id)->count());

            // The ledger holds EVIDENCE only — never k_e, never the wrapped blob.
            $attrs = strtolower((string) json_encode($rewrap->getAttributes()));
            $this->assertStringNotContainsString(bin2hex($rawDataKey), $attrs, 'the ledger never stores k_e');
            $this->assertStringNotContainsString(strtolower((string) $election->fresh()->ballot_key_wrapped), $attrs, 'the ledger never stores the wrapped blob');
        });
    }

    public function test_a_bad_rewrap_fails_closed_and_never_corrupts_the_election(): void
    {
        $this->onLivePg(function () {
            [$election, $race, $certifiedHash] = $this->buildCountedRace();

            $liveKek = BallotCrypto::kekFromAppKey((string) config('app.key'));
            $rawDataKey = BallotCrypto::unwrapDataKey((string) $election->ballot_key_wrapped, $liveKek);

            // The election arrives stranded under a foreign KEK.
            $foreignKek = BallotCrypto::kekFromAppKey('base64:'.base64_encode(random_bytes(32)));
            $strandedWrap = BallotCrypto::wrapDataKey($rawDataKey, $foreignKek);
            $election->forceFill(['ballot_key_wrapped' => $strandedWrap])->save();

            $ballotsBefore = $this->ballotFingerprint($race);

            // A CORRUPTED k_e (one bit flipped) — a tampered/wrong bundle.
            $badKey = $rawDataKey;
            $badKey[0] = $badKey[0] ^ "\x01";

            $threw = false;
            try {
                app(BallotKeyRewrapService::class)->adopt($election->fresh(), $badKey);
            } catch (BallotRewrapFailed) {
                $threw = true;
            }
            $this->assertTrue($threw, 'a re-wrap that cannot reproduce the certified count must fail closed');

            // FAIL-CLOSED guarantees — nothing was changed:
            $this->assertSame(
                $strandedWrap,
                (string) Election::query()->find($election->id)->ballot_key_wrapped,
                'a failed re-wrap never changes the stored ballot_key_wrapped'
            );
            $this->assertSame(0, ElectionBallotKeyRewrap::query()->where('election_id', $election->id)->count(),
                'a failed re-wrap writes no ledger row');
            $this->assertSame($ballotsBefore, $this->ballotFingerprint($race), 'a failed re-wrap never touches the ballots');
            $this->assertSame(
                $certifiedHash,
                (string) Tabulation::query()->where('race_id', $race->id)
                    ->where('status', Tabulation::STATUS_COMPLETE)->whereNotNull('record_hash')
                    ->orderByDesc('completed_at')->value('record_hash'),
                'the certified count is untouched'
            );
        });
    }

    public function test_the_rewrap_service_never_touches_the_voter_envelope_table(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Federation/BallotKeyRewrapService.php'));

        foreach (['ballot_envelopes', 'BallotEnvelope'] as $needle) {
            $this->assertStringNotContainsString($needle, $src,
                "the re-wrap must never touch voter identity ({$needle}) — it re-wraps k_e only");
        }
    }

    /** A stable fingerprint of a race's ballot rows — proves they were never re-encrypted. */
    private function ballotFingerprint(ElectionRace $race): string
    {
        $rows = Ballot::query()
            ->where('race_id', $race->id)
            ->orderBy('ballot_hash')
            ->get(['ballot_hash', 'payload_encrypted', 'salt'])
            ->map(fn (Ballot $b) => $b->ballot_hash.'|'.$b->payload_encrypted.'|'.$b->salt)
            ->all();

        return hash('sha256', implode("\n", $rows));
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
