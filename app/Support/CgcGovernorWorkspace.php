<?php

namespace App\Support;

use App\Http\Presenters\CandidacyPanel;
use App\Http\Presenters\ChamberVotePresenter;
use App\Models\Appointment;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\ChamberVote;
use App\Models\Executive;
use App\Models\ExecutiveMember;
use App\Models\Jurisdiction;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\Organization;
use App\Models\User;
use App\Models\VoteCast;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Current CGC ownership/oversight only; authority is rechecked by the existing filing engine. */
final class CgcGovernorWorkspace
{
    private ?array $resolved = null;

    private ?LegislatureMember $member = null;

    public function __construct(private Organization $organization, private ?Board $board, private ?User $viewer,
        private ChamberVotePresenter $votes) {}

    public function context(): ?array
    {
        if (! $this->organization->is_cgc) {
            return null;
        }
        if ($this->resolved !== null) {
            return $this->resolved;
        }
        $org = $this->organization;
        $executive = $org->overseen_by_executive_id ? Executive::query()->find($org->overseen_by_executive_id) : null;
        $legislature = $org->created_by_legislature_id ? Legislature::query()->find($org->created_by_legislature_id) : null;
        $placeIds = array_filter([$executive?->jurisdiction_id, $legislature?->jurisdiction_id]);
        $places = $placeIds === [] ? collect() : Jurisdiction::query()->whereIn('id', $placeIds)->pluck('name', 'id');
        $boardCurrent = $this->board !== null && (string) $this->board->id === (string) $org->board_id
            && $this->board->boardable_type === Board::BOARDABLE_ORGANIZATIONS && (string) $this->board->boardable_id === (string) $org->id;
        $vacancies = $boardCurrent ? $this->board->seats->where('seat_class', BoardSeat::CLASS_GOVERNOR)->where('status', BoardSeat::STATUS_VACANT)
            ->whereNull('appointment_id')->whereNull('holder_user_id')->whereNull('term_id')->count() : 0;
        $reason = match (true) {
            $org->type !== Organization::TYPE_COMMON_GOOD_CORP || $org->status !== Organization::STATUS_ACTIVE || ! $org->is_active || $org->dissolved_at !== null => __('This common-good corporation is not active.'),
            ! $boardCurrent || ! in_array($this->board->status, [Board::STATUS_FORMING, Board::STATUS_ACTIVE], true) => __('This organization has no current governing board available for appointments.'),
            $executive === null || ! in_array($executive->status, [Executive::STATUS_DELEGATED, Executive::STATUS_ELECTED, Executive::STATUS_CONVERSION_VOTED], true)
                || (string) $executive->jurisdiction_id !== (string) $org->jurisdiction_id => __('The assigned overseeing executive is unavailable or belongs to another jurisdiction.'),
            $legislature === null || ! in_array($legislature->status, ['active', 'forming'], true)
                || (string) $legislature->jurisdiction_id !== (string) $org->jurisdiction_id => __('The creating legislature is unavailable or belongs to another jurisdiction.'),
            default => null,
        };
        $executiveMember = $reason === null && $this->viewer !== null ? ExecutiveMember::query()
            ->where('executive_id', $executive->id)->where('user_id', $this->viewer->id)->where('status', ExecutiveMember::STATUS_SEATED)
            ->where('role', ExecutiveMember::ROLE_PRINCIPAL)->first(['id']) : null;
        $this->member = $reason === null && $this->viewer !== null ? LegislatureMember::query()
            ->where('legislature_id', $legislature->id)->where('user_id', $this->viewer->id)->whereIn('status', LegislatureMember::CURRENT_STATUSES)->first() : null;

        return $this->resolved = [
            'executive_href' => $executive ? '/executives/'.$executive->id : null,
            'executive_name' => $executive ? __(':place executive', ['place' => $places[$executive->jurisdiction_id] ?? __('Assigned')]) : null,
            'legislature_href' => $legislature ? '/legislatures/'.$legislature->id.'/chamber' : null,
            'legislature_name' => $legislature ? __(':place legislature', ['place' => $places[$legislature->jurisdiction_id] ?? __('Creating')]) : null,
            'legislature_id' => $legislature ? (string) $legislature->id : null,
            'board_id' => $boardCurrent ? (string) $this->board->id : null,
            'jurisdiction_id' => $org->jurisdiction_id,
            'vacancies' => $vacancies, 'ready' => $reason === null,
            'canNominate' => $executiveMember !== null && $vacancies > 0,
            'preview' => $executiveMember === null,
            'actor_name' => $executiveMember ? CandidacyPanel::displayName($this->viewer) : null,
            'reason' => $reason ?? ($executiveMember === null ? __('Nominations are filed by seated members of this organization’s overseeing executive.')
                : ($vacancies === 0 ? __('No governor seat is vacant. Existing nominations and terms are shown below.') : null)),
            'member_id' => $this->member?->id,
            'isSpeaker' => $this->member !== null && (string) $legislature->speaker_id === (string) $this->member->id,
            'nominate_href' => '/organizations/'.$org->id.'/governor-nominations',
        ];
    }

