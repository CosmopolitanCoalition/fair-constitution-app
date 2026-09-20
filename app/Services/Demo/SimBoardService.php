<?php

namespace App\Services\Demo;

use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\Organization;
use App\Models\Term;
use App\Services\Organizations\CoDeterminationService;
use App\Services\Organizations\OrgBoardService;
use App\Services\ClockService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use App\Support\HostCapacity;
use App\Support\SimTimer;
use Illuminate\Support\Facades\DB;

/**
 * THE SIM BOARD SEEDER (W7 — the vibrant demo). Fills organization boards so the
 * board and co-determination surfaces are not empty:
 *   · CGC governor boards — the owner (public) side is appointed; the governors
 *     are the overseeing executive committee's people (or residents where no
 *     committee stood up), seated with a 10-year civil term (CLK-09).
 *   · Business boards — a bounded sample of businesses get a provisioned board,
 *     a real org_workers employment sample, a co-determination reconcile, and
 *     seated owner and worker representatives.
 *
 * ROWS ARE SAMPLED (CivicsStage's philosophy, forced by the 8 GB box). Real
 * employment can be 100+ per firm and can never be materialised planet-wide, so
 * a capped worker sample is employed from the jurisdiction's own residents. In a
 * large jurisdiction the roster is big enough to cross the co-determination
 * threshold and worker seats appear; in a small one the board is owner-only —
 * the honest outcome, not a fabricated one.
 *
 * Seats are filled as SYSTEM ACTS (ruling sub-institutions-path B): the same
 * rows a real seating writes (a Term, the board_seat holder + status) without a
 * per-seat election or consent vote at planet scale.
 */
class SimBoardService
{
    /** Businesses given a provisioned, seated board per jurisdiction. */
    private const BUSINESS_BOARD_SAMPLE = 3;

    /** Workers employed per sampled business (capped — rows are sampled). */
    private const WORKER_SAMPLE_CAP = 150;

    public function __construct(
        private readonly OrgBoardService $boards,
        private readonly CoDeterminationService $coDetermination,
        private readonly RoleService $roles,
        private readonly SettingsResolver $settings,
        private readonly ClockService $clocks,
    ) {}

    /**
     * Seat the governor (owner/public) side of every CGC board in this
     * jurisdiction. Idempotent: only VACANT governor seats are filled.
     *
     * @return int governor seats newly seated
     */
    public function seatCgcGovernors(string $jurisdictionId, ?\Closure $beat = null): int
    {
        // The overseeing executive committee's people appoint the governors; on
        // a jurisdiction where none has stood up, the jurisdiction's residents
        // stand in so the public board is not left empty for the demo.
        $holders = null;
        $seated = 0;
        $cursor = 0;

        foreach ($this->cgcBoards($jurisdictionId, $beat) as $board) {
            if ($holders === null) {
                $holders = $this->holderPool($jurisdictionId);
                if ($holders === []) {
                    return 0;
                }
                $years = $this->settings->resolveInt($jurisdictionId, 'civil_appointment_years', 10);
            }
            $beat && $beat();
            $vacant = BoardSeat::query()
                ->where('board_id', $board->id)
                ->where('seat_class', BoardSeat::CLASS_GOVERNOR)
                ->where('status', BoardSeat::STATUS_VACANT)
                ->orderBy('seat_no')
                ->get();

            foreach ($vacant as $seat) {
                $this->seatSeat($seat, $holders[$cursor++ % count($holders)], $jurisdictionId, Term::CLASS_CIVIL_APPOINTMENT, $years);
                $seated++;
            }

            if ($seated > 0) {
                $board->forceFill(['status' => Board::STATUS_ACTIVE, 'composition_valid' => true])->save();
            }
        }

        return $seated;
    }

