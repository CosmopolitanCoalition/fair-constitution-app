<?php

namespace App\Services\Judiciary;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\Committee;
use App\Models\CommitteeSeat;
use App\Models\JudicialNomination;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\Legislature;
use App\Models\User;
use App\Services\ChamberVoteService;
use App\Services\PublicRecordService;
use Illuminate\Support\Facades\DB;

/** Operator ruling 2026-09-13: nomination authorization precedes source-house confirmation. */
final class JudicialNominationService
{
    public const NOMINATE_FORM = 'F-LEG-037';

    public const DESIGNATE_FORM = 'F-LEG-038';

    public const KINDS = ['judicial_nomination', 'judicial_committee_designation'];

    public function __construct(private ChamberVoteService $votes, private JudicialSeatService $seats, private PublicRecordService $records) {}

    public function propose(?User $actor, array $data, bool $designation = false): array
    {
        return DB::transaction(function () use ($actor, $data, $designation) {
            $data = validator($data, ['judiciary_id' => 'required|uuid', 'legislature_id' => 'required|uuid',
                'seat_id' => $designation ? 'nullable' : 'required|uuid', 'committee_id' => $designation ? 'required|uuid' : 'nullable',
                'nominee_user_id' => $designation ? 'nullable' : 'required|uuid', 'statement' => 'required|string|max:10000'])->validate();
            $court = Judiciary::query()->whereKey($data['judiciary_id'])->lockForUpdate()->firstOrFail();
            $source = $this->source($court);
            $leg = Legislature::query()->findOrFail($data['legislature_id']);
            $form = $designation ? self::DESIGNATE_FORM : self::NOMINATE_FORM;
            $member = ChamberActor::member($actor, $leg->id, $form);
            $payload = ['judiciary_id' => (string) $court->id, 'source_legislature_id' => (string) $source->id,
                'statement' => trim($data['statement']), 'proposer_user_id' => (string) $actor->id];
            $this->require($payload['statement'] !== '', 'A public statement is required.');
            if ($designation) {
                $this->require($court->nomination_mode === Judiciary::NOMINATION_COMMITTEE && $leg->id === $source->id,
                    'Only this court’s creating legislature may designate its judicial committee.');
                $committee = $this->committee($data['committee_id'], $source->id);
                $payload += ['committee_id' => $committee->id, 'previous_designation_vote_id' => $court->judicial_committee_vote_id];
                $bodyType = 'legislature';
                $bodyId = $source->id;
                $type = 'judicial_committee_designate';
            } else {
                $seat = JudicialSeat::query()->where('judiciary_id', $court->id)->whereKey($data['seat_id'])->lockForUpdate()->firstOrFail();
                $this->vacant($seat);
                $this->nominee($data['nominee_user_id'], $court->jurisdiction_id);
                $payload += ['seat_id' => $seat->id, 'nominee_user_id' => $data['nominee_user_id'],
                    'prior_nomination_id' => $this->priorNomination($seat), 'mode' => $court->nomination_mode,
                    'nominating_jurisdiction_id' => $seat->nominating_jurisdiction_id];
                if ($court->nomination_mode === Judiciary::NOMINATION_CONSTITUENT) {
                    $this->constituent($court, $seat, $leg);
                    $bodyType = 'legislature';
                    $bodyId = $leg->id;
                    $type = 'judicial_nominate';
                } else {
                    $this->require($court->nomination_mode === Judiciary::NOMINATION_COMMITTEE && $seat->seat_class === JudicialSeat::CLASS_COMMITTEE_NOMINATED
                        && $leg->id === $source->id, 'This is not a committee-nominated seat of this legislature.');
                    $committee = $this->designatedCommittee($court);
                    $this->require($this->committeeMember($committee->id, $member->id), 'Any serving member of the designated committee may propose, including its chair.');
                    $payload += ['committee_id' => $committee->id, 'designation_vote_id' => $court->judicial_committee_vote_id];
                    $bodyType = 'committee';
                    $bodyId = $committee->id;
                    $type = 'judicial_committee_nominate';
                }
            }
            $existing = ChamberVoteProposal::query()->where('payload->judiciary_id', $court->id)->where('status', 'open')
                ->where('proposal_kind', self::KINDS[$designation ? 1 : 0])->where('proposed_by_member_id', $member->id)
                ->where('payload', json_encode($payload))->first();
            if ($existing) {
                return ['proposal_id' => $existing->id, 'vote_id' => $existing->vote_id, 'judiciary_id' => $court->id, 'legislature_id' => $leg->id];
            }
            $proposal = ChamberVoteProposal::create(['legislature_id' => $leg->id, 'proposal_kind' => self::KINDS[$designation ? 1 : 0],
                'payload' => $payload, 'proposed_by_member_id' => $member->id, 'status' => 'open']);
            $vote = $this->votes->open($bodyType, $bodyId, $type, $proposal, $bodyType === 'committee' ? 'committee' : 'floor', opener: $member);
            $proposal->update(['vote_id' => $vote->id]);

            return ['proposal_id' => $proposal->id, 'vote_id' => $vote->id, 'judiciary_id' => $court->id, 'legislature_id' => $leg->id];
        });
    }

