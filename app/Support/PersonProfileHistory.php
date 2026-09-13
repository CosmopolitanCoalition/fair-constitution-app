<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Public history of one person, independent of the role through which they were found. */
class PersonProfileHistory
{
    public function actions(Request $request, string $userId): array
    {
        // Preserve the existing public/private boundary. Never ship raw audit payloads.
        $query = DB::table('audit_log')->where('actor_user_id', $userId)->where('rejected', false)
            ->whereIn('module', ['elections', 'residency', 'legislature', 'judiciary', 'executive'])
            ->where('event', 'not like', '%ping%')->where('event', 'not like', '%travel%')->where('event', 'not like', '%relocat%');

        return $this->page($request, $userId, 'actions', $query, ['seq'],
            ['seq', 'occurred_at', 'event', 'ref'], fn ($row) => [
                'seq' => (string) $row->seq, 'date' => $row->occurred_at,
                'label' => $row->event.($row->ref !== null ? ' · '.$row->ref : ''),
                'href' => '/system/audit-chain?seq='.$row->seq,
            ]);
    }

    public function publications(Request $request, string $userId): array
    {
        // This is the existing publication boundary, including records outside the audit module allowlist.
        return $this->page($request, $userId, 'publications', DB::table('public_records')->where('actor_user_id', $userId),
            ['seq'], ['seq', 'id', 'title', 'body', 'kind', 'published_at', 'audit_seq', 'supersedes_record_id'], fn ($row) => [
                'id' => $row->id, 'seq' => (string) $row->seq, 'title' => $row->title, 'body' => $row->body,
                'kind' => str_replace('_', ' ', $row->kind), 'date' => $row->published_at,
                'audit_href' => $row->audit_seq === null ? null : '/system/audit-chain?seq='.$row->audit_seq,
                'corrects' => $row->supersedes_record_id,
            ]);
    }

    public function hasOffices(string $userId): bool
    {
        return $this->officeQuery($userId)->exists();
    }

    public function offices(Request $request, string $userId): array
    {
        $result = $this->page($request, $userId, 'offices', $this->officeQuery($userId), ['starts', 'record_key'], ['*'], fn ($row) => (array) $row);
        $rows = collect($result['rows']);
        $places = DB::table('jurisdictions')->whereIn('id', $rows->pluck('jurisdiction_id')->filter()->unique())
            ->get(['id', 'name', 'slug'])->keyBy('id');
        $institutions = [];
        foreach (['legislature_members' => ['legislature_id', 'legislatures'], 'executive_members' => ['executive_id', 'executives'],
            'judicial_seats' => ['judiciary_id', 'judiciaries'], 'board_seats' => ['board_id', 'boards']] as $type => [$column, $route]) {
            $ids = $rows->where('office_type', $type)->pluck('office_id')->filter()->unique()->all();
            if ($ids === []) {
                continue;
            }
            foreach (DB::table($type)->whereIn('id', $ids)->get(['id', $column]) as $seat) {
                $institutions[$type.':'.$seat->id] = '/'.$route.'/'.$seat->{$column}.($type === 'legislature_members' ? '/chamber' : '');
            }
        }
        $titles = ['legislature_seat' => 'Legislative representative', 'executive_seat' => 'Executive', 'judicial_seat' => 'Judge',
            'election_board_member' => 'Election board member', 'board_governor' => 'Board governor', 'board_seat' => 'Board member',
            'admin_staff' => 'Administrative staff', 'civil_officer' => 'Civil officer'];
        $result['rows'] = $rows->map(function ($row) use ($places, $institutions, $titles) {
            $place = $places->get($row['jurisdiction_id']);

            return $row + [
                'title' => $titles[$row['office_kind']] ?? 'Public office', 'jurisdiction' => $place?->name,
                'href' => $institutions[$row['office_type'].':'.$row['office_id']] ?? ($place ? '/jurisdictions/'.$place->slug : null),
            ];
        })->all();

        return $result;
    }