    /**
     * Resolve the scope BEFORE probing boards: a reorderable join allowed stale
     * type statistics to scan every organization's board for each jurisdiction.
     * No board_id pointer or is_active filter: the boardable relation is authority.
     * The old join was unordered. Explicit organization/id order now makes the
     * existing holder rotation stable across pages; no eligible board is capped.
     *
     * @return \Generator<int, Board>
     */
    private function cgcBoards(string $jurisdictionId, ?\Closure $beat): \Generator
    {
        $after = null;
        $size = HostCapacity::sweepChunk();
        do {
            $beat && $beat();
            $mark = hrtime(true);
            try {
                $ids = Organization::query()->where('jurisdiction_id', $jurisdictionId)
                    ->where('type', Organization::TYPE_COMMON_GOOD_CORP)
                    ->when($after !== null, fn ($query) => $query->where('id', '>', $after))
                    ->orderBy('id')->limit($size)->pluck('id');
                $boards = $ids->isEmpty() ? collect() : Board::query()
                    ->where('boardable_type', Board::BOARDABLE_ORGANIZATIONS)
                    ->whereIn('boardable_id', $ids)
                    ->where('status', '!=', Board::STATUS_DISSOLVED)
                    ->orderBy('boardable_id')->orderBy('id')->get();
            } finally {
                SimTimer::record('civics.cgc_board_lookup', (int) ((hrtime(true) - $mark) / 1000));
            }
            foreach ($boards as $board) {
                yield $board;
            }
            $after = $ids->last();
        } while ($ids->count() === $size);
    }

