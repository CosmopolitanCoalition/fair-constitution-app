<?php

namespace App\Services\Education;

use App\Domain\Engine\ConstitutionalEngine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SEATED-MEMBER PRE-TRAIN (operator ruling Option A, Wave 4 §① — "seated
 * members are trained"). Files F-EDU-001 through the engine for every
 * currently-seated role-holder, exactly as if they had passed the
 * comprehension check, so a played/demo world's members are not all
 * redirected to Learn the instant education:seed arms the box.
 *
 * WHY A SERVICE, NOT A SEEDER HOOK. Members reach a seat by many roads —
 * the sim pipeline, the phase demo commands, a real election's
 * certification. A backfill that ENUMERATES the seated tables from the DB
 * is source-agnostic: it trains whoever is seated, however they got there,
 * and never has to touch another lane's seeding code. education:seed calls
 * it after publishing content (publish → arm → train, the only order the
 * live-module rule permits); any seeder may call it too — it is a no-op
 * until content is live (below).
 *
 * THE ARMING PRECONDITION (a redirect needs a destination, §5.2). Only a
 * track with a live published module is touched. A holder of an unarmed
 * track is left alone — there is nothing to learn there yet and the gate is
 * open anyway. This is exactly why the service is a SAFE no-op on any box
 * that has not run education:seed, and why the fixture corpus stays green.
 *
 * IDEMPOTENT / RESUMABLE. A holder who already has an accepted F-EDU-001
 * for the track is skipped, so re-running arms only the newly-seated and
 * mints no second achievement or stipend. Each filing is the engine's own
 * one transaction (no giant wrapping statement — THE ETL RULE); progress is
 * emitted per chunk. A partial run is safely completed by re-running.
 */
class SeatedMemberTrainingService
{
    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly TrainingGateService $gate,
    ) {
    }

    /**
     * Every currently-seated role-holder paired with the curriculum track
     * their role's acts are gated behind.
     *
     * MIRRORS RoleService's fact queries exactly (the seated-status filters,
     * the dissolved/deleted guards): a holder appears here iff RoleService
     * would derive the matching institutional role, so the pre-train pass
     * covers precisely the users the gate would otherwise redirect. Speaker
     * and committee holders ride the legislature seat (their authority IS
     * chamber authority — tracks_by_role R-10..R-12 → legislature). Advisors
     * (R-17) and jurors (R-22) file no gated form and are intentionally
     * absent — training them would mint a stipend for a role that never
     * meets the gate. Pinned against RoleService by SeatedMemberTrainingTest.
     *
     * @return list<array{user_id: string, track: string}>
     */
    public function seatedHolderTracks(): array
    {
        $rows = [];

        $push = static function (iterable $ids, string $track) use (&$rows): void {
            foreach ($ids as $uid) {
                $rows[] = ['user_id' => (string) $uid, 'track' => $track];
            }
        };

        // Legislature (R-09..R-12) — one track for the whole chamber.
        $push(DB::table('legislature_members')
            ->whereIn('status', ['elected', 'seated'])->whereNull('deleted_at')
            ->whereNotNull('user_id')->distinct()->pluck('user_id'), 'legislature');

        // Election board (R-08) — seated members of an ACTIVE board (the
        // synthetic bootstrap member carries user_id NULL and is skipped).
        $push(DB::table('election_board_members as m')
            ->join('election_boards as b', 'b.id', '=', 'm.election_board_id')
            ->where('m.status', 'seated')->whereNull('m.deleted_at')->whereNotNull('m.user_id')
            ->where('b.status', 'active')->whereNull('b.deleted_at')
            ->distinct()->pluck('m.user_id'), 'election_board');

        // Executive (R-14..R-16) — seated principals of a live executive.
        $push(DB::table('executive_members as em')
            ->join('executives as e', 'e.id', '=', 'em.executive_id')
            ->where('em.role', 'principal')->where('em.status', 'seated')->whereNull('em.deleted_at')
            ->whereNotNull('em.user_id')
            ->where('e.status', '!=', 'dissolved')->whereNull('e.deleted_at')
            ->distinct()->pluck('em.user_id'), 'executive');

        // Board of Governors (R-18) — seated department-board seats.
        $push(DB::table('board_seats as s')
            ->join('boards as b', 'b.id', '=', 's.board_id')
            ->where('s.status', 'seated')->whereNull('s.deleted_at')->whereNotNull('s.holder_user_id')
            ->where('b.boardable_type', 'departments')->where('b.status', '!=', 'dissolved')->whereNull('b.deleted_at')
            ->distinct()->pluck('s.holder_user_id'), 'board_of_governors');

        // Judiciary (R-19/R-20) — seated judicial seats, either type.
        $push(DB::table('judicial_seats as s')
            ->join('judiciaries as j', 'j.id', '=', 's.judiciary_id')
            ->where('s.status', 'seated')->whereNull('s.deleted_at')->whereNotNull('s.user_id')
            ->where('j.status', '!=', 'dissolved')->whereNull('j.deleted_at')
            ->distinct()->pluck('s.user_id'), 'judiciary');

        // Advocates (R-21) — registered bar members.
        $push(DB::table('advocates as a')
            ->join('judiciaries as j', 'j.id', '=', 'a.judiciary_id')
            ->where('a.status', 'registered')->whereNull('a.deleted_at')->whereNotNull('a.user_id')
            ->where('j.status', '!=', 'dissolved')->whereNull('j.deleted_at')
            ->distinct()->pluck('a.user_id'), 'advocate');

        return $rows;
    }

    /**
     * The seated holders of ONE jurisdiction, scoped to its own institutions.
     * The sim trains per jurisdiction so the pass is naturally bounded (a
     * chamber, a board, an executive, a bench, its advocates — tens of rows,
     * never the planet's millions in memory: THE ETL RULE, chunked by scope).
     *
     * @return list<array{user_id: string, track: string}>
     */
    public function seatedHolderTracksForJurisdiction(string $jurisdictionId): array
    {
        $rows = [];

        $push = static function (iterable $ids, string $track) use (&$rows): void {
            foreach ($ids as $uid) {
                $rows[] = ['user_id' => (string) $uid, 'track' => $track];
            }
        };

        $push(DB::table('legislature_members as m')
            ->join('legislatures as l', 'l.id', '=', 'm.legislature_id')
            ->where('l.jurisdiction_id', $jurisdictionId)->whereNull('l.deleted_at')
            ->whereIn('m.status', ['elected', 'seated'])->whereNull('m.deleted_at')
            ->whereNotNull('m.user_id')->distinct()->pluck('m.user_id'), 'legislature');

        $push(DB::table('election_board_members as m')
            ->join('election_boards as b', 'b.id', '=', 'm.election_board_id')
            ->where('b.jurisdiction_id', $jurisdictionId)
            ->where('m.status', 'seated')->whereNull('m.deleted_at')->whereNotNull('m.user_id')
            ->where('b.status', 'active')->whereNull('b.deleted_at')
            ->distinct()->pluck('m.user_id'), 'election_board');

        $push(DB::table('executive_members as em')
            ->join('executives as e', 'e.id', '=', 'em.executive_id')
            ->where('e.jurisdiction_id', $jurisdictionId)
            ->where('em.role', 'principal')->where('em.status', 'seated')->whereNull('em.deleted_at')
            ->whereNotNull('em.user_id')
            ->where('e.status', '!=', 'dissolved')->whereNull('e.deleted_at')
            ->distinct()->pluck('em.user_id'), 'executive');

        $push(DB::table('board_seats as s')
            ->join('boards as b', 'b.id', '=', 's.board_id')
            ->join('departments as d', 'd.id', '=', 'b.boardable_id')
            ->where('d.jurisdiction_id', $jurisdictionId)
            ->where('b.boardable_type', 'departments')
            ->where('s.status', 'seated')->whereNull('s.deleted_at')->whereNotNull('s.holder_user_id')
            ->where('b.status', '!=', 'dissolved')->whereNull('b.deleted_at')
            ->distinct()->pluck('s.holder_user_id'), 'board_of_governors');

        $push(DB::table('judicial_seats as s')
            ->join('judiciaries as j', 'j.id', '=', 's.judiciary_id')
            ->where('j.jurisdiction_id', $jurisdictionId)
            ->where('s.status', 'seated')->whereNull('s.deleted_at')->whereNotNull('s.user_id')
            ->where('j.status', '!=', 'dissolved')->whereNull('j.deleted_at')
            ->distinct()->pluck('s.user_id'), 'judiciary');

        $push(DB::table('advocates as a')
            ->join('judiciaries as j', 'j.id', '=', 'a.judiciary_id')
            ->where('j.jurisdiction_id', $jurisdictionId)
            ->where('a.status', 'registered')->whereNull('a.deleted_at')->whereNotNull('a.user_id')
            ->where('j.status', '!=', 'dissolved')->whereNull('j.deleted_at')
            ->distinct()->pluck('a.user_id'), 'advocate');

        return $rows;
    }

    /**
     * Pre-train every seated holder whose track is armed and not yet
     * completed. Returns the tally; emits per-chunk progress through $emit.
     *
     * @param  callable(string):void|null  $emit  progress sink
     * @return array{holders:int,filed:int,already:int,unarmed:int,failed:int}
     */
    public function armSeatedMembers(?callable $emit = null): array
    {
        return $this->armHolderTracks($this->seatedHolderTracks(), $emit);
    }

    /**
     * Pre-train the seated holders of ONE jurisdiction — the sim's per-scope
     * door, bounded and idempotent (a re-run skips holders already trained).
     *
     * @return array{holders:int,filed:int,already:int,unarmed:int,failed:int}
     */
    public function armForJurisdiction(string $jurisdictionId, ?callable $emit = null, ?\Closure $beat = null): array
    {
        return $this->armHolderTracks($this->seatedHolderTracksForJurisdiction($jurisdictionId), $emit, $beat);
    }

    /**
     * Arm a given holder set — the shared engine behind the global and the
     * per-jurisdiction doors.
     *
     * @param  list<array{user_id:string,track:string}>  $holders
     * @param  callable(string):void|null  $emit
     * @return array{holders:int,filed:int,already:int,unarmed:int,failed:int}
     */
    private function armHolderTracks(array $holders, ?callable $emit = null, ?\Closure $beat = null): array
    {
        $emit ??= static fn (string $line) => null;

        $moduleByTrack = $this->moduleKeyByTrack();
        $armed = [];                       // track → hasLiveTraining, cached
        $seen = [];                        // user|track dedupe across sources
        $counts = ['holders' => 0, 'filed' => 0, 'already' => 0, 'unarmed' => 0, 'failed' => 0];
        $processed = 0;

        foreach ($holders as $holder) {
            $beat && $beat();
            $key = $holder['user_id'].'|'.$holder['track'];

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $counts['holders']++;
            $track = $holder['track'];

            $armed[$track] ??= $this->gate->hasLiveTraining($track);

            if (! $armed[$track] || ! isset($moduleByTrack[$track])) {
                $counts['unarmed']++;

                continue;
            }

            $user = User::find($holder['user_id']);

            if ($user === null) {
                $counts['failed']++;

                continue;
            }

            if ($this->gate->hasCompleted($user, $track)) {
                $counts['already']++;

                continue;
            }

            try {
                // The engine wraps each filing in its own transaction —
                // one committed chunk per member, never a planet-wide one.
                $this->engine->file('F-EDU-001', $user, [
                    'track_key'  => $track,
                    'module_key' => $moduleByTrack[$track],
                    'passed'     => true,
                    'score_pct'  => 100,
                ]);
                $counts['filed']++;
            } catch (Throwable $e) {
                $counts['failed']++;
                $emit("  ! {$track} holder {$holder['user_id']} — filing failed: {$e->getMessage()}");
            }

            if (++$processed % 50 === 0) {
                $emit("  … {$processed} armed holders processed ({$counts['filed']} newly trained)");
            }
        }

        return $counts;
    }

    /** track → its first live module key, from the server catalog. */
    private function moduleKeyByTrack(): array
    {
        $out = [];

        foreach (config('cga.education.content', []) as $track => $def) {
            $first = $def['modules'][0]['key'] ?? null;

            if (is_string($first) && $first !== '') {
                $out[$track] = $first;
            }
        }

        return $out;
    }
}
