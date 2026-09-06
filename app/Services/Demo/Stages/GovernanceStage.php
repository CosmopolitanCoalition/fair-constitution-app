<?php

namespace App\Services\Demo\Stages;

use App\Domain\Engine\ConstitutionalEngine;
use App\Models\ChamberVote;
use App\Models\Department;
use App\Models\Executive;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\User;
use App\Services\ChamberVoteService;
use App\Services\InstitutionScaleService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The GOVERNANCE stage — the growth dial's upper half (SERVICE_SCALE_FORMULA §5).
 *
 * The formula's stages 4 (Maturing) and 5 (Peak) are where a seated chamber
 * grows into the institutions its size warrants: committees toward `K(S)`, and
 * an executive that delegates and then charters departments toward `D(P)`.
 * Until now the sim stopped dead at stage 3 (Governing) — its five stages ran
 * cohort → identity → election → counting → seating and then simply stopped, so
 * a demo world had chambers and no committee or department structure, forever.
 *
 * ⚑ IT DRIVES THE TARGET THROUGH THE REAL FORMS; IT MATERIALISES NOTHING.
 * Committees and departments are ACTS OF SELF-GOVERNMENT (Art. II §9). Q4 of the
 * formula's rulings (operator, 2026-07-29) is explicit that a provisioning
 * engine may NEVER mint them — they arrive through F-LEG-009 / F-LEG-014 /
 * F-LEG-016 once a chamber is seated and VOTES. So this stage files the real
 * forms through the real ConstitutionalEngine, opens real chamber votes, and the
 * seated members cast real votes until they adopt. Every row is created by the
 * vote engine's adoption dispatch, exactly as for a live chamber. The pins prove
 * it: every committee carries a `created_by_vote_id` and every department a
 * `charter_law_id` — columns only the adoption path sets.
 *
 * The formula supplies only the TARGET, a pre-governance ceiling never a mandate
 * (§6): a chamber that has voted itself fewer than the target is grown toward
 * it; a chamber already at or past it is left exactly as it voted itself.
 *
 * ⚑ EVERY GATE DEFERS-WITH-REASON, IT NEVER FORCES AN ACT. The doctrine is the
 * one `docs/plans/simworld/R_A_OBSERVANCE.md` records for the election stage: the
 * sim defers to the guard, it never fights it. Concretely:
 *   - A committee act must satisfy the Art. V §3 kind split, so a bicameral
 *     chamber whose Type B half is unseated (the exact state lane 1's Type B
 *     race fix clears) cannot pass one → the committee half SKIPS with a reason,
 *     and starts working the instant the Type B half seats, no edit here.
 *   - A department needs an executive that has DELEGATED (Art. III §1), and a
 *     committee executive wants 5+ members, so a chamber too small to seat one,
 *     or whose delegation vote has not adopted, defers the department half.
 * Neither gate fakes a member, lowers a threshold, or writes a row directly.
 */
final class GovernanceStage
{
    /** A plausible standing-committee vocabulary; a real chamber names its own. */
    public const COMMITTEE_NAMES = [
        'Rules', 'Budget', 'Oversight', 'Judiciary', 'Public Works',
        'Health', 'Education', 'Environment', 'Foreign Relations', 'Ethics',
        'Science', 'Labour', 'Housing', 'Transport', 'Culture',
        'Agriculture', 'Energy', 'Trade', 'Defence', 'Technology',
        'Water', 'Heritage', 'Youth', 'Elections',
    ];

    /** A committee wants about five members (§4.4's staffing clamp). */
    public const SEATS_PER_COMMITTEE = 5;

    /** A committee executive is 5+ members (Art. III) — its principal count. */
    private const EXEC_COMMITTEE_SIZE = 5;

    /** The mandatory five (Art. II §9) get human names; the rest are `other`. */
    public const DEPT_NAMES = [
        Department::KIND_CHIEF_EXECUTIVE => 'Office of the Chief Executive',
        Department::KIND_TREASURY        => 'Treasury',
        Department::KIND_DEFENSE         => 'Defence',
        Department::KIND_STATE           => 'State',
        Department::KIND_JUSTICE         => 'Justice',
    ];

    private function __construct() {}

    /**
     * Advance one jurisdiction's chamber through the growth dial. Both halves
     * run independently and each defers on its own terms, so a chamber that can
     * grow committees but not yet departments does the former and reports why it
     * held the latter.
     *
     * @return array{
     *     committees: array{created:int, target:?int, existing:?int, skipped:?string},
     *     departments: array{created:int, target:?int, existing:?int, delegated:bool, skipped:?string},
     *     skipped: ?string
     * }
     */
    public static function run(string $jurisdictionId, ?string $runId, int $version, ?\Closure $beat = null): array
    {
        $legislature = Legislature::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->whereNull('deleted_at')
            ->first();

        if ($legislature === null) {
            return self::bothSkip('no legislature');
        }

        $serving = self::seatedMembers($legislature);

        if ($serving->isEmpty()) {
            // Stage 3 has not happened yet. Nothing to grow into.
            return self::bothSkip('chamber not seated');
        }

        $proposerUser = User::query()->find($serving->first()->user_id);

        if ($proposerUser === null) {
            return self::bothSkip('no seated member with a user to hold the pen');
        }

        return [
            'committees' => self::growCommittees($legislature, $serving, $proposerUser),
            'departments' => self::growDepartments($legislature, $serving, $proposerUser),
            'skipped' => null,
        ];
    }

