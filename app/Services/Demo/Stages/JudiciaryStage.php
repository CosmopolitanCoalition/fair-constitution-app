<?php

namespace App\Services\Demo\Stages;

use App\Domain\Engine\ConstitutionalEngine;
use App\Models\ChamberVote;
use App\Models\Judiciary;
use App\Models\JudicialSeat;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\User;
use App\Services\ChamberVoteService;
use App\Services\Judiciary\JudicialSeatService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The JUDICIARY stage (operator order 2026-08-08 — closes the sim gap found
 * answering his bench question: the sim seated chambers, grew committees and
 * departments, and left every courtroom on the planet an empty `forming`
 * shell, because no stage filed F-LEG-017).
 *
 * ⚑ IT DRIVES THE BENCH THROUGH THE REAL FORMS; IT MATERIALISES NOTHING.
 * The judge pool is an act of self-government with a constitutionally DERIVED
 * shape (Art. IV §§1–2, JudiciaryFormationService::applyCreation): a
 * constituent-bearing jurisdiction's bench = judges_per_constituent ×
 * constituents (nomination mode FORCED constituent, round-robin provably
 * equal — Earth: 1 × 232 = 232), floored at min_judges. So this stage files
 * the real F-LEG-017 creation act, the seated members carry the supermajority
 * vote, and then EVERY vacant seat is filled the constitutional way: a
 * per-seat F-LEG-021 nomination by its constituent (nominee = a real resident
 * of that constituent) whose consent vote the chamber carries — the exact
 * sequence PhaseEDemoCommand::standUpJudiciary proves against the pins
 * (10-year CLK-09 terms arm per seat; the court flips appointed only when
 * every seat consents).
 *
 * ⚑ EVERY GATE DEFERS-WITH-REASON (the R_A observance doctrine):
 *   - no seated chamber → skip (stage 3 has not happened);
 *   - bicameral chamber with an unseated Type B half → the supermajority act
 *     cannot pass — skip with reason, never force;
 *   - no `forming` judiciary row → skip (the provisioner's shell has not run
 *     here — eager mode chains it; nothing is minted from this stage);
 *   - a LEAF jurisdiction derives COMMITTEE nomination (no constituents) —
 *     the act states the bench (committee_judge_count = the floor) and seats
 *     fill through committeeNominate(), the same consent pipeline (rubric
 *     sim-leaf-courts = A, 2026-08-08: full courts everywhere BEFORE the
 *     demo);
 *   - a constituent without a resident to nominate defers that seat.
 */
final class JudiciaryStage
{
    private function __construct() {}