    /** Exact vote-to-proposal binding, including failure. No unrelated vote may resolve this act. */
    public function assertVote(ChamberVoteProposal $proposal, ChamberVote $vote, string $outcome): void
    {
        $this->assertVoteIdentity($proposal, $vote);
        $this->require($vote->status === 'closed' && $vote->outcome === $outcome && in_array($outcome, ['adopted', 'failed'], true),
            'The judicial proposal requires a completed authorization vote.');
    }

    public function assertVoteIdentity(ChamberVoteProposal $proposal, ChamberVote $vote): void
    {
        $p = $proposal->payload;
        $designation = $proposal->proposal_kind === self::KINDS[1];
        $committee = ! $designation && ($p['mode'] ?? null) === 'committee';
        $type = $designation ? 'judicial_committee_designate' : ($committee ? 'judicial_committee_nominate' : 'judicial_nominate');
        $leg = Legislature::query()->find($proposal->legislature_id);
        $this->require($leg !== null && $vote->votable_type === 'chamber_vote_proposal' && $vote->votable_id === $proposal->id
            && $proposal->vote_id === $vote->id && $vote->legislature_id === $leg->id && $vote->jurisdiction_id === $leg->jurisdiction_id
            && $vote->body_type === ($committee ? 'committee' : 'legislature') && $vote->body_id === ($committee ? ($p['committee_id'] ?? null) : $leg->id)
            && $vote->stage === ($committee ? 'committee' : 'floor') && $vote->vote_type === $type && $vote->vote_method === 'yes_no'
            && $vote->threshold_basis === ($designation || $committee ? 'supermajority' : 'majority'),
            'The recorded vote does not authorize this judicial proposal.');
    }

    public function adopt(ChamberVoteProposal $proposal, ChamberVote $vote): array
    {
        $this->assertVote($proposal, $vote, 'adopted');
        $court = Judiciary::query()->whereKey($proposal->payload['judiciary_id'])->lockForUpdate()->firstOrFail();
        $this->assertPending($proposal, $court);
        $p = $proposal->payload;
        if ($proposal->proposal_kind === self::KINDS[1]) {
            $court->update(['judicial_committee_id' => $p['committee_id'], 'judicial_committee_vote_id' => $vote->id]);
            $result = ['judiciaries', $court->id];
        } else {
            $seat = JudicialSeat::query()->whereKey($p['seat_id'])->lockForUpdate()->firstOrFail();
            // The court lock serializes competing proposals; the seat lock protects the consent handoff.
            $this->vacant($seat);
            $nomination = $p['mode'] === 'committee'
                ? $this->seats->committeeNominate($seat, $p['nominee_user_id'], $p['proposer_user_id'], $p['statement'])
                : $this->seats->nominate($seat, $p['nominee_user_id'], $p['nominating_jurisdiction_id'], $p['proposer_user_id'], $p['statement']);
            $result = ['judicial_nominations', $nomination['nomination_id']];
        }
        $this->records->publish('act', $proposal->proposal_kind === self::KINDS[1] ? 'Judicial committee designated' : 'Judicial nomination authorized',
            $p['statement'], ['actor_user_id' => $p['proposer_user_id'], 'jurisdiction_id' => $vote->jurisdiction_id,
                'legislature_id' => $proposal->legislature_id, 'via_form' => $proposal->proposal_kind === self::KINDS[1] ? self::DESIGNATE_FORM : self::NOMINATE_FORM,
                'subject_type' => 'chamber_vote_proposals', 'subject_id' => $proposal->id]);

        return $result;
    }

