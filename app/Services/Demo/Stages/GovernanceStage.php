<?php

namespace App\Services\Demo\Stages;

use App\Domain\Engine\ConstitutionalEngine;
use App\Models\ChamberVote;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\User;
use App\Services\ChamberVoteService;
use App\Services\InstitutionScaleService;
use Illuminate\Support\Facades\DB;

/**
 * The GOVERNANCE stage — the growth dial's upper half (SERVICE_SCALE_FORMULA §5).
 *
 * The formula's stages 4 (Maturing) and 5 (Peak) are where a seated chamber
 * grows into the institutions its size warrants: committees toward `K(S)`,
 * departments toward `D(P)`. Until now the sim stopped dead at stage 3
 * (Governing) — its five stages ran cohort → identity → election → counting →
 * seating and then simply stopped, so a demo world had chambers and no committee
 * structure, forever.
 *
 * ⚑ IT DRIVES THE TARGET THROUGH THE REAL FORMS, IT NEVER MATERIALISES IT.
 * Committees are ACTS OF SELF-GOVERNMENT (Art. II §9). Q4 of the formula's
 * rulings (operator, 2026-07-29) is explicit that a provisioning engine may
 * NEVER mint them — they arrive through F-LEG-009 once a chamber is seated and
 * VOTES. So this stage files the real F-LEG-009 through the real
 * ConstitutionalEngine, which opens a real SUPERMAJORITY chamber vote, and the
 * seated members cast real votes until it adopts. The committee row is created
 * by the vote engine's adoption dispatch, exactly as it would be for a live
 * chamber. Nothing here writes a `committees` row.
 *
 * The formula supplies only the TARGET. It is a pre-governance ceiling, never a
 * mandate: a chamber that has voted itself fewer committees than `K(S)` is left
 * alone, because the formula never overrides a governed choice (§6).
 *
 * ⚑ THE BICAMERAL DEFERRAL — the honest skip, not a workaround.
 * `CommitteeService::proposeCreation` treats a chamber as bicameral when
 * `type_b_seats > 0`, and committee creation then has to satisfy the Art. V §3
 * kind split across BOTH halves. A chamber whose Type B half cannot elect (the
 * Type B race structure fix, lane 1) therefore cannot lawfully pass a committee
 * act at all. This stage does not force it, does not fake a member, and does not
 * lower the threshold: it SKIPS and says why. That is the same doctrine
 * `docs/plans/simworld/R_A_OBSERVANCE.md` records for the election stage — the
 * sim defers to the guard, it never fights it. When the Type B half seats, this
 * stage starts working on that chamber with no edit here.
 */
final class GovernanceStage
{
    /** A plausible standing-committee vocabulary; a real chamber names its own. */
    private const NAMES = [
        'Rules', 'Budget', 'Oversight', 'Judiciary', 'Public Works',
        'Health', 'Education', 'Environment', 'Foreign Relations', 'Ethics',
        'Science', 'Labour', 'Housing', 'Transport', 'Culture',
        'Agriculture', 'Energy', 'Trade', 'Defence', 'Technology',
        'Water', 'Heritage', 'Youth', 'Elections',
    ];

    /** A committee wants about five members (§4.4's staffing clamp). */
    private const SEATS_PER_COMMITTEE = 5;

    private function __construct() {}

    /**
     * Advance one jurisdiction's chamber toward its committee target.
     *
     * @return array{created: int, target: ?int, existing: ?int, skipped: ?string}
     */
    public static function run(string $jurisdictionId, ?string $runId, int $version): array
    {
        $legislature = Legislature::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->whereNull('deleted_at')
            ->first();

        if ($legislature === null) {
            return self::skip('no legislature');
        }

        $serving = LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->whereNull('deleted_at')
            ->where('status', 'elected')
            ->whereNull('vacated_at')
            ->whereNotNull('user_id')
            ->get();

        if ($serving->isEmpty()) {
            // Stage 3 has not happened yet. Nothing to grow into.
            return self::skip('chamber not seated');
        }

        // THE BICAMERAL DEFERRAL. A committee act must satisfy the Art. V §3
        // kind split, so an unseated Type B half makes it unpassable. Skip
        // honestly rather than force it.
        if ((int) $legislature->type_b_seats > 0) {
            $servingB = $serving->filter(fn ($m) => (string) $m->seat_type === 'B')->count();

            if ($servingB === 0) {
                return self::skip('bicameral chamber with an unseated Type B half — a committee act cannot pass');
            }
        }

        $target = InstitutionScaleService::committeeTarget((int) $legislature->total_seats);

        $existing = DB::table('committees')
            ->where('legislature_id', $legislature->id)
            ->whereNull('deleted_at')
            ->count();

        if ($existing >= $target) {
            // At or past the ceiling. A chamber that voted itself MORE than the
            // formula's target keeps them — the formula never overrides a
            // governed choice.
            return ['created' => 0, 'target' => $target, 'existing' => $existing, 'skipped' => 'at target'];
        }

        $engine = app(ConstitutionalEngine::class);
        $votes = app(ChamberVoteService::class);

        // The proposer must be a seated member (F-LEG-009 requires R-09).
        $proposer = $serving->first();
        $proposerUser = User::query()->find($proposer->user_id);

        if ($proposerUser === null) {
            return self::skip('no seated member with a user to hold the pen');
        }

        // A committee cannot be seated by more members than the chamber has.
        $seats = max(1, min(self::SEATS_PER_COMMITTEE, $serving->count()));

        $taken = DB::table('committees')
            ->where('legislature_id', $legislature->id)
            ->whereNull('deleted_at')
            ->pluck('name')
            ->all();

        $created = 0;

        foreach (self::NAMES as $name) {
            if ($existing + $created >= $target) {
                break;
            }

            if (in_array($name, $taken, true)) {
                continue;
            }

            try {
                // The REAL form. This opens a supermajority chamber vote; it
                // does NOT create a committee.
                $result = $engine->file('F-LEG-009', $proposerUser, [
                    'legislature_id' => (string) $legislature->id,
                    'name' => $name,
                    'purpose' => "Standing committee on {$name}.",
                    'seats' => $seats,
                ]);

                $voteId = $result->recorded['vote_id'] ?? null;

                if ($voteId === null) {
                    return self::partial($created, $target, $existing, 'F-LEG-009 opened no vote');
                }

                $vote = ChamberVote::query()->find($voteId);

                if ($vote === null) {
                    return self::partial($created, $target, $existing, 'the opened vote is missing');
                }

                // Every seated member casts. The vote auto-closes the moment the
                // supermajority threshold is met — we do not close it ourselves.
                foreach ($serving as $member) {
                    if ($vote->fresh()?->outcome !== null) {
                        break;
                    }

                    $votes->cast($vote->fresh(), $member, 'yes');
                }

                $created++;
            } catch (\Throwable $e) {
                // A refusal is an ANSWER, not a crash: record it and stop, so the
                // reason reaches the run summary instead of a stack trace.
                return self::partial($created, $target, $existing, 'refused: '.$e->getMessage());
            }
        }

        return ['created' => $created, 'target' => $target, 'existing' => $existing, 'skipped' => null];
    }

    /** @return array{created: int, target: null, existing: null, skipped: string} */
    private static function skip(string $why): array
    {
        return ['created' => 0, 'target' => null, 'existing' => null, 'skipped' => $why];
    }

    /** @return array{created: int, target: int, existing: int, skipped: string} */
    private static function partial(int $created, int $target, int $existing, string $why): array
    {
        return ['created' => $created, 'target' => $target, 'existing' => $existing, 'skipped' => $why];
    }
}