    /**
     * @return array{
     *     filed:bool, seats_total:?int, seats_seated:int,
     *     status:?string, skipped:?string
     * }
     */
    public static function run(string $jurisdictionId, ?string $runId, int $version, ?\Closure $beat = null): array
    {
        $legislature = Legislature::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->whereNull('deleted_at')
            ->first();

        if ($legislature === null) {
            return self::skip('no legislature');
        }

        $serving = self::seatedMembers($legislature);
        if ($serving->isEmpty()) {
            return self::skip('chamber not seated');
        }

        // The supermajority creation act is a chamber act — an unseated Type B
        // half makes it unpassable (Art. V §3). Defer, never force.
        if ((int) $legislature->type_b_seats > 0
            && $serving->filter(fn ($m) => (string) $m->seat_type === 'B')->isEmpty()) {
            return self::skip('bicameral chamber with an unseated Type B half — the creation act cannot pass');
        }

        $judiciary = Judiciary::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->whereNull('deleted_at')
            ->first();

        if ($judiciary === null) {
            return self::skip('no forming judiciary — the provisioning shell has not run here');
        }

        if (in_array($judiciary->status, Judiciary::OPERATING_STATUSES, true)) {
            return self::done($judiciary, filed: false, skipped: 'already operating');
        }

        $proposerUser = User::query()->find($serving->first()->user_id);
        if ($proposerUser === null) {
            return self::skip('no seated member with a user to hold the pen');
        }

        $engine = app(ConstitutionalEngine::class);
        $votes = app(ChamberVoteService::class);
        $seatsSvc = app(JudicialSeatService::class);

        // ── 1. File F-LEG-017 (once): forming → creating + the vacant
        //       equal-per-constituent seat pool. ──────────────────────────────
        $filed = false;
        if ($judiciary->status === Judiciary::STATUS_FORMING) {
            $constituents = \App\Services\Judiciary\JudiciaryFormationService::constituentJurisdictionIds($legislature);

            // The floor is the jurisdiction's own setting (bench law), never a
            // literal 5; the court's bench (already the bench law) stands.
            $minJudges = max(
                app(\App\Services\SettingsResolver::class)->resolveInt((string) $jurisdictionId, 'judiciary_min_judges_per_race', 5),
                (int) $judiciary->min_judges,
            );
            $payload = [
                'legislature_id' => (string) $legislature->id,
                'jurisdiction_id' => (string) $jurisdictionId,
                'court_name' => (string) ($judiciary->court_name ?: 'Superior Court'),
                'function_text' => 'Hears the civil, criminal, administrative, and constitutional matters of this jurisdiction (growth dial, §5 stage 3).',
            ];
            if ($constituents !== []) {
                // Constituent mode: the Type B shape — the act picks the
                // multiple; the floor decides the minimum multiple.
                $payload['judges_per_constituent'] = max(1, (int) ceil($minJudges / count($constituents)));
            } else {
                // LEAF (rubric sim-leaf-courts = A, 2026-08-08: full courts
                // everywhere BEFORE the demo): committee nomination mode —
                // the act states the bench, floored at min_judges.
                $payload['committee_judge_count'] = $minJudges;
            }

            try {
                $result = $engine->file('F-LEG-017', $proposerUser, $payload);
            } catch (\Throwable $e) {
                return self::skip('creation refused: '.$e->getMessage());
            }

            if (! self::carryVote($votes, $serving, $result->recorded['vote_id'] ?? null)) {
                return self::skip('the creation vote did not open');
            }

            $judiciary->refresh();
            $filed = true;

            if ($judiciary->status !== Judiciary::STATUS_CREATING) {
                return self::done($judiciary, $filed, 'creation vote closed without adopting');
            }
        }

        // ── 2. Seat the bench: per-seat F-LEG-021 nominate (a real resident of
        //       the nominating constituent) + the consent vote. ───────────────
        $vacant = JudicialSeat::query()
            ->where('judiciary_id', $judiciary->id)
            ->where('status', JudicialSeat::STATUS_VACANT)
            ->orderBy('seat_number')
            ->get();

        $deferredSeats = 0;
        foreach ($vacant as $seat) {
            $beat && $beat();
            // The nominee pool: a constituent-nominated seat draws from ITS
            // constituent's residents (Art. IV §2 — each nominates its own);
            // a committee-nominated seat (leaf court) draws from the
            // jurisdiction's own residents. Same consent pipeline either way.
            $poolJurisdictionId = $seat->seat_class === JudicialSeat::CLASS_CONSTITUENT_NOMINATED
                ? (string) $seat->nominating_jurisdiction_id
                : $jurisdictionId;

            if ($poolJurisdictionId === '') {
                $deferredSeats++;

                continue;
            }

            $nominee = self::residentOf($poolJurisdictionId, $judiciary);
            if ($nominee === null) {
                $deferredSeats++;   // no resident to nominate yet

                continue;
            }

            try {
                $out = $seat->seat_class === JudicialSeat::CLASS_CONSTITUENT_NOMINATED
                    ? $seatsSvc->nominate(
                        $seat,
                        (string) $nominee->id,
                        (string) $seat->nominating_jurisdiction_id,
                    )
                    : $seatsSvc->committeeNominate($seat, (string) $nominee->id);
                self::carryVote($votes, $serving, $out['consent_vote_id'] ?? null);
            } catch (\Throwable $e) {
                $deferredSeats++;

                continue;   // this seat defers; the rest keep seating
            }
        }

        $judiciary->refresh();

        return self::done($judiciary, $filed,
            $deferredSeats > 0 ? "{$deferredSeats} seat(s) deferred" : null);
    }

    /** A nominee: an active resident of the constituent, not already on this bench. */
    private static function residentOf(string $jurisdictionId, Judiciary $judiciary): ?User
    {
        $taken = JudicialSeat::query()
            ->where('judiciary_id', $judiciary->id)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $userId = DB::table('residency_confirmations')
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_active', true)
            ->when($taken !== [], fn ($q) => $q->whereNotIn('user_id', $taken))
            ->value('user_id');

        return $userId !== null ? User::query()->find((string) $userId) : null;
    }

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
                break;
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

    /** @return array{filed:bool, seats_total:?int, seats_seated:int, status:?string, skipped:?string} */
    private static function done(Judiciary $judiciary, bool $filed, ?string $skipped): array
    {
        return [
            'filed' => $filed,
            'seats_total' => JudicialSeat::query()->where('judiciary_id', $judiciary->id)->count(),
            'seats_seated' => JudicialSeat::query()->where('judiciary_id', $judiciary->id)
                ->where('status', JudicialSeat::STATUS_SEATED)->count(),
            'status' => (string) $judiciary->status,
            'skipped' => $skipped,
        ];
    }

    /** @return array{filed:bool, seats_total:?int, seats_seated:int, status:?string, skipped:?string} */
    private static function skip(string $why): array
    {
        return ['filed' => false, 'seats_total' => null, 'seats_seated' => 0,
            'status' => null, 'skipped' => $why];
    }
}