    private function officeQuery(string $userId)
    {
        // Terms retain the original holder when reusable seats change hands. Never attribute an old term to today's holder.
        $terms = DB::table('terms as t')->where('t.holder_user_id', $userId)->whereNull('t.deleted_at')
            ->selectRaw("'term:' || t.id AS record_key, COALESCE(CAST(t.starts_on AS TEXT), '0001-01-01') AS starts,
                CAST(t.ends_on AS TEXT) AS scheduled_end, CAST(NULL AS TEXT) AS left_on,
                t.office_kind, t.office_type, t.office_id, t.jurisdiction_id, t.status, 'term' AS source");
        // Older installations and delegated executives also have legitimate seat records without a separate term.
        foreach ([
            ['legislature_members', 'legislatures', 'legislature_id', 'legislature_seat', 'seated_on', 'term_ends_on', 'vacated_at'],
            ['executive_members', 'executives', 'executive_id', 'executive_seat', 'joined_at', null, 'left_at'],
            ['judicial_seats', 'judiciaries', 'judiciary_id', 'judicial_seat', 'term_starts_on', 'term_ends_on', null],
        ] as [$table, $institution, $foreign, $kind, $start, $end, $left]) {
            $scheduled = $end ? "CAST(s.$end AS TEXT)" : 'CAST(NULL AS TEXT)';
            $departed = $left ? "CAST(s.$left AS TEXT)" : 'CAST(NULL AS TEXT)';
            $legacy = DB::table($table.' as s')->join($institution.' as i', 'i.id', '=', 's.'.$foreign)
                ->where('s.user_id', $userId)->whereNull('s.deleted_at')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('terms as held')
                    ->where('held.holder_user_id', $userId)->where('held.office_type', $table)
                    ->whereColumn('held.office_id', 's.id')->whereNull('held.deleted_at'))
                ->selectRaw("'$table:' || s.id AS record_key, COALESCE(CAST(s.$start AS TEXT), '0001-01-01') AS starts,
                    $scheduled AS scheduled_end, $departed AS left_on, '$kind' AS office_kind,
                    '$table' AS office_type, s.id AS office_id, i.jurisdiction_id, s.status, 'seat' AS source");
            $terms->unionAll($legacy);
        }

        return DB::query()->fromSub($terms, 'history');
    }

    private function page(Request $request, string $userId, string $kind, $query, array $order, array $columns, callable $map): array
    {
        $key = 'profile_'.$kind.'_cursor';
        $scope = hash('sha256', $userId.':'.$kind);
        $cursor = null;
        $notice = null;
        try {
            $token = $request->query($key);
            if ($token !== null && $token !== '') {
                if (! is_string($token) || strlen($token) > 2048) {
                    throw new \InvalidArgumentException;
                }
                $data = json_decode(base64_decode(strtr($token, '-_', '+/'), true) ?: '', true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($data) || count($data) !== count($order) + 2 || ($data['scope'] ?? null) !== $scope
                    || ! is_bool($data['_pointsToNextItems'] ?? null)) {
                    throw new \InvalidArgumentException;
                }
                foreach ($order as $field) {
                    $value = $data[$field] ?? null;
                    $valid = match ($field) {
                        'seq' => (is_int($value) || is_string($value)) && preg_match('/\A[1-9][0-9]{0,18}\z/', (string) $value)
                            && (strlen((string) $value) < 19 || strcmp((string) $value, (string) PHP_INT_MAX) <= 0),
                        'starts' => is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) && ! date_parse($value)['error_count'] && ! date_parse($value)['warning_count'],
                        'record_key' => is_string($value) && preg_match('/\A(?:term|legislature_members|executive_members|judicial_seats):(.+)\z/', $value, $match) && Str::isUuid($match[1]),
                        default => false,
                    };
                    if (! $valid) {
                        throw new \InvalidArgumentException;
                    }
                }
                $cursor = new Cursor(array_intersect_key($data, array_flip($order)), $data['_pointsToNextItems']);
            }
        } catch (\Throwable) {
            // A bad public bookmark must not enter a validation redirect loop.
            $notice = 'This history link is invalid or belongs to a different person. Showing the first page.';
        }
        foreach ($order as $field) {
            $query->orderByDesc($field);
        }
        $page = $query->cursorPaginate(20, $columns, $key, $cursor);
        $tab = $kind === 'offices' ? 'office' : 'record';
        $context = ['who' => $userId, 'tab' => $tab];
        $url = fn (?Cursor $position) => $position ? '/people?'.http_build_query($context + [$key => (new Cursor(
            array_combine($order, array_map(fn ($field) => $position->parameter($field), $order)) + ['scope' => $scope],
            $position->pointsToNextItems()))->encode()]) : null;

        return ['rows' => $page->getCollection()->map($map)->all(), 'pages' => [
            'previous' => $url($page->previousCursor()), 'next' => $url($page->nextCursor()),
            'first' => '/people?'.http_build_query($context),
        ], 'notice' => $notice];
    }
}
