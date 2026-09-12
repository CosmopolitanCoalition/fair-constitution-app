<?php

namespace App\Http\Controllers\Rooms;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\CourtCase;
use App\Models\Legislature;
use App\Services\Rooms\RoomFloorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoomFloorController extends Controller
{
    public function chamber(Request $request, Legislature $legislature): RedirectResponse
    {
        return $this->apply($request, 'legislature', (string) $legislature->id);
    }

    public function court(Request $request, CourtCase $case): RedirectResponse
    {
        return $this->apply($request, 'court', (string) $case->id);
    }

    public function board(Request $request, Board $board): RedirectResponse
    {
        return $this->apply($request, 'board', (string) $board->id);
    }

    private function apply(Request $request, string $kind, string $id): RedirectResponse
    {
        abort_unless($request->user(), 403);
        $data = $request->validate([
            'action' => ['required', Rule::in(['raise', 'lower', 'recognize', 'yield', 'witness'])],
            'handle' => ['nullable', 'string', 'max:255', 'required_if:action,witness'],
        ]);
        app(RoomFloorService::class)->act($kind, $id, $request->user(), $data['action'], $data['handle'] ?? null);
        return back()->with('status', match ($data['action']) {
            'raise' => 'Your hand is raised.', 'lower' => 'Your hand is lowered.',
            'recognize' => 'The speaking queue has been updated.', 'yield' => 'The floor is open.',
            'witness' => 'The participant is on the witness stand. Testimony remains part of the formal case record.',
        });
    }
}
