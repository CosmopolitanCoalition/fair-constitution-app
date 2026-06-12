<?php

namespace App\Domain\Ballots;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Ballot;
use App\Models\BallotEnvelope;
use App\Models\Election;
use App\Models\ElectionRace;
use App\Models\User;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * THE single writer of the secrecy boundary (design §B.5.1) — the only
 * code unit allowed to insert into `ballot_envelopes` or `ballots`.
 * BallotSecrecyTest greps the codebase for rogue writers; adding another
 * insert path is a constitutional violation, not a refactor.
 *
 * The write pipeline — ONE transaction, in order:
 *   1. envelope insert (voter-linked, content-free) — the DB unique
 *      (race_id, user_id, kind) is the double-vote authority; a unique
 *      violation surfaces as DoubleVoteException (citation Art. II §2).
 *   2. per-election data key ensured (generated under row lock on first
 *      ballot if WI-B3's ranked_open hook hasn't already — two concurrent
 *      first ballots can never mint two keys).
 *   3. ballot insert (content, voter-free): sodium-encrypted canonical
 *      rankings, fresh 32-byte salt, ballot_hash = sha256(salt ‖ canonical),
 *      HOUR-TRUNCATED cast_bucket — deliberately NO other timestamp
 *      anywhere on the row (wall-clock insert time is a linking channel).
 * Returns the {ballot_hash, salt} receipt — shown once, stored nowhere
 * voter-linked.
 *
 * AUDIT DISCIPLINE (§B.5.6) — exactly ONE chain entry per ballot, payload
 * participation ONLY ({race_id, envelope_id}): NEVER the ballot_hash
 * (hash + chain-seq adjacency would re-link voter↔ballot), NEVER rankings,
 * never the salt. Two entry points keep that exact:
 *   - commit()          — standalone/system callers: appends the
 *                         'ballot.committed' entry itself.
 *   - commitForEngine() — the F-IND-007 path via EngineBallotBox
 *                         (BallotBoxDelegate): returns the chain-safe
 *                         payload WITHOUT appending — ConstitutionalEngine
 *                         records that payload as the handler's
 *                         'ballot.committed' entry (one entry, same shape).
 *
 * decryptForCount() — TRUST BOUNDARY: this method takes no actor and does
 * no authorization. Anything holding the app key + DB can decrypt ballots
 * (§B.5.2 states this plainly: encryption protects against DB
 * exfiltration, not the server operator). The boundary is therefore
 * code-shaped, not credential-shaped: its only legitimate callers are the
 * tabulation pipeline (TabulateElectionJob / CountbackService, WI-B5) and
 * audit re-runs — never a controller, never anything with an HTTP request
 * in its call stack. Decrypted rankings exist only in memory and flow
 * straight into BallotSet grouping, which strips even ordering.
 */
