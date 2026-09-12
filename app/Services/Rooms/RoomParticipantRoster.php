<?php

namespace App\Services\Rooms;

use App\Models\Advocate;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\CaseParty;
use App\Models\CourtCase;
use App\Models\JuryMember;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\MatrixIdentity;
use App\Models\PanelJudge;
use InvalidArgumentException;

/** Current offices for a bounded set of connected identities, never a people directory. */
class RoomParticipantRoster
{
    public const LIMIT = 100;

    public function __construct(private readonly PublicRoomNames $names) {}

    public function forInstitution(Legislature|CourtCase|Board $institution, array $handles): array
    {
        if (count($handles) > self::LIMIT) throw new InvalidArgumentException('Too many participants.');
        foreach ($handles as $handle) {
            if (! is_string($handle) || mb_strlen($handle) > 255 || ! preg_match('/^@[^\s:]+:[^\s]+$/u', $handle)) {
                throw new InvalidArgumentException('Invalid participant identity.');
            }
        }
        $handles = array_values(array_unique($handles));
        if ($handles === []) return [];
        $identities = MatrixIdentity::query()->whereIn('matrix_user_id', $handles)->get(['user_id', 'matrix_user_id']);
        $users = $identities->pluck('user_id')->unique()->values()->all();
        $roles = [];
        // Earlier entries win when someone has more than one office in this room.
        $add = static function ($user, string $role, $seat = null) use (&$roles): void {
            if ($user !== null) $roles[(string) $user] ??= ['role' => $role, 'seat' => $seat];
        };
        if ($users !== [] && $institution instanceof Legislature) {
            $rows = LegislatureMember::query()->where('legislature_id', $institution->id)->current()->whereIn('user_id', $users)
                ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$institution->speaker_id])->orderBy('id')
                ->get(['id', 'user_id', 'seat_no']);
            foreach ($rows as $row) $add($row->user_id, (string) $row->id === (string) $institution->speaker_id ? 'speaker' : 'legislator', $row->seat_no);
        } elseif ($users !== [] && $institution instanceof Board) {
            $rows = BoardSeat::query()->where('board_id', $institution->id)->seated()->whereIn('holder_user_id', $users)
                ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$institution->chair_seat_id])->orderBy('id')
                ->get(['id', 'holder_user_id', 'seat_no']);
            foreach ($rows as $row) $add($row->holder_user_id, (string) $row->id === (string) $institution->chair_seat_id ? 'chair' : 'board_member', $row->seat_no);
        } elseif ($users !== [] && $institution instanceof CourtCase) {
            $judges = PanelJudge::query()->whereIn('user_id', $users)
                ->whereHas('panel', fn ($q) => $q->where('case_id', $institution->id)->where('status', 'seated'))
                ->where('status', PanelJudge::STATUS_SEATED)->where('screening_result', PanelJudge::SCREENING_CLEARED)
                ->orderByDesc('is_presiding')->orderBy('id')->get(['user_id', 'is_presiding']);
            foreach ($judges as $judge) $add($judge->user_id, $judge->is_presiding ? 'presiding_judge' : 'judge');
            $parties = CaseParty::query()->where('case_id', $institution->id)->where('status', CaseParty::STATUS_ACTIVE)
                ->whereIn('party_user_id', $users)->orderBy('id')->get(['party_user_id', 'party_role']);
            foreach ($parties as $party) $add($party->party_user_id, match ($party->party_role) {
                'prosecution' => 'prosecutor', 'defendant', 'accused' => 'defense', 'plaintiff' => 'claimant', default => 'respondent',
            });
            $advocates = Advocate::query()->whereIn('user_id', $users)->registered()
                ->where(function ($q) use ($institution): void {
                    $q->where('id', $institution->advocate_id)->orWhereIn('id', CaseParty::query()
                        ->select('represented_by_advocate_id')->where('case_id', $institution->id)
                        ->where('status', CaseParty::STATUS_ACTIVE)->whereNotNull('represented_by_advocate_id'));
                })->get(['user_id']);
            foreach ($advocates as $advocate) $add($advocate->user_id, 'advocate');
            $jurors = JuryMember::query()->whereIn('user_id', $users)
                ->whereHas('jury', fn ($q) => $q->where('case_id', $institution->id)->where('status', '!=', 'discharged'))
                ->where('screening_status', JuryMember::SCREENING_EMPANELED)->orderBy('seat_no')->orderBy('id')->get(['user_id', 'seat_no']);
            foreach ($jurors as $juror) $add($juror->user_id, 'juror', $juror->seat_no);
        }
        $names = $users === [] ? [] : $this->names->forUsers($users);
        $byHandle = $identities->keyBy('matrix_user_id');
        return array_map(static function (string $handle) use ($byHandle, $names, $roles): array {
            $user = (string) ($byHandle->get($handle)?->user_id ?? '');
            return ['handle' => $handle, 'display_name' => $names[$user] ?? null,
                ...($roles[$user] ?? ['role' => 'guest', 'seat' => null])];
        }, $handles);
    }
}