    /**
     * The committee half — F-LEG-009, a SUPERMAJORITY vote per committee.
     *
     * @return array{created:int, target:?int, existing:?int, skipped:?string}
     */
    private static function growCommittees(Legislature $legislature, Collection $serving, User $proposerUser): array
    {
        // THE BICAMERAL DEFERRAL. A committee act must satisfy the Art. V §3 kind
        // split, so an unseated Type B half makes it unpassable.
        if ((int) $legislature->type_b_seats > 0) {
            $servingB = $serving->filter(fn ($m) => (string) $m->seat_type === 'B')->count();

            if ($servingB === 0) {
                return self::half(0, null, null, 'bicameral chamber with an unseated Type B half — a committee act cannot pass');
            }
        }

        $target = InstitutionScaleService::committeeTarget((int) $legislature->total_seats);
        $existing = self::liveCount('committees', $legislature->id);

        if ($existing >= $target) {
            // At or past the ceiling — never override a governed choice (§6).
            return self::half(0, $target, $existing, 'at target');
        }

        $engine = app(ConstitutionalEngine::class);
        $votes = app(ChamberVoteService::class);
        $seats = max(1, min(self::SEATS_PER_COMMITTEE, $serving->count()));
        $taken = DB::table('committees')->where('legislature_id', $legislature->id)
            ->whereNull('deleted_at')->pluck('name')->all();

        $created = 0;

        foreach (self::COMMITTEE_NAMES as $name) {
            $beat && $beat();
            if ($existing + $created >= $target) {
                break;
            }
            if (in_array($name, $taken, true)) {
                continue;
            }

            try {
                $result = $engine->file('F-LEG-009', $proposerUser, [
                    'legislature_id' => (string) $legislature->id,
                    'name' => $name,
                    'purpose' => "Standing committee on {$name}.",
                    'seats' => $seats,
                ]);

                if (! self::carryVote($votes, $serving, $result->recorded['vote_id'] ?? null)) {
                    return self::half($created, $target, $existing, 'a committee vote did not open');
                }

                $created++;
            } catch (\Throwable $e) {
                return self::half($created, $target, $existing, 'refused: '.$e->getMessage());
            }
        }

        return self::half($created, $target, $existing, null);
    }

    /**
     * The department half — delegate the executive (F-LEG-014, SUPERMAJORITY) if
     * it is still forming, then charter departments toward `D(P)` (F-LEG-016,
     * ordinary majority). Mandatory kinds (Art. II §9) are filled first; the
     * remainder are `other`.
     *
     * @return array{created:int, target:?int, existing:?int, delegated:bool, skipped:?string}
     */
    private static function growDepartments(Legislature $legislature, Collection $serving, User $proposerUser): array
    {
        $executive = Executive::query()
            ->where('jurisdiction_id', $legislature->jurisdiction_id)
            ->whereNull('deleted_at')
            ->first();

        if ($executive === null) {
            return self::deptHalf(0, null, null, false, 'no executive to oversee a department');
        }

        $engine = app(ConstitutionalEngine::class);
        $votes = app(ChamberVoteService::class);

        // An executive that is still `forming` must DELEGATE before it can hold a
        // department (Art. III §1). A committee executive wants 5+ members, so a
        // chamber too small cannot seat one — defer, do not force it.
        if ($executive->status === Executive::STATUS_FORMING) {
            if ($serving->count() <= self::EXEC_COMMITTEE_SIZE) {
                return self::deptHalf(0, null, null, false,
                    'too few seated members to delegate an executive committee (wants 5+)');
            }

            try {
                $result = $engine->file('F-LEG-014', $proposerUser, [
                    'legislature_id' => (string) $legislature->id,
                    'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                    'delegated_scope' => 'Demo growth dial: a delegated executive committee (§5 stage 4).',
                    'member_count' => self::EXEC_COMMITTEE_SIZE,
                    'interest' => [],
                ]);

                self::carryVote($votes, $serving, $result->recorded['vote_id'] ?? null);
            } catch (\Throwable $e) {
                return self::deptHalf(0, null, null, false, 'delegation refused: '.$e->getMessage());
            }

            $executive->refresh();

            if ($executive->status === Executive::STATUS_FORMING) {
                return self::deptHalf(0, null, null, false, 'delegation vote did not adopt');
            }
        }

        if (! in_array($executive->status, [Executive::STATUS_DELEGATED, Executive::STATUS_ELECTED], true)) {
            return self::deptHalf(0, null, null, false, "executive is {$executive->status}, not delegatable");
        }

        $target = InstitutionScaleService::departmentTarget((int) $legislature->jurisdiction?->population);
        $existing = self::liveCount('departments', null, ['jurisdiction_id' => $legislature->jurisdiction_id]);

        if ($existing >= $target) {
            return self::deptHalf(0, $target, $existing, true, 'at target');
        }

        $created = 0;

        foreach (self::departmentPlan($legislature->jurisdiction_id, $target - $existing) as $dept) {
            if ($existing + $created >= $target) {
                break;
            }

            try {
                $result = $engine->file('F-LEG-016', $proposerUser, [
                    'legislature_id' => (string) $legislature->id,
                    'executive_id' => (string) $executive->id,
                    'name' => $dept['name'],
                    'kind' => $dept['kind'],
                    'charter' => [
                        'function_text' => "Carries the {$dept['name']} function of the executive.",
                        'powers_text' => 'As chartered by the seated chamber.',
                        'reporting_interval_months' => 6,
                    ],
                    'owner_seats' => 1,
                    'nominees' => [],
                ]);

                if (! self::carryVote($votes, $serving, $result->recorded['vote_id'] ?? null)) {
                    return self::deptHalf($created, $target, $existing, true, 'a department vote did not open');
                }

                $created++;
            } catch (\Throwable $e) {
                return self::deptHalf($created, $target, $existing, true, 'refused: '.$e->getMessage());
            }
        }

        return self::deptHalf($created, $target, $existing, true, null);
    }