class BallotBox
{
    public function __construct(
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Commit one ranked ballot. See class docblock for the exact sequence.
     *
     * @param  list<string>  $rankings  ordered candidacy UUIDs, most-preferred
     *                                  first (write-ins included — any validated
     *                                  candidacy id, tabulated identically)
     *
     * @throws DoubleVoteException        second ballot in the same race/kind
     * @throws ConstitutionalViolation    ranked window not open
     * @throws \InvalidArgumentException  malformed rankings (not UUIDs, repeats, empty)
     */
    public function commit(User $voter, ElectionRace $race, array $rankings, string $kind = Ballot::KIND_RANKED): BallotReceipt
    {
        return DB::transaction(function () use ($voter, $race, $rankings, $kind): BallotReceipt {
            [$receipt, $payload] = $this->write($voter, $race, $rankings, $kind);

            // Participation-only audit entry (design §B.5.6).
            $this->audit->append(
                module: 'elections',
                event: 'ballot.committed',
                payload: $payload,
                ref: 'F-IND-007',
                actorId: $voter->id,
                jurisdictionId: $race->jurisdiction_id,
            );

            return $receipt;
        });
    }

    /**
     * The F-IND-007 engine path (called via EngineBallotBox): same write
     * pipeline, NO audit append — the returned payload IS the chain entry
     * (ConstitutionalEngine appends it as the handler's 'ballot.committed'
     * event inside the same engine transaction). Appending here too would
     * double-enter every ballot.
     *
     * @param  list<string>  $rankings
     * @return array{0: BallotReceipt, 1: array{race_id: string, envelope_id: string}}
     */
    public function commitForEngine(User $voter, ElectionRace $race, array $rankings): array
    {
        return DB::transaction(fn (): array => $this->write($voter, $race, $rankings, Ballot::KIND_RANKED));
    }

    /**
     * Steps 1–3 of the class docblock. Audit is the CALLER's duty — exactly
     * one participation entry per ballot, via one of the two public paths.
     *
     * @return array{0: BallotReceipt, 1: array{race_id: string, envelope_id: string}}
     */
    private function write(User $voter, ElectionRace $race, array $rankings, string $kind): array
    {
        if ($kind !== Ballot::KIND_RANKED) {
            throw new \InvalidArgumentException(
                'Phase B commits ranked ballots only — referendum ballots arrive with Phase C (F-IND-008).'
            );
        }

        // Canonicalize (and validate) BEFORE any state is touched.
        $canonical = BallotCrypto::canonicalRankings($rankings);

        $election = $race->election()->firstOrFail();

        // Mechanism-level defense in depth: the form handler (WI-B4) gates
        // the window constitutionally; the box refuses out-of-window writes
        // even when called directly.
        if ($election->status !== Election::STATUS_RANKED_OPEN) {
            throw new ConstitutionalViolation(
                "Ballots may only be committed while the ranked window is open (election status: {$election->status}).",
                'Art. II §2'
            );
        }

        // 1. Envelope — the DB unique is the double-vote authority.
        try {
            $envelope = BallotEnvelope::create([
                'race_id'      => $race->id,
                'user_id'      => $voter->id,
                'kind'         => $kind,
                'committed_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new DoubleVoteException($kind);
        }

        // 2. Per-election data key.
        $dataKey = $this->ensureElectionKey($election);

        // 3. Anonymous ballot row.
        $salt = BallotCrypto::newSaltHex();
        $hash = BallotCrypto::commitmentHash($salt, $canonical);

        Ballot::create([
            // EXPLICIT random v4 — Ballot uses HasUuids, whose default
            // client-generated id is ORDERED (time-encoding); a
            // time-ordered PK would leak precise insert time and defeat
            // cast_bucket truncation (design: "random — no sequence").
            'id'                => (string) Str::uuid(),
            'race_id'           => $race->id,
            'kind'              => $kind,
            'payload_encrypted' => BallotCrypto::encryptCanonical($canonical, $dataKey),
            'salt'              => $salt,
            'ballot_hash'       => $hash,
            'cast_bucket'       => CarbonImmutable::now('UTC')->startOfHour(),
            'counted'           => false,
        ]);

        return [
            new BallotReceipt($hash, $salt),
            ['race_id' => $race->id, 'envelope_id' => $envelope->id],
        ];
    }

    /**
     * Decrypt a race's ranked ballots for tabulation — see the TRUST
     * BOUNDARY note in the class docblock. Yields one ordered candidacy-id
     * list per ballot, in ballot_hash order (deterministic and decorrelated
     * from insertion order; BallotSet grouping erases order anyway).
     *
     * Every ballot's commitment is re-verified on the way out
     * (sha256(salt ‖ canonical) must equal the stored ballot_hash) — a
     * mismatch means row tampering or key confusion and aborts the count.
     *
     * @return Generator<int, list<string>>
     */
    public function decryptForCount(ElectionRace $race): Generator
    {
        $query = Ballot::query()
            ->where('race_id', $race->id)
            ->where('kind', Ballot::KIND_RANKED)
            ->orderBy('ballot_hash');

        if (! $query->clone()->exists()) {
            return;
        }

        $election = $race->election()->firstOrFail();

        if ($election->ballot_key_wrapped === null) {
            throw new RuntimeException(
                "Race {$race->id} has ballots but election {$election->id} has no wrapped ballot key — key was lost or rows were written outside BallotBox."
            );
        }

        $dataKey = BallotCrypto::unwrapDataKey($election->ballot_key_wrapped, self::kek());

        foreach ($query->cursor() as $ballot) {
            $canonical = BallotCrypto::decryptToCanonical($ballot->payload_encrypted, $dataKey);

            if (! hash_equals($ballot->ballot_hash, BallotCrypto::commitmentHash($ballot->salt, $canonical))) {
                throw new RuntimeException(
                    "Ballot {$ballot->id} failed commitment re-verification — tampering or key mismatch; aborting count."
                );
            }

            yield BallotCrypto::decryptRankings($ballot->payload_encrypted, $dataKey);
        }
    }

    /**
     * Ensure the election has a wrapped data key, returning the RAW key.
     *
     * The elections.ballot_key_wrapped writer (the only one): WI-B3's
     * ranked_open transition calls this once up front; commit() calls it
     * defensively per ballot. Generation runs under a row lock so two
     * concurrent first-ballots can never mint different keys (which would
     * leave earlier ballots undecryptable).
     *
     * Key generation is a state change → audited (no key material in the
     * payload, obviously).
     */
    public function ensureElectionKey(Election $election): string
    {
        $kek = self::kek();

        if ($election->ballot_key_wrapped !== null) {
            return BallotCrypto::unwrapDataKey($election->ballot_key_wrapped, $kek);
        }

        return DB::transaction(function () use ($election, $kek): string {
            $fresh = Election::query()->whereKey($election->id)->lockForUpdate()->firstOrFail();

            if ($fresh->ballot_key_wrapped !== null) {
                $election->ballot_key_wrapped = $fresh->ballot_key_wrapped;

                return BallotCrypto::unwrapDataKey($fresh->ballot_key_wrapped, $kek);
            }

            $dataKey = BallotCrypto::generateDataKey();

            $fresh->forceFill([
                'ballot_key_wrapped' => BallotCrypto::wrapDataKey($dataKey, $kek),
            ])->save();

            $election->ballot_key_wrapped = $fresh->ballot_key_wrapped;

            $this->audit->append(
                module: 'elections',
                event: 'election.ballot_key_generated',
                payload: ['election_id' => $election->id],
                ref: 'WF-CIV-04',
                actorId: null,
                jurisdictionId: $fresh->jurisdiction_id,
            );

            return $dataKey;
        });
    }

    /** KEK derived from the live app key (see BallotCrypto::kekFromAppKey). */
    private static function kek(): string
    {
        return BallotCrypto::kekFromAppKey((string) config('app.key'));
    }
}
