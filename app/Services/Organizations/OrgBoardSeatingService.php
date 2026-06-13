<?php

namespace App\Services\Organizations;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\Candidacy;
use App\Models\Election;
use App\Models\ElectionRace;
use App\Models\RaceResult;
use App\Models\Tabulation;
use App\Models\Term;
use App\Services\AuditService;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * D-O5 (PHASE_D_DESIGN_organizations §C.2/§C.4) — certification + seating
 * for org-board elections.
 *
 * Org elections never pass through F-ELB-004 (no I-ELB in the loop):
 * certification is filed by R-23 via F-ORG-003/004 `action: certify`,
 * with a 48h auto-certify backstop (system actor) so a stalling agent
 * can never block constitutionally-mandated worker seats.
 *
 * Seating: winners → board_seats (seated, elected_in_race_id, holder),
 * one `org_cycle` terms row each (ends = starts + boards.cycle_months —
 * write-once, the terms-table discipline), runner-up record retained in
 * the tabulation for countback, composition revalidation, joint-chair
 * re-election trigger, public record + audit, RoleService flush
 * (R-26/R-27).
 */
class OrgBoardSeatingService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly PublicRecordService $records,
        private readonly RoleService $roles,
        private readonly OrgBoardService $boards,
        private readonly CoDeterminationService $coDetermination,
    ) {
    }

    /**
     * Certify a tabulated org-board election and seat the winners.
     *
     * @return array<string, mixed>
     */
    public function certify(Election $election): array
    {
        if (! in_array($election->kind, [Election::KIND_ORG_BOARD_OWNER, Election::KIND_ORG_BOARD_WORKER], true)) {
            throw new ConstitutionalViolation(
                "Election [{$election->id}] is not an org-board election.",
                'CGA Forms Catalog (F-ORG-003/004)'
            );
        }

        if (! in_array($election->status, [Election::STATUS_TABULATING, Election::STATUS_VOTING_CLOSED], true)) {
            throw new ConstitutionalViolation(
                "Election [{$election->id}] is not ready to certify (status: {$election->status}).",
                'Art. II §2 · as implemented'
            );
        }

        $board = Board::query()->lockForUpdate()->findOrFail($election->board_id);

        $seatClass = $election->kind === Election::KIND_ORG_BOARD_WORKER
            ? BoardSeat::CLASS_WORKER_ELECTED
            : BoardSeat::CLASS_OWNER_ELECTED;

        $certifiedAt = CarbonImmutable::now('UTC');
        $window      = [
            'starts_on' => $certifiedAt->startOfDay(),
            'ends_on'   => $certifiedAt->startOfDay()->addMonthsNoOverflow((int) $board->cycle_months),
        ];

        $seated = [];

        return DB::transaction(function () use ($election, $board, $seatClass, $window, $seated) {
            foreach ($election->races()->get() as $race) {
                $tabulation = $this->certifiedTabulation($race);

                $results = RaceResult::query()
                    ->where('tabulation_id', $tabulation->id)
                    ->whereNotNull('seat_no')
                    ->orderBy('seat_no')
                    ->get();

                foreach ($results as $result) {
                    $candidacy = Candidacy::query()->findOrFail($result->candidacy_id);

                    $seat = $board->seats()
                        ->where('seat_class', $seatClass)
                        ->where('status', BoardSeat::STATUS_VACANT)
                        ->orderBy('seat_no')
                        ->first();

                    if ($seat === null) {
                        break; // every vacant seat of the class is filled
                    }

                    $term = Term::create([
                        'office_kind'        => 'board_seat',
                        'office_type'        => 'board_seats',
                        'office_id'          => (string) $seat->id,
                        'holder_user_id'     => (string) $candidacy->user_id,
                        'jurisdiction_id'    => (string) $election->jurisdiction_id,
                        'legislature_id'     => null,
                        'term_class'         => 'org_cycle',
                        'starts_on'          => $window['starts_on']->toDateString(),
                        'ends_on'            => $window['ends_on']->toDateString(),
                        'source_election_id' => (string) $election->id,
                        'status'             => Term::STATUS_ACTIVE,
                    ]);

                    $seat->forceFill([
                        'holder_user_id'     => (string) $candidacy->user_id,
                        'elected_in_race_id' => (string) $race->id,
                        'term_id'            => (string) $term->id,
                        'status'             => BoardSeat::STATUS_SEATED,
                    ])->save();

                    $candidacy->forceFill(['status' => Candidacy::STATUS_ELECTED])->save();

                    $this->roles->flushUser((string) $candidacy->user_id); // R-26/R-27

                    $seated[] = [
                        'seat_id'      => (string) $seat->id,
                        'seat_no'      => (int) $seat->seat_no,
                        'user_id'      => (string) $candidacy->user_id,
                        'candidacy_id' => (string) $candidacy->id,
                        'race_id'      => (string) $race->id,
                        'term_id'      => (string) $term->id,
                    ];
                }

                // Non-winners of this race are defeated (ESM-06 terminal).
                Candidacy::query()
                    ->where('race_id', $race->id)
                    ->whereIn('status', [Candidacy::STATUS_FINALIST, Candidacy::STATUS_NON_FINALIST])
                    ->whereNotIn('id', array_column($seated, 'candidacy_id'))
                    ->update(['status' => Candidacy::STATUS_DEFEATED, 'updated_at' => now()]);

                $race->forceFill(['status' => Election::STATUS_CERTIFIED])->save();
            }

            // ESM-03: tabulating → certified → final (single authority).
            $lifecycle = app(\App\Services\ElectionLifecycleService::class);
            if ($election->status === Election::STATUS_VOTING_CLOSED) {
                $lifecycle->markTabulating($election);
            }
            $lifecycle->markCertified($election, ['seated' => count($seated)]);
            $lifecycle->markFinal($election);

            // Composition revalidation + chair re-election (any seating is
            // a composition change — §B.3.5).
            $snapshot = $this->coDetermination->recompute(
                (string) $board->boardable_type,
                (string) $board->boardable_id
            );

            $chairVote = $this->boards->onCompositionChange($board);

            $record = $this->records->publish(
                kind: 'certification',
                title: sprintf('Board election certified — %d %s seat(s) filled', count($seated), $seatClass),
                body: sprintf(
                    'Election %s certified; %d winner(s) seated on board %s with org-cycle terms ending %s (Art. III §6).',
                    (string) $election->id,
                    count($seated),
                    (string) $board->id,
                    $window['ends_on']->toDateString()
                ),
                attrs: [
                    'jurisdiction_id' => (string) $election->jurisdiction_id,
                    'via_workflow'    => 'WF-ORG-05',
                    'subject_type'    => 'boards',
                    'subject_id'      => (string) $board->id,
                ],
            );

            return [
                'election_id'       => (string) $election->id,
                'board_id'          => (string) $board->id,
                'seat_class'        => $seatClass,
                'seated'            => $seated,
                'term_window'       => [
                    'starts_on' => $window['starts_on']->toDateString(),
                    'ends_on'   => $window['ends_on']->toDateString(),
                ],
                'composition'       => $snapshot['reconciliation'] ?? null,
                'chair_vote_id'     => $chairVote?->id !== null ? (string) $chairVote->id : null,
                'record_id'         => (string) $record->id,
            ];
        });
    }

    /** The certified record per race — same selection as F-ELB-004. */
    private function certifiedTabulation(ElectionRace $race): Tabulation
    {
        $tabulation = Tabulation::query()
            ->where('race_id', $race->id)
            ->where('status', Tabulation::STATUS_COMPLETE)
            ->whereNotNull('record_hash')
            ->orderByDesc('completed_at')
            ->first();

        if ($tabulation === null) {
            throw new ConstitutionalViolation(
                "Race [{$race->id}] has no complete tabulation — certification requires every race counted.",
                'CGA Forms Catalog (F-ORG-003/004)'
            );
        }

        return $tabulation;
    }
}