    /**
     * The next departments to charter: mandatory kinds this jurisdiction does not
     * yet hold (a real government needs all five), then `other` for the balance.
     *
     * @return list<array{kind:string, name:string}>
     */
    private static function departmentPlan(string $jurisdictionId, int $need): array
    {
        $held = DB::table('departments')
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('status', '!=', Department::STATUS_DISSOLVED)
            ->whereNull('deleted_at')
            ->pluck('kind')
            ->all();

        $plan = [];

        foreach (self::DEPT_NAMES as $kind => $name) {
            if (! in_array($kind, $held, true)) {
                $plan[] = ['kind' => $kind, 'name' => $name];
            }
        }

        // `other` departments carry the remainder toward D(P). They are the one
        // kind that may repeat (mandatory kinds are unique per jurisdiction).
        $i = 1;
        while (count($plan) < $need) {
            $plan[] = ['kind' => Department::KIND_OTHER, 'name' => "Agency {$i}"];
            $i++;
        }

        return $plan;
    }

    /**
     * Cast every seated member's `yes` until the vote closes. Returns false only
     * if no vote was opened; a vote that opens and closes ADOPTED or not is the
     * chamber's business, not this stage's to force.
     */
    private static function carryVote(ChamberVoteService $votes, Collection $serving, ?string $voteId): bool
    {
        if ($voteId === null) {
            return false;
        }

        $vote = ChamberVote::query()->find($voteId);

        if ($vote === null) {
            return false;
        }

        foreach ($serving as $member) {
            if ($vote->fresh()?->outcome !== null) {
                break; // already closed — do not force further casts
            }

            $votes->cast($vote->fresh(), $member, 'yes');
        }

        return true;
    }

    private static function seatedMembers(Legislature $legislature): Collection
    {
        return LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->whereNull('deleted_at')
            ->where('status', 'elected')
            ->whereNull('vacated_at')
            ->whereNotNull('user_id')
            ->get();
    }

    /** @param array<string,mixed> $where */
    private static function liveCount(string $table, ?string $legislatureId, array $where = []): int
    {
        $q = DB::table($table)->whereNull('deleted_at');

        if ($legislatureId !== null) {
            $q->where('legislature_id', $legislatureId);
        }

        foreach ($where as $col => $val) {
            $q->where($col, $val);
        }

        return $q->count();
    }

    /** @return array{created:int, target:?int, existing:?int, skipped:?string} */
    private static function half(int $created, ?int $target, ?int $existing, ?string $skipped): array
    {
        return ['created' => $created, 'target' => $target, 'existing' => $existing, 'skipped' => $skipped];
    }

    /** @return array{created:int, target:?int, existing:?int, delegated:bool, skipped:?string} */
    private static function deptHalf(int $created, ?int $target, ?int $existing, bool $delegated, ?string $skipped): array
    {
        return ['created' => $created, 'target' => $target, 'existing' => $existing,
            'delegated' => $delegated, 'skipped' => $skipped];
    }

    /**
     * @return array{
     *     committees: array{created:int, target:?int, existing:?int, skipped:string},
     *     departments: array{created:int, target:?int, existing:?int, delegated:bool, skipped:string},
     *     skipped: string
     * }
     */
    private static function bothSkip(string $why): array
    {
        return [
            'committees' => self::half(0, null, null, $why),
            'departments' => self::deptHalf(0, null, null, false, $why),
            'skipped' => $why,
        ];
    }
}