    /** Also supplies a read-only explanation when a pending proposal has become obsolete. */
    public function assertPending(ChamberVoteProposal $proposal, Judiciary $court): void
    {
        $source = $this->source($court);
        $p = $proposal->payload;
        $this->require(($p['source_legislature_id'] ?? null) === $source->id && ($p['judiciary_id'] ?? null) === $court->id,
            'The creating legislature has changed since this proposal was filed.');
        if ($proposal->proposal_kind === self::KINDS[1]) {
            $this->require($court->nomination_mode === 'committee' && $proposal->legislature_id === $source->id
                && ($p['previous_designation_vote_id'] ?? null) === $court->judicial_committee_vote_id, 'A newer committee designation has superseded this proposal.');
            $this->committee($p['committee_id'], $source->id);

            return;
        }
        $seat = JudicialSeat::query()->where('judiciary_id', $court->id)->find($p['seat_id']);
        $this->require($seat !== null, 'The proposed court seat is unavailable.');
        $this->vacant($seat);
        $this->require($p['mode'] === $court->nomination_mode && ($p['prior_nomination_id'] ?? null) === $this->priorNomination($seat),
            'Another nomination has superseded this proposal. File a new proposal for the current vacancy.');
        $this->nominee($p['nominee_user_id'], $court->jurisdiction_id);
        if ($p['mode'] === 'committee') {
            $committee = $this->designatedCommittee($court);
            $this->require($seat->seat_class === JudicialSeat::CLASS_COMMITTEE_NOMINATED && $proposal->legislature_id === $source->id
                && $p['committee_id'] === $committee->id && $p['designation_vote_id'] === $court->judicial_committee_vote_id,
                'The designated judicial committee has changed. File a new nomination proposal.');
        } else {
            $leg = Legislature::query()->find($proposal->legislature_id);
            $this->require($leg !== null && $p['nominating_jurisdiction_id'] === $seat->nominating_jurisdiction_id, 'The nominating legislature or seat allocation has changed.');
            $this->constituent($court, $seat, $leg);
        }
    }

    public function source(Judiciary $court): Legislature
    {
        $this->require($court->type === 'appointed' && in_array($court->status, ['creating', 'appointed', 'conversion_voted', 'reverted'], true),
            'Nomination requires an active appointed court or a court being created.');
        $leg = Legislature::query()->find($court->source_legislature_id);
        $this->require($leg !== null && $leg->jurisdiction_id === $court->jurisdiction_id && in_array($leg->status, ['active', 'forming'], true),
            'The court’s creating legislature is unavailable.');

        return $leg;
    }

    public function designatedCommittee(Judiciary $court): Committee
    {
        $this->require($court->judicial_committee_id !== null && $court->judicial_committee_vote_id !== null,
            'The creating legislature must first designate a judicial committee by a recorded supermajority act.');

        return $this->committee($court->judicial_committee_id, $court->source_legislature_id);
    }

    public function committeeMember(string $committeeId, string $memberId): bool
    {
        return CommitteeSeat::query()->where('committee_id', $committeeId)->where('member_id', $memberId)->live()->exists();
    }

    private function committee(string $id, string $legislature): Committee
    {
        $c = Committee::query()->where('legislature_id', $legislature)->where('status', '!=', 'dissolved')->find($id);
        $this->require($c !== null, 'Choose an existing, non-dissolved committee of the creating legislature.');

        return $c;
    }

    private function constituent(Judiciary $court, JudicialSeat $seat, Legislature $leg): void
    {
        $this->require($seat->seat_class === JudicialSeat::CLASS_CONSTITUENT_NOMINATED
            && $leg->jurisdiction_id === $seat->nominating_jurisdiction_id && in_array($leg->status, ['active', 'forming'], true)
            && DB::table('jurisdictions')->where('id', $leg->jurisdiction_id)->where('parent_id', $court->jurisdiction_id)->whereNull('deleted_at')->exists(),
            'Only the current legislature of the seat’s constituent jurisdiction may authorize this nomination.');
    }

    private function nominee(string $user, string $jurisdiction): void
    {
        $this->require(User::query()->whereKey($user)->exists() && DB::table('residency_confirmations')->where('user_id', $user)
            ->where('jurisdiction_id', $jurisdiction)->where('is_active', true)->exists(), 'Choose a person with an active association in the court’s jurisdiction.');
    }

    private function vacant(JudicialSeat $seat): void
    {
        $this->require($seat->status === 'vacant' && $seat->appointment_id === null && $seat->user_id === null && $seat->term_id === null,
            'This court seat is no longer vacant.');
    }

    private function priorNomination(JudicialSeat $seat): ?string
    {
        return JudicialNomination::withTrashed()->where('seat_id', $seat->id)->orderByDesc('id')->value('id');
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new ConstitutionalViolation($message, 'Judicial nomination ruling, 2026-09-13');
        }
    }
}