    public function appointments(Request $request): array
    {
        $ctx = $this->context();
        $path = '/organizations/'.$this->organization->id.'/board-elections';
        $empty = ['rows' => [], 'pages' => ['previous' => null, 'next' => null, 'first' => $path]];
        if (! $ctx || ! $ctx['board_id']) {
            return $empty;
        }
        $input = $request->validate(['governor_cursor' => ['nullable', 'string', 'max:2048']]);
        $cursor = null;
        if (! empty($input['governor_cursor'])) {
            try {
                $data = json_decode(base64_decode(strtr($input['governor_cursor'], '-_', '+/'), true) ?: '', true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($data) || count($data) !== 3 || ($data['board'] ?? null) !== $ctx['board_id']
                    || ! is_string($data['id'] ?? null) || ! Str::isUuid($data['id']) || ! is_bool($data['_pointsToNextItems'] ?? null)) {
                    throw new \InvalidArgumentException;
                }
                $cursor = new Cursor(['directory_id' => $data['id']], $data['_pointsToNextItems']);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['governor_cursor' => __('This appointment page link is invalid. Return to the first page.')]);
            }
        }
        $page = DB::table('appointments as a')->join('board_seats as s', 's.id', '=', 'a.appointable_id')
            ->where('a.appointable_type', 'board_seats')->whereNull('a.deleted_at')->whereNull('s.deleted_at')
            ->where('s.board_id', $ctx['board_id'])->where('s.seat_class', BoardSeat::CLASS_GOVERNOR)
            ->orderByDesc('directory_id')->cursorPaginate(20, ['a.id as directory_id', 'a.id', 'a.nominee_user_id', 'a.consent_vote_id', 'a.status', 'a.term_id', 'a.nominated_via_form', 'a.created_at',
                's.id as seat_id', 's.seat_no', 's.appointment_id as current_appointment_id', 's.status as seat_status',
                's.holder_user_id as current_holder_id', 's.term_id as seat_term_id'], 'governor_cursor', $cursor);
        $rows = $page->items();
        $ids = array_values(array_unique(array_column($rows, 'nominee_user_id')));
        $names = CandidacyPanel::displayNames($ids);
        $people = PublicPersonSelectionContext::forIds($ids);
        $votes = ChamberVote::query()->whereIn('id', array_filter(array_column($rows, 'consent_vote_id')))->with('tallies')->get()->keyBy('id');
        $terms = DB::table('terms')->whereIn('id', array_filter(array_column($rows, 'term_id')))->get(['id', 'starts_on', 'ends_on'])->keyBy('id');
        $casts = $this->member === null ? collect() : VoteCast::query()->where('member_id', $this->member->id)->whereIn('vote_id', $votes->keys())->get(['vote_id', 'value'])->keyBy('vote_id');
        $link = function (?Cursor $position) use ($ctx, $path) {
            return $position ? $path.'?'.http_build_query(['governor_cursor' => (new Cursor([
                'id' => $position->parameter('directory_id'), 'board' => $ctx['board_id'],
            ], $position->pointsToNextItems()))->encode()]) : null;
        };

