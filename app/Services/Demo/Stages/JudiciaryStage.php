<?php

namespace App\Services\Demo\Stages;

use App\Domain\Engine\ConstitutionalEngine;
use App\Models\ChamberVote;
use App\Models\Judiciary;
use App\Models\JudicialNomination;
use App\Models\JudicialSeat;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\User;
use App\Services\ChamberVoteService;
use App\Services\Judiciary\JudicialSeatService;
use App\Support\SimTimer;
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
            $mDerive = hrtime(true);
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
            SimTimer::record('judiciary.derive', (int) ((hrtime(true) - $mDerive) / 1000));

            $mFile = hrtime(true);
            try {
                $result = $engine->file('F-LEG-017', $proposerUser, $payload);
                SimTimer::record('judiciary.file_creation', (int) ((hrtime(true) - $mFile) / 1000));
            } catch (\Throwable $e) {
                return self::skip('creation refused: '.$e->getMessage());
            }

            $mVote = hrtime(true);
            if (! self::carryVote($votes, $serving, $result->recorded['vote_id'] ?? null)) {
                return self::skip('the creation vote did not open');
            }
            SimTimer::record('judiciary.creation_vote', (int) ((hrtime(true) - $mVote) / 1000));

            $judiciary->refresh();
            $filed = true;

            if ($judiciary->status !== Judiciary::STATUS_CREATING) {
                return self::done($judiciary, $filed, 'creation vote closed without adopting');
            }
        }

        // ── 2. Seat the bench: per-seat F-LEG-021 nominate (a real resident of
        //       the nominating constituent) + the consent vote. ───────────────
        // The nominee pool: a constituent-nominated seat draws from ITS
        // constituent's residents (Art. IV §2 — each nominates its own); a
        // committee-nominated seat (leaf court) draws from the jurisdiction's
        // own residents. Same consent pipeline either way, one consent vote per
        // seat (the constitutional per-seat consent is unchanged here).
        $mSeat = hrtime(true);
        $vacant = JudicialSeat::query()
            ->where('judiciary_id', $judiciary->id)
            ->where('status', JudicialSeat::STATUS_VACANT)
            ->orderBy('seat_number')
            ->get();

        // NOMINEE PREFETCH (overhead cut 2026-09-08). Nominee selection reads
        // the residency roster ONCE per distinct pool jurisdiction and the
        // already-seated set ONCE for the whole bench, instead of per seat. The
        // old residentOf() re-plucked the growing `taken` set and ran a fresh
        // residency lookup on every seat — O(seats^2) reads on a large bench.
        // Each pool query fetches only as many distinct residents as that pool
        // has seats to fill, ordered by user_id for a deterministic assignment,
        // and excludes residents already on this bench. Assignment is otherwise
        // identical: each seat gets a distinct active resident of its pool, or
        // defers when the pool is dry. Nothing about the F-LEG-021 nomination or
        // its consent vote changes; this only removes read overhead.
        $poolOf = static fn ($seat): string => $seat->seat_class === JudicialSeat::CLASS_CONSTITUENT_NOMINATED
            ? (string) $seat->nominating_jurisdiction_id
            : $jurisdictionId;

        $needByPool = [];
        foreach ($vacant as $seat) {
            $pool = $poolOf($seat);
            if ($pool !== '') {
                $needByPool[$pool] = ($needByPool[$pool] ?? 0) + 1;
            }
        }

        // Residents already on this bench — excluded once, not re-read per seat.
        $taken = JudicialSeat::query()
            ->where('judiciary_id', $judiciary->id)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        // One roster read per pool jurisdiction: its distinct active residents,
        // capped at the seats that pool must fill.
        $poolQueues = [];
        foreach ($needByPool as $pool => $need) {
            $poolQueues[$pool] = DB::table('residency_confirmations')
                ->where('jurisdiction_id', $pool)
                ->where('is_active', true)
                ->when($taken !== [], fn ($q) => $q->whereNotIn('user_id', $taken))
                ->distinct()
                ->orderBy('user_id')
                ->limit($need)
                ->pluck('user_id')
                ->map(fn ($id) => (string) $id)
                ->all();
        }

        $assigned = [];
        $deferredSeats = 0;
        foreach ($vacant as $seat) {
            $beat && $beat();
            $poolJurisdictionId = $poolOf($seat);

            if ($poolJurisdictionId === '') {
                $deferredSeats++;

                continue;
            }

            // Next distinct, not-yet-assigned resident of this pool. `$assigned`
            // guards the rare resident who appears in two pools' rosters.
            $nomineeId = null;
            while (! empty($poolQueues[$poolJurisdictionId])) {
                $cand = array_shift($poolQueues[$poolJurisdictionId]);
                if (! isset($assigned[$cand])) {
                    $nomineeId = $cand;

                    break;
                }
            }

            if ($nomineeId === null) {
                $deferredSeats++;   // no resident to nominate yet

                continue;
            }

            $assigned[$nomineeId] = true;

            // Stage the nomination (no per-seat vote); the bench consents once
            // below. The VACANT guard and nominee association are enforced inside.
            try {
                $seatsSvc->stageSlateNomination(
                    $seat,
                    $nomineeId,
                    $seat->seat_class === JudicialSeat::CLASS_CONSTITUENT_NOMINATED
                        ? JudicialNomination::MODE_CONSTITUENT
                        : JudicialNomination::MODE_COMMITTEE,
                    $seat->seat_class === JudicialSeat::CLASS_CONSTITUENT_NOMINATED
                        ? (string) $seat->nominating_jurisdiction_id
                        : null,
                );
            } catch (\Throwable $e) {
                $deferredSeats++;

                continue;   // this seat defers; the rest keep staging
            }
        }

        // ONE consent vote for the whole slate (operator ruling 2026-09-08 — a
        // chamber may consent to a bench at once and vote it down to go per-seat
        // on objection). This collapses the J-seats × M-members cast + per-cast
        // record pole to a single M-member cast. Every check holds: association
        // at staging, majority of ALL serving on this vote, equal-per-constituent
        // on advance. Seat only on adoption; a voted-down slate leaves the seats
        // nominated. The NOMINATED query also recovers a prior partial run's seats.
        $hasNominated = JudicialSeat::query()
            ->where('judiciary_id', $judiciary->id)
            ->where('status', JudicialSeat::STATUS_NOMINATED)
            ->exists();

        if ($hasNominated) {
            $slateVote = $seatsSvc->openSlateConsent($judiciary);
            self::carryVote($votes, $serving, (string) $slateVote->id);
            $slateVote->refresh();

            if ((string) $slateVote->outcome === ChamberVote::OUTCOME_ADOPTED) {
                $seatsSvc->seatSlateOnAdoption($judiciary);
            }
        }
        SimTimer::record('judiciary.seat', (int) ((hrtime(true) - $mSeat) / 1000));

        $judiciary->refresh();

        return self::done($judiciary, $filed,
            $deferredSeats > 0 ? "{$deferredSeats} seat(s) deferred" : null);
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

        // Bulk cast — every serving member votes 'yes' in ONE locked pass
        // (castManyYes is provably tally-identical to the per-member loop, but
        // locks the vote once and bulk-inserts the casts). Speaker neutrality
        // and the auto-close at full participation are handled inside.
        $votes->castManyYes($vote, $serving);

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
