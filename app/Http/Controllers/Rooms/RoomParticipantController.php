<?php

namespace App\Http\Controllers\Rooms;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\CourtCase;
use App\Models\Legislature;
use App\Models\MatrixRoom;
use App\Services\Matrix\PublicVoiceRoomAccess;
use App\Services\Matrix\VoiceReachFailed;
use App\Services\Rooms\BoardRoomAccess;
use App\Services\Rooms\RoomParticipantRoster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only POST: handles are bounded request data, never grants or room selectors. */
class RoomParticipantController extends Controller
{
    public function __construct(private readonly RoomParticipantRoster $roster,
        private readonly BoardRoomAccess $boards, private readonly PublicVoiceRoomAccess $publicRooms) {}

    public function chamber(Request $request, Legislature $legislature): JsonResponse
    {
        return $this->participants($request, $legislature, MatrixRoom::ENTITY_LEGISLATURE);
    }

    public function court(Request $request, CourtCase $case): JsonResponse
    {
        return $this->participants($request, $case, MatrixRoom::ENTITY_CASE);
    }

    public function board(Request $request, Board $board): JsonResponse
    {
        return $this->participants($request, $board, MatrixRoom::ENTITY_BOARD);
    }

    private function participants(Request $request, Legislature|CourtCase|Board $institution, string $type): JsonResponse
    {
        abort_unless($request->user(), 403);
        $data = $request->validate(['handles' => ['present', 'array', 'max:'.RoomParticipantRoster::LIMIT],
            'handles.*' => ['required', 'string', 'max:255', 'regex:/^@[^\s:]+:[^\s]+$/u']]);
        $private = $institution instanceof Board;
        if ($private) $this->boards->assertMayJoin($request->user(), $institution);
        $room = MatrixRoom::query()->where('entity_type', $type)->where('entity_id', $institution->id)
            ->whereNull('space_type')->whereNull('tombstoned_at')->whereNotNull('matrix_room_id')
            ->where('room_type', $private ? MatrixRoom::ROOM_ORG_PRIVATE : MatrixRoom::ROOM_INSTITUTION)
            ->where('is_public', ! $private)->where('is_encrypted', false)->first(['matrix_room_id']);
        abort_unless($room && $room->matrix_room_id, 403, 'This room is not available.');
        if (! $private) {
            abort_unless($institution->jurisdiction_id, 403);
            try { $this->publicRooms->assertMayJoin($request->user(), $institution->jurisdiction_id, $room->matrix_room_id); }
            catch (VoiceReachFailed) { abort(403, 'This room is not available.'); }
        }
        return response()->json(['roomId' => $room->matrix_room_id, 'roster' => $this->roster->forInstitution($institution, $data['handles'])]);
    }
}
