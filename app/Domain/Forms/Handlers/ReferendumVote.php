<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Ballots\BallotBox;
use App\Domain\Ballots\BallotReceiptHolder;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Election;
use App\Models\ReferendumQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * F-IND-008 — Referendum Vote (R-04). Mirrors F-IND-007 BallotSubmission
 * for question-scoped ballots (votes_laws §D — the Phase B storeReferendum
 * 422 stub goes live here):
 *
 *  - the question is SCHEDULED on an election whose ranked window is open;
 *  - the voter holds an active association with the QUESTION's
 *    jurisdiction (Art. I — the only gate);
 *  - double vote barred by the (question, voter) partial unique;
 *  - the {question_id, choice} payload seals under the election's wrapped
 *    key through BallotBox (the single secrecy-boundary writer); the
 *    receipt travels out-of-band via BallotReceiptHolder.
 *
 * The returned payload — the engine's single 'referendum.ballot_committed'
 * chain entry — is PARTICIPATION ONLY ({referendum_question_id,
 * envelope_id}); the engine's SENSITIVE_KEYS strips 'choice' from any
 * rejection record too.
 */
class ReferendumVote implements FormHandler
{
    public function __construct(
        private readonly BallotBox $box,
        private readonly BallotReceiptHolder $receipts,
    ) {
    }

    public function module(): string
    {
        return 'elections';
    }

    public function event(): string
    {
        return 'referendum.ballot_committed';
    }

    public function requiredRoles(): array
    {
        return ['R-04'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        // System filings cannot vote — a ballot belongs to a person.
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'A referendum ballot must be cast by an individual voter, never the system.',
                'Art. II §6'
            );
        }

        $question = ReferendumQuestion::query()->find($payload['question_id'] ?? null);

        if ($question === null) {
            throw new ConstitutionalViolation(
                'F-IND-008 targets an unknown referendum question.',
                'CGA Forms Catalog (F-IND-008)'
            );
        }

        if ($question->status !== ReferendumQuestion::STATUS_SCHEDULED || $question->election_id === null) {
            throw new ConstitutionalViolation(
                "This question is not on an open ballot (status: {$question->status}).",
                'Art. II §6'
            );
        }

        $electionStatus = Election::query()->whereKey($question->election_id)->value('status');

        if ($electionStatus !== Election::STATUS_RANKED_OPEN) {
            throw new ConstitutionalViolation(
                "The voting window is not open for this question's election (status: {$electionStatus}).",
                'Art. II §6'
            );
        }

        // Art. I — association with the question's jurisdiction is the
        // ONLY gate.
        $associated = DB::table('residency_confirmations')
            ->where('user_id', (string) $actor->getKey())
            ->where('jurisdiction_id', (string) $question->jurisdiction_id)
            ->where('is_active', true)
            ->exists();

        if (! $associated) {
            throw new ConstitutionalViolation(
                'Voting on this question requires an active association with its jurisdiction — '
                . 'voting follows jurisdictional association (Art. I).',
                'Art. I'
            );
        }

        $choice = strtolower(trim((string) ($payload['choice'] ?? '')));

        if (! in_array($choice, ['yes', 'no'], true)) {
            throw new ConstitutionalViolation(
                'A referendum ballot answers yes or no.',
                'Art. II §6 · as implemented'
            );
        }

        // The secrecy boundary: BallotBox writes the envelope + encrypted
        // ballot pair (double-vote → DoubleVoteException with citation)
        // and returns the chain-safe participation payload.
        [$receipt, $participation] = $this->box->commitReferendumForEngine($actor, $question, $choice);

        // Out-of-band receipt — read once by the HTTP layer, never chained.
        $this->receipts->put($receipt);

        return $participation;
    }
}
