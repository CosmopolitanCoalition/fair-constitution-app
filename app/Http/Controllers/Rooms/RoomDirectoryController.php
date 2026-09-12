<?php

namespace App\Http\Controllers\Rooms;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\Committee;
use App\Models\CommitteeMeeting;
use App\Models\CourtCase;
use App\Models\Department;
use App\Models\Legislature;
use App\Models\Organization;
use App\Support\JurisdictionContext;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Browse one place and one kind of room. Listing never provisions a Matrix room. */
class RoomDirectoryController extends Controller
{
    public function index(Request $request): Response
    {
        $input = $request->validate([
            'section' => ['nullable', Rule::in(['chambers', 'committees', 'courts', 'boards'])],
            'cursor' => ['nullable', 'string', 'max:1024'],
        ]);
        $cursor = $this->cursor($input['cursor'] ?? null);
        $section = $input['section'] ?? 'chambers';
        $place = JurisdictionContext::requested($request);
        $rows = [];
        $pagination = ['previous' => null, 'next' => null];
        $commons = [];
        if ($place !== null) {
            $commons[] = ['title' => 'Public square', 'detail' => 'Open conversation, text, voice and video.',
                'href' => '/civic/commons/square?jurisdiction='.$place->id];
            if (Legislature::query()->where('jurisdiction_id', $place->id)->where('status', 'active')->exists()) {
                $commons[] = ['title' => 'Halls of governance', 'detail' => 'Civic discussion and public testimony.',
                    'href' => '/civic/commons/halls?jurisdiction='.$place->id];
            }
        }
        if ($section === 'boards' && $request->user()) {
            $page = BoardSeat::query()->where('holder_user_id', $request->user()->id)->seated()
                ->orderBy('id')->cursorPaginate(20, ['id', 'board_id', 'seat_class'], cursor: $cursor);
            // Only boards reached through this viewer's small membership page are resolved.
            foreach ($page->items() as $seat) {
                $board = $seat->board()->where('status', '<>', Board::STATUS_DISSOLVED)
                    ->first(['id', 'boardable_type', 'boardable_id']);
                if (! $board) continue;
                // Persisted owner types are table aliases, not PHP model names.
                $owner = match ($board->boardable_type) {
                    Board::BOARDABLE_ORGANIZATIONS => Organization::query()->find($board->boardable_id, ['id', 'name']),
                    Board::BOARDABLE_DEPARTMENTS => Department::query()->find($board->boardable_id, ['id', 'name']),
                    default => null,
                };
                $rows[] = ['id' => $seat->id, 'title' => ($owner?->name ?? 'Organization').' board',
                    'detail' => 'Private · current board members', 'href' => '/rooms/board/'.$board->id];
            }
        } elseif ($place !== null && $section !== 'boards') {
            if ($section === 'chambers') {
                $query = Legislature::query()->where('jurisdiction_id', $place->id);
            } elseif ($section === 'courts') {
                $query = CourtCase::query()->where('jurisdiction_id', $place->id);
            } else {
                // Live legislatures are unique per jurisdiction; keep even a legacy roster bounded.
                $legislatures = Legislature::query()->where('jurisdiction_id', $place->id)->limit(25)->pluck('id');
                $query = Committee::query()->whereIn('legislature_id', $legislatures);
            }
            $columns = match ($section) {
                'chambers' => ['id', 'status'],
                'courts' => ['id', 'title', 'status'],
                default => ['id', 'name', 'status'],
            };
            $page = $query->orderBy('id')->cursorPaginate(20, $columns, cursor: $cursor);
            foreach ($page->items() as $record) {
                $detail = ucfirst(str_replace('_', ' ', $record->status));
                if ($section === 'chambers') {
                    $rows[] = ['id' => $record->id, 'title' => $place->name.' chamber', 'detail' => $detail,
                        'href' => '/rooms/chamber/'.$record->id];
                } elseif ($section === 'courts') {
                    $rows[] = ['id' => $record->id, 'title' => $record->title ?: 'Court hearing', 'detail' => $detail,
                        'href' => '/rooms/court/'.$record->id];
                } else {
                    $meeting = CommitteeMeeting::query()->where('committee_id', $record->id)
                        ->orderByDesc('scheduled_for')->orderByDesc('id')->first(['id', 'status']);
                    $rows[] = ['id' => $record->id, 'title' => $record->name, 'detail' => $meeting
                        ? 'Latest meeting · '.ucfirst($meeting->status) : 'No meeting scheduled',
                        'href' => $meeting ? '/rooms/committee/'.$meeting->id : '/committees/'.$record->id,
                        'action' => $meeting ? 'Open room' : 'Committee record'];
                }
            }
        }
        if (isset($page)) {
            $page->withPath('/rooms')->appends(array_filter(['jurisdiction' => $place?->slug, 'section' => $section]));
            $pagination = ['previous' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()];
        }
        return Inertia::render('Rooms/Directory', [
            'selectedPlace' => $place ? JurisdictionContext::chip($place) : null,
            'jurisdictionContext' => $place ? JurisdictionContext::forRoom($place) : null,
            'section' => $section, 'rooms' => $rows, 'commons' => $commons, 'pagination' => $pagination,
        ]);
    }

    private function cursor(?string $encoded): ?Cursor
    {
        if ($encoded === null || $encoded === '') return null;
        $data = json_decode(base64_decode(strtr($encoded, '-_', '+/'), true) ?: '', true);
        if (! is_array($data) || count($data) !== 2 || ! is_bool($data['_pointsToNextItems'] ?? null)
            || ! is_string($data['id'] ?? null) || ! Str::isUuid($data['id'])) {
            throw ValidationException::withMessages(['cursor' => 'This page link is invalid. Open the room directory again.']);
        }
        return new Cursor(['id' => $data['id']], $data['_pointsToNextItems']);
    }
}
