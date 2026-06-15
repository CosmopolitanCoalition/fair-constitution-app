<?php

namespace App\Services\Federation;

use App\Domain\Ballots\BallotCrypto;
use App\Models\Election;
use App\Models\ElectionBallotKeyRewrap;
use App\Models\ElectionRace;
use App\Models\Tabulation;
use App\Services\AuditService;
use App\Services\TabulationRecorder;
use App\Services\VoteCountingService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Re-wraps an election's ballot key to THIS (gaining) cluster on an autonomy flip
 * (Phase G, G5a) — and proves, FAIL-CLOSED, that the re-wrap preserves
 * re-countability before it commits.
 *
 * The scheme makes this surgical: ballots are encrypted under a per-election data
 * key k_e; only k_e is wrapped, under a KEK derived from the instance's app key.
 * So a re-wrap re-encrypts ONLY the 32-byte k_e — never a ballot, never a
 * ballot_envelope (voter identity). The raw k_e arrives in the encrypted G5
 * operational bundle; here we wrap it under the local KEK, then verify.
 *
 * THE FAIL-CLOSED GATE (operator-locked decision): the gaining cluster must
 * reproduce every race's CERTIFIED record_hash from the ballots re-decrypted under
 * the re-wrapped key, BEFORE the new key is allowed to stand. record_hash is a
 * pure function of the ballots + the public count (CountResult::recordHash() —
 * "byte-identical on any machine"), so a correct re-wrap reproduces it exactly and
 * a wrong/corrupted key cannot. The tentative key is written inside a transaction;
 * ANY mismatch (or a decrypt/commitment failure) throws and rolls the transaction
 * back, restoring the prior `ballot_key_wrapped` untouched.
 */
