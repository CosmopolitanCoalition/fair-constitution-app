<?php

namespace App\Support;

use App\Models\CommitteeMeeting;
use App\Models\PublicRecord;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\ValidationException;

/** Published testimony, paged within one committee or one explicitly selected hearing. */
final class CommitteeTestimonyDirectory
{
    public function cursor(Request $request, string $committeeId, ?string $meetingId): ?Cursor
    {
        $input = $request->validate(['testimony_cursor' => ['nullable', 'string', 'max:2048']]);
        $encoded = $input['testimony_cursor'] ?? null;
        if ($encoded === null || $encoded === '') return null;

        $data = json_decode(base64_decode(strtr($encoded, '-_', '+/'), true) ?: '', true);
        if (! is_array($data) || count($data) !== 4
            || ($data['committee'] ?? null) !== $committeeId || ! array_key_exists('meeting', $data)
            || $data['meeting'] !== $meetingId || ! is_int($data['seq'] ?? null) || $data['seq'] < 1
            || ! is_bool($data['_pointsToNextItems'] ?? null)) {
            throw ValidationException::withMessages(['testimony_cursor' => __('This testimony page link is invalid. Open the committee or hearing again.')]);
        }

        return new Cursor(['seq' => $data['seq']], $data['_pointsToNextItems']);
    }

    public function page(Request $request, string $committeeId, ?string $meetingId, ?Cursor $cursor): array
    {
        $page = PublicRecord::query()
            ->where('kind', 'testimony')
            ->where('subject_type', 'committee_meetings')
            ->when($meetingId !== null, fn ($query) => $query->where('subject_id', $meetingId),
                fn ($query) => $query->whereIn('subject_id', CommitteeMeeting::query()->select('id')->where('committee_id', $committeeId)))
            ->orderByDesc('seq')
            ->cursorPaginate(50, ['actor_display', 'body', 'published_at', 'seq', 'audit_seq'], 'testimony_cursor', $cursor);

        return [
            'rows' => $page->getCollection()->map(fn (PublicRecord $record) => [
                'who' => $record->actor_display ?? 'Resident',
                'text' => $record->body,
                'recorded_at' => $record->published_at?->toIso8601String(),
                'seq' => (int) $record->seq,
                'record_href' => '/system/audit-chain?seq='.(int) $record->audit_seq,
            ])->all(),
            'pages' => [
                'previous' => $this->url($page->previousCursor(), $request, $committeeId, $meetingId),
                'next' => $this->url($page->nextCursor(), $request, $committeeId, $meetingId),
            ],
        ];
    }

    private function url(?Cursor $cursor, Request $request, string $committeeId, ?string $meetingId): ?string
    {
        if ($cursor === null) return null;
        $scoped = new Cursor([
            'seq' => (int) $cursor->parameter('seq'), 'committee' => $committeeId, 'meeting' => $meetingId,
        ], $cursor->pointsToNextItems());

        return '/committees/'.$committeeId.'?'.http_build_query([
            ...$request->except('testimony_cursor'), 'testimony_cursor' => $scoped->encode(),
        ]);
    }
}
