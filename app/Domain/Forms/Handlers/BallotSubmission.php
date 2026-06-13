<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\BallotBoxDelegate;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\RaceFootprint;
use App\Models\BallotEnvelope;
use App\Models\Candidacy;
use App\Models\Election;
use App\Models\ElectionRace;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * F-IND-007 — Ballot Submission, Ranked Choice (R-04).
 *
 * Validation (design §C):
 *  - election is in the ranked window (ESM-03 'ranked_open');
 *  - the voter's active association resolves into THIS race's footprint
 *    (R-04 in the race jurisdiction);
 *  - no existing envelope for (race, voter) — double-vote barrier;
 *  - rankings well-formed: a non-empty ordered list of DISTINCT candidacy
 *    UUIDs, each referencing a candidacy OF THIS RACE that is a finalist
 *    or validated write-in (non_finalists remain write-in ELIGIBLE —
 *    right to stand preserved; write-ins tabulate identically).
 *
 * Mutation: delegated to BallotBoxDelegate (WI-B2's BallotBox — the ONLY
 * writer of the secrecy pair). The delegate's return IS the audit payload:
 * participation only ({race_id, envelope_id}), never content, never the
 * ballot hash (hash + chain adjacency would re-link voter to ballot). The
 * voter's receipt travels out-of-band — see the BallotBoxDelegate
 * docblock for the integration contract.
 */
class BallotSubmission implements FormHandler
{
    /** Candidacy statuses a ranking may lawfully reference. */
    public const RANKABLE_STATUSES = [
        Candidacy::STATUS_FINALIST,
        Candidacy::STATUS_NON_FINALIST, // write-in eligible
        Candidacy::STATUS_VALIDATED,
        Candidacy::STATUS_IN_POOL,
    ];

    public function __construct(
        private readonly BallotBoxDelegate $ballotBox,
    ) {
    }

    public function module(): string
    {
        return 'elections';
    }

    public function event(): string
    {
        return 'ballot.committed';
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
        $rankings = self::assertWellFormedRankings($payload['rankings'] ?? null);

        $race = ElectionRace::query()->find($payload['race_id'] ?? null);

        if ($race === null) {
            throw new ConstitutionalViolation(
                'F-IND-007 targets an unknown race.',
                'CGA Forms Catalog (F-IND-007)'
            );
        }

        $electionStatus = Election::query()->whereKey($race->election_id)->value('status');

        if ($electionStatus !== Election::STATUS_RANKED_OPEN) {
            throw new ConstitutionalViolation(
                "The ranked window is not open for this election (status: {$electionStatus}).",
                'Art. II §2'
            );
        }

        // System filings cannot vote — a ballot belongs to a person.
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'A ballot must be cast by an individual voter, never the system.',
                'Art. II §2'
            );
        }

        $userId = (string) $actor->getKey();

        // Phase D (PHASE_D_DESIGN_organizations §C.1): org-board races
        // gate on CLASS membership (owners/workers, one-member-one-vote
        // within the class — stakes are NEVER vote weights); residents
        // races keep the Art. I association footprint. Ballot machinery
        // (envelope/commitment/secrecy) is identical either way.
        if ($race->electorate_type !== ElectionRace::ELECTORATE_RESIDENTS) {
            $eligible = app(\App\Services\Organizations\OrgElectorateService::class)->isEligible($userId, $race);

            if (! $eligible) {
                throw new ConstitutionalViolation(
                    'no_class_membership — this board race belongs to the '
                    . ($race->electorate_type === ElectionRace::ELECTORATE_WORKERS ? 'worker' : 'owner')
                    . ' class (Art. III §6).',
                    'Art. III §6'
                );
            }
        } elseif (! RaceFootprint::userInFootprint($userId, $race)) {
            throw new ConstitutionalViolation(
                'Your active residency association does not resolve into this race — voting follows '
                . 'jurisdictional association (Art. I).',
                'Art. I'
            );
        }

        $alreadyVoted = BallotEnvelope::query()
            ->where('race_id', (string) $race->id)
            ->where('user_id', $userId)
            ->where('kind', 'ranked')
            ->exists();

        if ($alreadyVoted) {
            throw new ConstitutionalViolation(
                'A ballot envelope already exists for this race — one person, one vote.',
                'Art. II §2'
            );
        }

        $this->assertRankingsReferenceThisRace($race, $rankings);

        // The secrecy boundary: BallotBox writes the envelope + encrypted
        // ballot pair and returns the chain-safe participation payload.
        return $this->ballotBox->commit($actor, $race, $rankings);
    }

    /**
     * Pure shape guard (pinned DB-free): a non-empty ordered list of
     * distinct UUID strings, no duplicate ranks.
     *
     * @return list<string>
     */
    public static function assertWellFormedRankings(mixed $rankings): array
    {
        if (! is_array($rankings) || $rankings === [] || ! array_is_list($rankings)) {
            throw new ConstitutionalViolation(
                'Rankings must be a non-empty ordered list of candidacy ids.',
                'CGA Forms Catalog (F-IND-007)'
            );
        }

        $clean = [];

        foreach ($rankings as $entry) {
            if (! is_string($entry) || ! Str::isUuid($entry)) {
                throw new ConstitutionalViolation(
                    'Each ranking entry must be a candidacy UUID.',
                    'CGA Forms Catalog (F-IND-007)'
                );
            }

            $clean[] = strtolower($entry);
        }

        if (count($clean) !== count(array_unique($clean))) {
            throw new ConstitutionalViolation(
                'Rankings may not repeat a candidacy (no duplicate ranks).',
                'CGA Forms Catalog (F-IND-007)'
            );
        }

        return $clean;
    }

    /** @param list<string> $rankings */
    private function assertRankingsReferenceThisRace(ElectionRace $race, array $rankings): void
    {
        $rankable = Candidacy::query()
            ->where('race_id', (string) $race->id)
            ->whereIn('status', self::RANKABLE_STATUSES)
            ->whereIn('id', $rankings)
            ->pluck('id')
            ->map(fn ($id) => strtolower((string) $id))
            ->all();

        $unknown = array_diff($rankings, $rankable);

        if ($unknown !== []) {
            throw new ConstitutionalViolation(
                'Rankings may only reference finalist or validated (write-in) candidacies of this race; '
                . 'offending ids: ' . implode(', ', $unknown) . '.',
                'CGA Forms Catalog (F-IND-007)'
            );
        }
    }
}
