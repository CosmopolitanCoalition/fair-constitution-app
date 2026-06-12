<?php

namespace App\Services\Legislature;

use App\Models\Appointment;
use App\Models\Election;
use App\Models\ElectionBoard;
use App\Services\AuditService;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;

/**
 * WF-ELE-10 completion (chamber ops §E.2) — bootstrap board retirement.
 *
 * Readiness: the forming proper board has NO unresolved nominations AND
 * seated members ≥ config('cga.election_board_min_members', 3) — no
 * constitutional number exists ("as implemented", flagged).
 *
 * The transition is ONE transaction (forced by the partial unique
 * election_boards_one_active): bootstrap → retired, proper → active,
 * custody of in-flight elections transferred (final/cancelled elections
 * keep their historical bootstrap board id — provenance is immutable),
 * one audit entry enumerating the transferred election ids, one public
 * record.
 *
 * Nothing in flight stops: approval phases, scheduled specials, queued
 * tabulations are board-agnostic jobs. The board id only gates F-ELB
 * filings — from the flip those require a seated proper-board member; the
 * operator's bootstrap posture ends instantly and automatically
 * (RoleService::hasActiveBoardSeat keys on is_bootstrap + active, now
 * false; ResolvesBoardActor::boardActorFor needs zero changes).
 *
 * A proper board later falling below the minimum does NOT resurrect the
 * bootstrap board — the legislature must appoint (honest gap, surfaced).
 */
class ElectionBoardTransitionService
{
    /** Election statuses whose custody is NOT transferred (historical). */
    public const CUSTODY_IMMUTABLE_STATUSES = [
        Election::STATUS_FINAL,
        Election::STATUS_CANCELLED,
    ];

    public function __construct(
        private readonly PublicRecordService $records,
        private readonly AuditService $audit,
        private readonly RoleService $roles,
    ) {
    }

    /**
     * Pure readiness predicate (pinned DB-free by BoardTransitionTest).
     */
    public static function ready(int $unresolvedNominations, int $seatedMembers, int $minimumMembers): bool
    {
        return $unresolvedNominations === 0 && $seatedMembers >= $minimumMembers;
    }

    /**
     * Run the readiness check for a forming proper board; transition when
     * ready. Returns the transition summary, or null when not (yet) ready.
     *
     * @return array<string, mixed>|null
     */
    public function maybeTransition(ElectionBoard $board): ?array
    {
        if ($board->is_bootstrap || $board->status !== ElectionBoard::STATUS_FORMING) {
            return null;
        }

        $unresolved = Appointment::query()
            ->where('appointable_type', 'election_boards')
            ->where('appointable_id', (string) $board->id)
            ->whereIn('status', [Appointment::STATUS_NOMINATED, Appointment::STATUS_CONSENTED])
            ->count();

        $seated = $board->members()
            ->where('status', 'seated')
            ->whereNotNull('user_id')
            ->count();

        $minimum = (int) config('cga.election_board_min_members', 3);

        if (! self::ready($unresolved, $seated, $minimum)) {
            return null;
        }

        return $this->transition($board);
    }

    /**
     * The WF-ELE-10 flip (one transaction — see class docblock).
     *
     * @return array<string, mixed>
     */
    public function transition(ElectionBoard $proper): array
    {
        $summary = DB::transaction(function () use ($proper) {
            $bootstrap = ElectionBoard::query()
                ->where('jurisdiction_id', (string) $proper->jurisdiction_id)
                ->where('is_bootstrap', true)
                ->where('status', ElectionBoard::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            // 1. Bootstrap retires FIRST (the one-active partial unique).
            if ($bootstrap !== null) {
                $bootstrap->forceFill([
                    'status'     => ElectionBoard::STATUS_RETIRED,
                    'retired_at' => now(),
                ])->save();
            }

            // 2. Proper board becomes authoritative.
            $proper->forceFill(['status' => ElectionBoard::STATUS_ACTIVE])->save();

            // 3. Custody transfer of in-flight elections; certified/final
            //    elections keep their historical board id (provenance).
            $transferred = [];

            if ($bootstrap !== null) {
                $transferred = Election::query()
                    ->where('election_board_id', (string) $bootstrap->id)
                    ->whereNotIn('status', self::CUSTODY_IMMUTABLE_STATUSES)
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id)
                    ->all();

                if ($transferred !== []) {
                    Election::query()
                        ->whereIn('id', $transferred)
                        ->update(['election_board_id' => (string) $proper->id, 'updated_at' => now()]);
                }
            }

            $this->audit->append(
                module: 'elections',
                event: 'custody_transferred',
                payload: [
                    'jurisdiction_id'    => (string) $proper->jurisdiction_id,
                    'bootstrap_board_id' => $bootstrap !== null ? (string) $bootstrap->id : null,
                    'proper_board_id'    => (string) $proper->id,
                    'election_ids'       => $transferred,
                    'citation'           => 'WF-ELE-10',
                ],
                ref: 'WF-ELE-10',
                jurisdictionId: (string) $proper->jurisdiction_id,
            );

            // 4. The public record of the hand-over.
            $this->records->publish(
                kind: 'certification',
                title: 'Bootstrap election board retired — proper board authoritative',
                body: sprintf(
                    'The bootstrap (system-as-board) posture has ended%s. Custody of %d in-flight '
                    . 'election(s) transferred; the proper board is authoritative for all future '
                    . 'elections in this jurisdiction (WF-ELE-10).',
                    $bootstrap !== null ? sprintf(' (board %s retired)', (string) $bootstrap->id) : '',
                    count($transferred)
                ),
                attrs: [
                    'jurisdiction_id' => (string) $proper->jurisdiction_id,
                    'legislature_id'  => $proper->legislature_id !== null ? (string) $proper->legislature_id : null,
                    'via_workflow'    => 'WF-ELE-10',
                    'subject_type'    => 'election_boards',
                    'subject_id'      => (string) $proper->id,
                ],
            );

            return [
                'bootstrap_board_id'    => $bootstrap !== null ? (string) $bootstrap->id : null,
                'proper_board_id'       => (string) $proper->id,
                'transferred_elections' => $transferred,
            ];
        });

        // R-08 derivations changed for everyone touching this board
        // (operator loses the bootstrap posture; appointees gained seats).
        $this->roles->flush();

        return $summary;
    }
}