    /**
     * Provision and seat boards for a bounded sample of this jurisdiction's
     * businesses: a real employment sample, a co-determination reconcile, and
     * seated owner + worker representatives.
     *
     * @return array{boards:int, workers:int, owner_seats:int, worker_seats:int}
     */
    public function seedBusinessBoards(string $jurisdictionId, ?\Closure $beat = null): array
    {
        $out = ['boards' => 0, 'workers' => 0, 'owner_seats' => 0, 'worker_seats' => 0];

        $businesses = Organization::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('type', Organization::TYPE_BUSINESS)
            ->whereNull('deleted_at')
            ->whereNull('board_id') // not already boarded (idempotent)
            ->orderBy('id')
            ->limit(self::BUSINESS_BOARD_SAMPLE)
            ->get();

        if ($businesses->isEmpty()) {
            return $out;
        }

        $residents = $this->holderPool($jurisdictionId);
        if ($residents === []) {
            return $out;
        }

        foreach ($businesses as $org) {
            $beat && $beat();

            // Owner side: a small board (owner-elected seats for a private firm).
            $ownerSeats = 3;
            $board = $this->boards->provision($org, $ownerSeats);

            // Employment sample: employ residents (capped) so co-determination
            // reads a REAL headcount. A large jurisdiction crosses the threshold.
            $target = min(self::WORKER_SAMPLE_CAP, max(0, (int) $org->worker_count), count($residents));
            $now = now();
            $workerRows = [];
            for ($i = 0; $i < $target; $i++) {
                $workerRows[] = [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'employer_type' => 'organizations',
                    'employer_id' => (string) $org->id,
                    'user_id' => $residents[$i % count($residents)],
                    'status' => 'active',
                    'started_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($workerRows, 500) as $chunk) {
                DB::table('org_workers')->insertOrIgnore($chunk);
            }
            $out['workers'] += count($workerRows);

            // Co-determination reconciles the worker seat ROWS to the headcount.
            $this->coDetermination->recompute('organizations', (string) $org->id);

            // Seat owner-elected seats (residents standing as owners) and any
            // worker seats co-determination just created (workers as holders).
            $out['owner_seats'] += $this->seatVacant($board, BoardSeat::CLASS_OWNER_ELECTED, $residents, $jurisdictionId);
            $out['worker_seats'] += $this->seatVacant(
                $board->fresh(),
                BoardSeat::CLASS_WORKER_ELECTED,
                array_slice($residents, 0, max(1, $target)),
                $jurisdictionId
            );

            $board->forceFill(['status' => Board::STATUS_ACTIVE, 'composition_valid' => true])->save();
            $out['boards']++;
        }

        return $out;
    }

    /**
     * Provision (if absent) and seat one organisation's owner-side board with the
     * given holders, as a system act (Phase J, W-0300: the Foundation's
     * member-elected board on a synthetic world). Idempotent: an existing board
     * is kept and only its vacant owner-elected seats are filled.
     *
     * @param list<string> $holderUserIds
     * @return int seats newly seated
     */
    public function seatOrganizationBoard(Organization $org, int $seats, array $holderUserIds, string $jurisdictionId): int
    {
        if ($holderUserIds === []) {
            return 0;
        }

        $board = $org->board_id !== null ? Board::query()->find($org->board_id) : null;
        if ($board === null) {
            $board = $this->boards->provision($org, $seats);
        }

        $seated = $this->seatVacant($board, BoardSeat::CLASS_OWNER_ELECTED, $holderUserIds, $jurisdictionId);
        if ($seated > 0) {
            $board->forceFill(['status' => Board::STATUS_ACTIVE, 'composition_valid' => true])->save();
        }

        return $seated;
    }

    /** Seat every vacant seat of a class with holders (system act). */
    private function seatVacant(Board $board, string $seatClass, array $holders, string $jurisdictionId): int
    {
        if ($holders === []) {
            return 0;
        }

        $vacant = BoardSeat::query()
            ->where('board_id', $board->id)
            ->where('seat_class', $seatClass)
            ->where('status', BoardSeat::STATUS_VACANT)
            ->orderBy('seat_no')
            ->get();

        $n = 0;
        foreach ($vacant as $seat) {
            $this->seatSeat($seat, $holders[$n % count($holders)], $jurisdictionId, 'org_cycle', null, (int) ($board->cycle_months ?? 48));
            $n++;
        }

        return $n;
    }

    /** The seating write, as a system act: a Term, then the board_seat holder. */
    private function seatSeat(BoardSeat $seat, string $userId, string $jurisdictionId, string $termClass, ?int $years, ?int $months = null): void
    {
        $now = now();
        $ends = $years !== null ? $now->copy()->addYears($years) : $now->copy()->addMonthsNoOverflow($months ?? 48);

        DB::transaction(function () use ($seat, $userId, $jurisdictionId, $termClass, $now, $ends) {
            $term = Term::create([
                'office_kind' => 'board_seat',
                'office_type' => 'board_seats',
                'office_id' => (string) $seat->id,
                'holder_user_id' => $userId,
                'jurisdiction_id' => $jurisdictionId,
                'term_class' => $termClass,
                'starts_on' => $now->toDateString(),
                'ends_on' => $ends->toDateString(),
                'status' => Term::STATUS_ACTIVE,
            ]);

            $seat->forceFill([
                'holder_user_id' => $userId,
                'term_id' => (string) $term->id,
                'status' => BoardSeat::STATUS_SEATED,
            ])->save();

            if ($termClass === Term::CLASS_CIVIL_APPOINTMENT) {
                $this->clocks->arm(
                    'CLK-09',
                    $jurisdictionId,
                    'term',
                    (string) $term->id,
                    $ends->copy()->startOfDay(),
                    ['step' => 'civil_term_expiry', 'ends_on' => $ends->toDateString()],
                );
            }
        });

        $this->roles->flushUser($userId);
    }

    /**
     * Holder candidates for this jurisdiction: the overseeing executive's seated
     * principals first (they appoint the public side), then the jurisdiction's
     * sim residents, so a place with no executive still fills its boards.
     *
     * @return list<string>
     */
    private function holderPool(string $jurisdictionId): array
    {
        $execMembers = DB::table('executive_members as em')
            ->join('executives as e', 'e.id', '=', 'em.executive_id')
            ->where('e.jurisdiction_id', $jurisdictionId)->whereNull('e.deleted_at')
            ->where('em.status', 'seated')->whereNull('em.deleted_at')->whereNotNull('em.user_id')
            ->pluck('em.user_id')->map(fn ($id) => (string) $id)->all();

        $residents = DB::table('residency_confirmations as rc')
            ->join('users as u', 'u.id', '=', 'rc.user_id')
            ->where('rc.jurisdiction_id', $jurisdictionId)->where('rc.is_active', true)
            ->where('u.email', 'like', 'sim-%@demo.invalid')
            ->orderBy('rc.user_id')->limit(self::WORKER_SAMPLE_CAP)
            ->pluck('rc.user_id')->map(fn ($id) => (string) $id)->all();

        return array_values(array_unique(array_merge($execMembers, $residents)));
    }
}
