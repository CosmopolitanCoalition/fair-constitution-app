<?php

namespace App\Support;

use App\Http\Presenters\CandidacyPanel;
use App\Http\Presenters\ChamberVotePresenter;
use App\Models\Appointment;
use App\Models\ChamberVote;
use App\Models\JudicialNomination;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\User;
use App\Models\VoteCast;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** One court's public nomination history and the existing legislative consent actions. */
final class JudicialConfirmationDirectory
{
    public function __construct(private ChamberVotePresenter $presenter) {}

    public function page(Request $request, Judiciary $court, ?User $viewer): array
    {
        $path = '/judiciaries/'.$court->id;
        $key = 'confirmations_cursor';
        $token = $request->validate([$key => ['nullable', 'string', 'max:2048']])[$key] ?? null;
        $cursor = null;
        if ($token) {
            try {
                $data = json_decode(base64_decode(strtr($token, '-_', '+/'), true) ?: '', true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($data) || count($data) !== 3 || ($data['court'] ?? null) !== (string) $court->id
                    || ! is_string($data['id'] ?? null) || ! Str::isUuid($data['id']) || ! is_bool($data['_pointsToNextItems'] ?? null)) {
                    throw new \InvalidArgumentException;
                }
                $cursor = new Cursor(['id' => $data['id']], $data['_pointsToNextItems']);
            } catch (\Throwable) {
                throw ValidationException::withMessages([$key => 'This confirmation page link is invalid. Return to the first page.']);
            }
        }
        $source = $court->source_legislature_id ? Legislature::query()->find($court->source_legislature_id) : null;
        $sourceValid = $source && (string) $source->jurisdiction_id === (string) $court->jurisdiction_id
            && in_array($source->status, [Legislature::STATUS_ACTIVE, Legislature::STATUS_FORMING], true);
        $member = $sourceValid && $viewer ? LegislatureMember::query()->where('legislature_id', $source->id)
            ->where('user_id', $viewer->id)->whereIn('status', LegislatureMember::CURRENT_STATUSES)->first() : null;
        $speaker = $member && (string) $source->speaker_id === (string) $member->id;
        $courtActive = $court->type === Judiciary::TYPE_APPOINTED && in_array($court->status,
            [Judiciary::STATUS_CREATING, Judiciary::STATUS_APPOINTED, Judiciary::STATUS_CONVERSION_VOTED, Judiciary::STATUS_REVERTED], true);
        $page = JudicialNomination::query()->where('judiciary_id', $court->id)->orderByDesc('id')
            ->with(['nominatingJurisdiction:id,name', 'seat', 'appointment.term'])
            ->cursorPaginate(20, ['*'], $key, $cursor);
        $rows = $page->getCollection();
        $names = CandidacyPanel::displayNames($rows->pluck('nominee_user_id')->unique()->all());
        $people = PublicPersonSelectionContext::forIds($rows->pluck('nominee_user_id')->unique()->all());
        $votes = ChamberVote::query()->whereIn('id', $rows->map(fn ($n) => $n->appointment?->consent_vote_id)->filter()->all())
            ->with('tallies')->get()->keyBy('id');
        $casts = $member ? VoteCast::query()->where('member_id', $member->id)->whereIn('vote_id', $votes->keys())->get()->keyBy('vote_id') : collect();
        $records = DB::table('public_records')->whereIn('id', $rows->pluck('dossier_record_id')->filter()->all())
            ->get(['id', 'body', 'subject_type', 'subject_id', 'via_form'])->keyBy('id');
        $link = fn (?Cursor $position) => $position ? $path.'?'.http_build_query([$key => (new Cursor([
            'id' => $position->parameter('id'), 'court' => (string) $court->id,
        ], $position->pointsToNextItems()))->encode()]) : null;

        return ['rows' => $rows->map(function (JudicialNomination $n) use ($court, $courtActive, $source, $sourceValid, $member, $speaker, $names, $people, $votes, $casts, $records) {
            $a = $n->appointment;
            $seat = $n->seat;
            $vote = $a ? $votes->get($a->consent_vote_id) : null;
            $validAppointment = $a && $seat && (string) $seat->judiciary_id === (string) $court->id
                && $a->appointable_type === 'judicial_seats' && (string) $a->appointable_id === (string) $seat->id
                && (string) $a->nominee_user_id === (string) $n->nominee_user_id && $a->nominated_via_form === 'F-LEG-021';
            $individual = $vote && $vote->votable_type === 'appointment_consent' && (string) $vote->votable_id === (string) $a?->id;
            // Step 5 publishes completed slate consents. They remain readable, but never receive individual vote controls.
            $slate = $vote && $vote->votable_type === 'judiciary' && (string) $vote->votable_id === (string) $court->id
                && $vote->status === ChamberVote::STATUS_CLOSED && $n->status === JudicialNomination::STATUS_CONSENTED;
            $validVote = $validAppointment && $vote && ($individual || $slate) && $source
                && $vote->vote_type === 'bog_consent' && $vote->vote_method === ChamberVote::METHOD_YES_NO
                && $vote->body_type === ChamberVote::BODY_LEGISLATURE && (string) $vote->body_id === (string) $source->id
                && (string) $vote->legislature_id === (string) $source->id && (string) $vote->jurisdiction_id === (string) $court->jurisdiction_id
                && $vote->stage === ChamberVote::STAGE_FLOOR && $vote->threshold_basis === ChamberVote::BASIS_MAJORITY;
            $current = $validAppointment && (string) $seat->appointment_id === (string) $a->id;
            $eligible = $sourceValid && $courtActive && $current && $individual && $validVote
                && $n->status === JudicialNomination::STATUS_NOMINATED && $a->status === Appointment::STATUS_NOMINATED && $a->term_id === null
                && $seat->status === JudicialSeat::STATUS_NOMINATED && $seat->user_id === null && $seat->term_id === null;
            $myCast = $vote ? $casts->get($vote->id)?->value : null;
            $lane = $vote?->bicameral ? $member?->seatKind() : 'all';
            $tally = $vote?->tallies->firstWhere('lane', $lane);
            $canTie = $eligible && $speaker && ! $myCast && $vote->status === ChamberVote::STATUS_CLOSED
                && $vote->outcome === ChamberVote::OUTCOME_TIED && ! $vote->speaker_tiebreak && $tally
                && $tally->yes === $tally->no && $tally->yes === $tally->required_yes - 1;
            $record = $records->get($n->dossier_record_id);
            $dossier = $validAppointment && $record && $record->subject_type === 'appointments' && (string) $record->subject_id === (string) $a->id
                && $record->via_form === 'F-LEG-021' ? $record->body : null;
            return [
                'id' => (string) $n->id, 'status' => $n->status, 'seat_number' => $seat?->seat_number, 'current' => (bool) $current,
                'nominee' => ['id' => (string) $n->nominee_user_id, 'name' => $names[$n->nominee_user_id]] + $people[$n->nominee_user_id],
                'nominated_by' => $n->mode === JudicialNomination::MODE_COMMITTEE ? 'Judicial committee' : ($n->nominatingJurisdiction?->name ?? 'Constituent jurisdiction'),
                'dossier' => $dossier,
                'term' => $validAppointment && $a->term ? ['starts' => $a->term->starts_on?->toDateString(), 'ends' => $a->term->ends_on?->toDateString()] : null,
                'consent_notice' => $a?->consent_vote_id && ! $validVote ? 'The linked consent record is unavailable or does not match this nomination.' : null,
                'consent' => $validVote ? [
                    'tally' => $this->presenter->tallyProps($vote), 'cast_url' => '/votes/'.$vote->id.'/cast',
                    'can_cast' => (bool) ($eligible && $member && ! $speaker && ! $myCast && $vote->status === ChamberVote::STATUS_OPEN),
                    'can_tiebreak' => (bool) $canTie, 'tiebreak_url' => '/votes/'.$vote->id.'/tiebreak', 'my_cast' => $myCast,
                    'read_only_reason' => $slate ? 'The recorded bench slate was confirmed together.' : (! $current ? 'This is an earlier nomination.' : ($speaker ? 'The Speaker does not cast an ordinary confirmation vote.' : null)),
                ] : null,
            ];
        })->all(), 'pages' => ['previous' => $link($page->previousCursor()), 'next' => $link($page->nextCursor()), 'first' => $path],
            'context' => ['is_member' => $member !== null, 'is_speaker' => (bool) $speaker, 'preview' => $member === null,
                'actor_name' => $member ? CandidacyPanel::displayName($viewer) : null,
                'legislature_href' => $source ? '/legislatures/'.$source->id.'/chamber' : null,
                'reason' => ! $sourceValid ? 'The court’s source legislature is unavailable for confirmation.' : null],
        ];
    }
}