class BallotKeyRewrapService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly VoteCountingService $counter,
        private readonly TabulationRecorder $recorder,
    ) {}

    /**
     * Adopt a re-wrapped ballot key for an election on this gaining cluster.
     *
     * @param  string  $rawDataKey  the raw 32-byte per-election data key k_e,
     *                              delivered via the encrypted G5 operational bundle
     *
     * @throws BallotRewrapFailed when the re-wrapped key cannot reproduce the
     *                            certified count (fail closed — nothing is committed)
     */
    public function adopt(Election $election, string $rawDataKey, ?string $toClusterId = null, ?string $fromClusterId = null): ElectionBallotKeyRewrap
    {
        $localKek = $this->localKek();

        // Wrap k_e under THIS cluster's KEK. Throws (before any DB write) if k_e is
        // not exactly key-length — a malformed bundle never reaches the row.
        $candidate = BallotCrypto::wrapDataKey($rawDataKey, $localKek);
        $prior = $election->ballot_key_wrapped;

        $races = $election->races()->get();

        try {
            return DB::transaction(function () use ($election, $candidate, $prior, $rawDataKey, $localKek, $races, $toClusterId, $fromClusterId): ElectionBallotKeyRewrap {
                // Tentatively adopt the re-wrapped key. The count path re-reads
                // ballot_key_wrapped FROM THE ROW, so it must be persisted to verify.
                $election->forceFill(['ballot_key_wrapped' => $candidate])->save();

                $verified = [];

                foreach ($races as $race) {
                    $certified = $this->certifiedRecordHash($race);

                    if ($certified === null) {
                        continue; // an uncounted race carries no certified artifact to reproduce
                    }

                    if (! hash_equals($certified, $this->reproduce($race))) {
                        // FAIL CLOSED — abort; the transaction reverts ballot_key_wrapped.
                        throw new BallotRewrapFailed((string) $election->id, (string) $race->id, 'certified record_hash did not reproduce under the re-wrapped key');
                    }

                    $verified[(string) $race->id] = $certified;
                }

                if ($verified === []) {
                    // No certified count exists to prove re-countability against —
                    // we will not silently adopt an unverifiable key.
                    throw new BallotRewrapFailed((string) $election->id, null, 'no certified count to reproduce — re-wrap cannot be proven');
                }

                // Belt-and-suspenders: the adopted key round-trips under the local KEK.
                if (! hash_equals($rawDataKey, BallotCrypto::unwrapDataKey($candidate, $localKek))) {
                    throw new BallotRewrapFailed((string) $election->id, null, 're-wrapped key failed its round-trip');
                }

                $rewrap = ElectionBallotKeyRewrap::create([
                    'election_id'            => (string) $election->id,
                    'jurisdiction_id'        => $election->jurisdiction_id,
                    'from_cluster_id'        => $fromClusterId,
                    'to_cluster_id'          => $toClusterId,
                    'prior_wrap_fingerprint' => $prior !== null ? hash('sha256', $prior) : null,
                    'new_wrap_fingerprint'   => hash('sha256', $candidate),
                    'races_verified'         => count($verified),
                    'count_record_digest'    => $this->countRecordDigest($verified),
                    'verified_at'            => now(),
                ]);

                // Audit the re-wrap — fingerprints + the PUBLIC count digest only;
                // NEVER k_e, NEVER the wrapped blob.
                $this->audit->append(
                    module: 'federation_operational',
                    event: 'election.ballot_key_rewrapped',
                    payload: [
                        'election_id'          => (string) $election->id,
                        'races_verified'       => count($verified),
                        'count_record_digest'  => $rewrap->count_record_digest,
                        'new_wrap_fingerprint' => $rewrap->new_wrap_fingerprint,
                    ],
                    ref: 'WF-JUR-06',
                    actorId: null,
                    jurisdictionId: $election->jurisdiction_id,
                );

                return $rewrap;
            });
        } catch (BallotRewrapFailed $e) {
            // The transaction rolled back → the row's ballot_key_wrapped is the prior
            // value again. Realign the in-memory model and re-throw.
            $election->ballot_key_wrapped = $prior;

            throw $e;
        }
    }

    /**
     * Reproduce a race's record_hash from the ballots re-decrypted under the
     * (tentatively adopted) key — the same count the original tabulation ran
     * (RCV for a single seat, STV otherwise; mirrors TabulateRaceJob). A wrong key
     * surfaces as a decrypt/commitment failure here and fails the re-wrap closed.
     */
    private function reproduce(ElectionRace $race): string
    {
        try {
            $input = $this->recorder->countInput($race);

            $result = $race->seat_kind === ElectionRace::SEAT_KIND_SINGLE
                ? $this->counter->countRcv($input)
                : $this->counter->countStv($input);

            return $result->recordHash();
        } catch (Throwable $e) {
            throw new BallotRewrapFailed((string) $race->election_id, (string) $race->id, 'count did not reproduce: '.$e->getMessage());
        }
    }

    /** The race's certified record_hash — the latest complete sealed tabulation. */
    private function certifiedRecordHash(ElectionRace $race): ?string
    {
        $hash = Tabulation::query()
            ->where('race_id', $race->id)
            ->where('status', Tabulation::STATUS_COMPLETE)
            ->whereNotNull('record_hash')
            ->orderByDesc('completed_at')
            ->value('record_hash');

        return $hash !== null ? (string) $hash : null;
    }

    /**
     * A digest over the verified PUBLIC race record_hashes — the fail-closed proof
     * artifact recorded in the ledger (race-id-sorted for determinism).
     *
     * @param  array<string,string>  $verifiedByRace
     */
    private function countRecordDigest(array $verifiedByRace): string
    {
        ksort($verifiedByRace);

        $parts = [];
        foreach ($verifiedByRace as $raceId => $hash) {
            $parts[] = $raceId.':'.$hash;
        }

        return hash('sha256', implode('|', $parts));
    }

    /** This (gaining) cluster's KEK — derived from the live app key, exactly as BallotBox does. */
    private function localKek(): string
    {
        return BallotCrypto::kekFromAppKey((string) config('app.key'));
    }
}
