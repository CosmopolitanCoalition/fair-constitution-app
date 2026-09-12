<?php

namespace App\Services\Legislature;

use App\Http\Presenters\ChamberVotePresenter;
use App\Models\LegislatureSession;
use App\Models\Motion;
use App\Models\PublicRecord;
use App\Models\SessionAttendance;
use App\Models\VoteCast;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Exact-session public records, with independent bounded cursors and no live actions. */
class SessionArchive
{
    public function __construct(private readonly ChamberVotePresenter $votes) {}

    public function listing(Request $request, string $legislatureId): array
    {
        $cursor = $this->cursor($request, 'cursor', 'session_no', true);
        $page = LegislatureSession::query()->where('legislature_id', $legislatureId)
            ->orderByDesc('session_no')->cursorPaginate(20, ['id', 'session_no', 'status', 'scheduled_for', 'opened_at', 'adjourned_at'], cursor: $cursor);
        $page->withPath('/legislatures/'.$legislatureId.'/sessions');
        return $this->page($page, fn ($session) => [
            'id' => (string) $session->id, 'number' => $session->session_no, 'status' => $session->status,
            'date' => ($session->opened_at ?? $session->scheduled_for)?->toIso8601String(),
            'href' => $this->href($legislatureId, (string) $session->id),
        ]);
    }

    public function record(Request $request, string $legislatureId): array
    {
        $data = $request->validate(['session' => ['required', 'uuid'], 'motion' => ['nullable', 'uuid']]);
        // Validate each cursor before any domain read; malformed bigint/UUID values never reach SQL.
        $cursors = [];
        foreach (['attendance', 'motions', 'records', 'casts'] as $name) {
            $cursors[$name] = $this->cursor($request, $name.'_cursor', $name === 'records' ? 'seq' : 'id', $name === 'records');
        }
        $session = LegislatureSession::query()->where('legislature_id', $legislatureId)->whereKey($data['session'])->firstOrFail();
        $base = '/legislatures/'.$legislatureId.'/session';
        $params = ['session' => $session->id];
        if (! empty($data['motion'])) $params['motion'] = $data['motion'];
        foreach (array_keys($cursors) as $name) if ($request->filled($name.'_cursor')) $params[$name.'_cursor'] = $request->query($name.'_cursor');
        $paginate = function ($query, string $name, string $order = 'id', array $columns = ['*']) use ($base, $params, $cursors) {
            return $query->orderByDesc($order)->cursorPaginate(20, $columns, $name.'_cursor', $cursors[$name])
                ->withPath($base)->appends($params)->fragment($name);
        };

        $attendance = $paginate(SessionAttendance::query()->where('session_id', $session->id)
            ->with(['member' => fn ($q) => $q->withTrashed()->select(['id', 'user_id', 'seat_no', 'seat_type']), 'member.user:id,display_name']), 'attendance', 'id', ['id', 'member_id', 'status']);
        $motions = $paginate(Motion::query()->where('session_id', $session->id)
            ->with(['movedBy' => fn ($q) => $q->withTrashed()->select(['id', 'user_id']), 'movedBy.user:id,display_name', 'vote.tallies']), 'motions', 'id', ['id', 'moved_by_member_id', 'vote_id', 'kind', 'text', 'status', 'bill_id']);
        $records = $paginate(PublicRecord::query()->where('legislature_id', $legislatureId)
            ->where(function ($query) use ($session) {
                $query->where(fn ($q) => $q->where('subject_type', 'legislature_session')->where('subject_id', $session->id));
                if ($session->minutes_record_id) $query->orWhere('id', $session->minutes_record_id);
            }), 'records', 'seq', ['seq', 'id', 'title', 'body', 'kind', 'published_at', 'audit_seq']);

        $selectedMotion = null;
        $casts = null;
        if (! empty($data['motion'])) {
            $motion = Motion::query()->where('session_id', $session->id)->whereKey($data['motion'])->firstOrFail();
            $selectedMotion = ['id' => (string) $motion->id, 'text' => $motion->text];
            if ($motion->vote_id !== null) {
                $casts = $paginate(VoteCast::query()->where('vote_id', $motion->vote_id)
                    ->with(['member' => fn ($q) => $q->withTrashed()->select(['id', 'user_id']), 'member.user:id,display_name']), 'casts', 'id', ['id', 'member_id', 'value', 'explanation', 'is_tiebreak']);
            }
        }

        return [
            'session' => $session->only(['id', 'session_no', 'status', 'scheduled_for', 'opened_at', 'adjourned_at',
                'serving_at_open', 'quorum_required', 'quorum_met', 'serving_by_kind', 'quorum_required_by_kind', 'agenda']),
            'attendance' => $this->page($attendance, fn ($row) => [
                'id' => (string) $row->id, 'name' => $row->member?->user?->display_name ?: 'Member',
                'seat' => $row->member?->seat_no, 'status' => $row->status,
            ]),
            'motions' => $this->page($motions, fn ($motion) => [
                'id' => (string) $motion->id, 'text' => $motion->text, 'kind' => $motion->kind, 'status' => $motion->status,
                'name' => $motion->movedBy?->user?->display_name ?: 'Member', 'bill_id' => $motion->bill_id,
                'vote' => $motion->vote ? $this->votes->tallyProps($motion->vote) : null,
                'castsHref' => $motion->vote_id ? $this->href($legislatureId, $session->id, ['motion' => $motion->id]).'#casts' : null,
            ]),
            'records' => $this->page($records, fn ($record) => [
                'id' => (string) $record->id, 'title' => $record->title, 'body' => $record->body,
                'kind' => $record->kind, 'date' => $record->published_at?->toIso8601String(),
                'auditHref' => $record->audit_seq ? '/system/audit-chain?seq='.$record->audit_seq : null,
            ]),
            'selectedMotion' => $selectedMotion,
            'casts' => $casts ? $this->page($casts, fn ($cast) => [
                'id' => (string) $cast->id, 'name' => $cast->member?->user?->display_name ?: 'Member',
                'value' => $cast->value ?? 'Ranking filed', 'explanation' => $cast->explanation,
                'isTiebreak' => (bool) $cast->is_tiebreak,
            ]) : null,
        ];
    }

    private function href(string $legislatureId, string $sessionId, array $extra = []): string
    {
        return '/legislatures/'.$legislatureId.'/session?'.http_build_query(['session' => $sessionId] + $extra);
    }

    private function page($page, callable $map): array
    {
        return ['data' => array_map($map, $page->items()), 'next' => $page->nextPageUrl(), 'previous' => $page->previousPageUrl()];
    }

    private function cursor(Request $request, string $name, string $field, bool $integer = false): ?Cursor
    {
        $input = $request->validate([$name => ['nullable', 'string', 'max:1024']]);
        $encoded = $input[$name] ?? null;
        if ($encoded === null || $encoded === '') return null;
        $decoded = json_decode(base64_decode(strtr($encoded, '-_', '+/'), true) ?: '', true);
        $value = is_array($decoded) ? ($decoded[$field] ?? null) : null;
        if (! is_array($decoded) || count($decoded) !== 2 || ! is_bool($decoded['_pointsToNextItems'] ?? null)
            || ($integer ? ! is_int($value) || $value < 1 : ! is_string($value) || ! Str::isUuid($value))) {
            throw ValidationException::withMessages([$name => 'This page link is invalid. Open the session record again.']);
        }
        return new Cursor([$field => $value], $decoded['_pointsToNextItems']);
    }
}