        return ['rows' => array_map(function ($row) use ($ctx, $names, $people, $votes, $casts, $terms) {
            $vote = $votes->get($row->consent_vote_id);
            $current = (string) $row->current_appointment_id === (string) $row->id;
            $validVote = $vote && $vote->vote_type === 'bog_consent' && $vote->votable_type === 'appointment_consent' && (string) $vote->votable_id === (string) $row->id
                && $vote->body_type === ChamberVote::BODY_LEGISLATURE && (string) $vote->body_id === (string) $ctx['legislature_id']
                && $vote->stage === ChamberVote::STAGE_FLOOR && (string) $vote->legislature_id === (string) $ctx['legislature_id'] && (string) $vote->jurisdiction_id === (string) $ctx['jurisdiction_id'];
            $eligibleAppointment = $ctx['ready'] && $current && $row->status === Appointment::STATUS_NOMINATED && $row->term_id === null && $row->nominated_via_form === 'F-EXE-001'
                && $row->seat_status === BoardSeat::STATUS_NOMINATED && $row->current_holder_id === null && $row->seat_term_id === null;
            $canCast = $eligibleAppointment && $validVote && $vote->status === ChamberVote::STATUS_OPEN
                && $this->member !== null && ! $ctx['isSpeaker'] && ! $casts->has($vote->id);
            $speakerLane = $vote?->bicameral ? $this->member?->seatKind() : 'all';
            $tally = $vote?->tallies->firstWhere('lane', $speakerLane);
            $canTiebreak = $eligibleAppointment && $validVote && $ctx['isSpeaker'] && ! $casts->has($vote->id)
                && $vote->status === ChamberVote::STATUS_CLOSED && $vote->outcome === ChamberVote::OUTCOME_TIED
                && $vote->threshold_basis === ChamberVote::BASIS_MAJORITY && ! $vote->speaker_tiebreak
                && $tally !== null && $tally->yes === $tally->no && $tally->yes === $tally->required_yes - 1;
            $record = DB::table('public_records')->where('subject_type', 'appointments')->where('subject_id', $row->id)->where('via_form', 'F-EXE-001')
                ->orderByDesc('seq')->first(['id', 'title', 'body', 'published_at']);

            return ['id' => (string) $row->id, 'seat_no' => (int) $row->seat_no, 'status' => $row->status, 'current' => $current,
                'nominee' => ['id' => (string) $row->nominee_user_id, 'name' => $names[$row->nominee_user_id]] + $people[$row->nominee_user_id],
                'created_at' => $row->created_at, 'term' => $terms->get($row->term_id),
                'dossier' => $record,
                'consent_notice' => $row->consent_vote_id && ! $validVote ? __('The linked consent record is unavailable or does not match this appointment.') : null,
                'consent' => $validVote ? ['tally' => $this->votes->tallyProps($vote), 'cast_url' => '/votes/'.$vote->id.'/cast',
                    'can_cast' => (bool) $canCast, 'my_cast' => $casts->get($vote->id)?->value,
                    'can_tiebreak' => (bool) $canTiebreak, 'tiebreak_url' => '/votes/'.$vote->id.'/tiebreak',
                    'read_only_reason' => ! $current ? __('This is an earlier nomination for the seat.') : ($ctx['isSpeaker'] ? __('The Speaker does not cast an ordinary consent vote.') : null),
                ] : null];
        }, $rows), 'pages' => ['previous' => $link($page->previousCursor()), 'next' => $link($page->nextCursor()), 'first' => '/organizations/'.$this->organization->id.'/board-elections']];
    }
}
