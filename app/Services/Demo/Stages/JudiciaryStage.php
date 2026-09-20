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
use App\Support\HostCapacity;
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
 * per-seat F-LEG-021 nomination by its constituent (nominee = an eligible
 * resident of the court's jurisdiction) whose consent vote the chamber carries — the exact
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
 *   - an exhausted court-wide eligible resident pool defers remaining seats.
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
            && $serving->filter(fn ($m) => $m->seatKind() === 'type_b')->isEmpty()) {
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
        // constituent's residents as a preference; eligibility is the court's
        // jurisdiction (JudicialNominationService::nominee). A
        // committee-nominated seat (leaf court) draws from the jurisdiction's
        // own residents. Same consent pipeline either way, one consent vote per
        // seat (the constitutional per-seat consent is unchanged here).
        $mSeat = hrtime(true);
        $vacant = JudicialSeat::query()
            ->where('judiciary_id', $judiciary->id)
            ->where('status', JudicialSeat::STATUS_VACANT)
            ->orderBy('seat_number')
            ->get();

        // NOMINEE PREFETCH. Each distinct jurisdiction gets one bounded index
        // probe; several pools share a database round trip. The old residentOf()
        // re-plucked the growing `taken` set and looked up residents per seat.
        // Each probe fetches only as many distinct residents as that pool has
        // seats to fill, ordered by user_id for a deterministic assignment,
        // and excludes residents already on this bench. Local assignments are
        // preserved before the bounded court-jurisdiction fallback below.
        // F-LEG-021 eligibility and the consent process remain unchanged.
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

        $mPools = hrtime(true);
        $poolQueues = self::residentPools($needByPool, (string) $judiciary->id, $beat);
        SimTimer::record('judiciary.resident_pools', (int) ((hrtime(true) - $mPools) / 1000));

        $mNominate = hrtime(true);
        $assigned = []; $nominees = []; $short = [];
        $deferredSeats = 0;
        foreach ($vacant as $seat) {
            $beat && $beat();
            $poolJurisdictionId = $poolOf($seat);

            if ($poolJurisdictionId === '') {
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
                $short[] = $seat->id;
                continue;
            }

            $assigned[$nomineeId] = true;
            $nominees[$seat->id] = $nomineeId;
        }

        if ($short !== []) {
            // The real F-LEG-037/F-LEG-021 eligibility rule is association
            // with the COURT jurisdiction, not the nominating constituent.
            // Preserve every preferred local nominee first, then fill only
            // shortages from the court's remaining distinct residents.
            $fallback = self::residentPools([$jurisdictionId => count($short) + count($assigned)], (string) $judiciary->id, $beat)[$jurisdictionId];
            $fallback = array_values(array_filter($fallback, fn ($id) => ! isset($assigned[$id])));
            foreach ($short as $seatId) {
                if ($fallback === []) { break; }
                $id = array_shift($fallback); $assigned[$id] = true; $nominees[$seatId] = $id;
            }
        }

        foreach ($vacant as $seat) {
            $beat && $beat();
            $nomineeId = $nominees[$seat->id] ?? null;
            if ($nomineeId === null) { $deferredSeats++; continue; }

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
        SimTimer::record('judiciary.stage_nominations', (int) ((hrtime(true) - $mNominate) / 1000));

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
            $mConsent = hrtime(true);
            $slateVote = $seatsSvc->openSlateConsent($judiciary);
            self::carryVote($votes, $serving, (string) $slateVote->id);
            $slateVote->refresh();
            SimTimer::record('judiciary.slate_consent', (int) ((hrtime(true) - $mConsent) / 1000));

            if ((string) $slateVote->outcome === ChamberVote::OUTCOME_ADOPTED) {
                $mAdopt = hrtime(true);
                $seatsSvc->seatSlateOnAdoption($judiciary);
                SimTimer::record('judiciary.slate_seating', (int) ((hrtime(true) - $mAdopt) / 1000));
            }
        }
        SimTimer::record('judiciary.seat', (int) ((hrtime(true) - $mSeat) / 1000));

        $judiciary->refresh();

        return self::done($judiciary, $filed,
            $deferredSeats > 0 ? "{$deferredSeats} seat(s) deferred" : null);
    }

    /**
     * The same ordered, distinct, seat-limited roster per constituent, returned
     * in bounded source batches instead of a database round trip per pool.
     * The bench exclusion is a NULL-free, uncorrelated subquery: PostgreSQL can
     * hash it once per statement, without transporting a growing ID list or
     * rescanning the bench for each resident. All rosters are read before any
     * nomination, preserving the existing cross-pool assignment/deferral rules.
     *
     * @param array<string,int> $needByPool
     * @return array<string,list<string>>
     */
    private static function residentPools(array $needByPool, string $judiciaryId, ?\Closure $beat): array
    {
        $queues = array_fill_keys(array_keys($needByPool), []);
        foreach (array_chunk($needByPool, max(1, HostCapacity::sweepChunk()), true) as $pools) {
            $beat && $beat();
            $source = [];
            foreach ($pools as $jurisdictionId => $needed) {
                $source[] = ['jurisdiction_id' => $jurisdictionId, 'needed' => $needed];
            }
            $rows = DB::select('
                SELECT pools.jurisdiction_id, resident.user_id
                  FROM jsonb_to_recordset(?::jsonb) AS pools(jurisdiction_id uuid, needed integer)
                 CROSS JOIN LATERAL (
                     SELECT DISTINCT r.user_id
                       FROM residency_confirmations r
                      WHERE r.jurisdiction_id = pools.jurisdiction_id AND r.is_active = true
                        AND r.user_id NOT IN (
                            SELECT taken.user_id FROM judicial_seats taken
                             WHERE taken.judiciary_id = ?::uuid AND taken.deleted_at IS NULL
                               AND taken.user_id IS NOT NULL
                        )
                      ORDER BY r.user_id LIMIT pools.needed
                 ) resident
                 ORDER BY pools.jurisdiction_id, resident.user_id
            ', [json_encode($source, JSON_THROW_ON_ERROR), $judiciaryId]);
            foreach ($rows as $row) {
                $queues[(string) $row->jurisdiction_id][] = (string) $row->user_id;
            }
        }

        return $queues;
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
        $counts = JudicialSeat::query()->where('judiciary_id', $judiciary->id)
            ->selectRaw('COUNT(*) AS total, COUNT(CASE WHEN status = ? THEN 1 END) AS seated', [JudicialSeat::STATUS_SEATED])
            ->first();

        return [
            'filed' => $filed,
            'seats_total' => (int) $counts->total,
            'seats_seated' => (int) $counts->seated,
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
