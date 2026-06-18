<?php

namespace App\Services;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Election;
use App\Models\EmergencyPower;
use App\Models\MultiJurisdictionVote;
use App\Models\Vacancy;
use Illuminate\Support\Facades\DB;

/**
 * G-VER — the "game in progress" FREEZE (Art. II §7 non-disruption). A
 * constitutional_version bump must NEVER silently re-rule a process in flight, so
 * it is BLOCKED for a jurisdiction SUBTREE while any live constitutional process
 * exists in it. Like the mirror write-guard, it does not stop the world — citizens
 * keep voting, sessions keep meeting; it blocks exactly one thing: applying the
 * upgrade. Reads only — statuses + ids, never the protected triad.
 *
 * Live predicates (each an existing queryable state):
 *   - an uncertified election (before F-ELB-004 certification)
 *   - an open MJV / dual-supermajority process
 *   - a live emergency power (CLK-03 window) — over the subtree OR a covering ancestor
 *   - an in-flight vacancy fill (countback / special-election window, CLK-04)
 * [Judicial CLK-11/12 windows are a noted extension — add to detectLiveProcesses().]
 */
class UpgradeFreezeService
{
    /** Election statuses that are NOT yet certified — the contest is still live. */
    private const ELECTION_LIVE = [
        Election::STATUS_SCHEDULED, Election::STATUS_APPROVAL_OPEN, Election::STATUS_FINALIST_CUTOFF,
        Election::STATUS_RANKED_OPEN, Election::STATUS_VOTING_CLOSED, Election::STATUS_TABULATING,
        Election::STATUS_AUDIT_RERUN,
    ];

    /** Vacancy statuses where a seat is being filled (countback/special) under the original rules. */
    private const VACANCY_LIVE = [
        Vacancy::STATUS_DECLARED, Vacancy::STATUS_COUNTBACK_RUNNING,
        Vacancy::STATUS_COUNTBACK_FAILED, Vacancy::STATUS_SPECIAL_SCHEDULED,
    ];

    /**
     * Every live constitutional process within the subtree (empty = thawed).
     *
     * @return list<array{kind:string,ref:string}>
     */
    public function detectLiveProcesses(string $rootJurisdictionId): array
    {
        $subtree = $this->descendantIds($rootJurisdictionId);
        if ($subtree === []) {
            return [];
        }

        $tag = fn (string $kind): callable => fn ($id): array => ['kind' => $kind, 'ref' => (string) $id];

        $elections = Election::query()
            ->whereIn('status', self::ELECTION_LIVE)
            ->whereIn('jurisdiction_id', $subtree)
            ->pluck('id')->map($tag('election'))->all();

        $mjvs = MultiJurisdictionVote::query()
            ->where('status', MultiJurisdictionVote::STATUS_OPEN)
            ->where(fn ($q) => $q
                ->whereHas('initiatingLegislature', fn ($l) => $l->whereIn('jurisdiction_id', $subtree))
                ->orWhereHas('consents', fn ($c) => $c->whereIn('jurisdiction_id', $subtree)))
            ->pluck('id')->map($tag('multi_jurisdiction_vote'))->all();

        // A power over the subtree OR over an ancestor (which covers the subtree).
        $emergencyScope = array_values(array_unique(array_merge($subtree, $this->ancestorIds($rootJurisdictionId))));
        $emergencies = EmergencyPower::query()
            ->whereIn('status', EmergencyPower::LIVE_STATUSES)
            ->whereIn('area_jurisdiction_id', $emergencyScope)
            ->pluck('id')->map($tag('emergency_power'))->all();

        $vacancies = Vacancy::query()
            ->whereIn('status', self::VACANCY_LIVE)
            ->whereIn('jurisdiction_id', $subtree)
            ->pluck('id')->map($tag('vacancy'))->all();

        return array_merge($elections, $mjvs, $emergencies, $vacancies);
    }

    public function isFrozen(string $rootJurisdictionId): bool
    {
        return $this->detectLiveProcesses($rootJurisdictionId) !== [];
    }

    /** Refuse (Art. II §7) unless the subtree has no live process. */
    public function assertThawed(string $rootJurisdictionId): void
    {
        $live = $this->detectLiveProcesses($rootJurisdictionId);
        if ($live === []) {
            return;
        }

        $kinds = implode(', ', array_values(array_unique(array_map(fn ($l) => $l['kind'], $live))));

        throw new ConstitutionalViolation(
            'A constitutional-version upgrade cannot disrupt a process in flight ('
            .count($live)." live: {$kinds}). Wait for the thaw, or scope the upgrade to a quiet subtree.",
            'Art. II §7',
        );
    }

    /** Root + all descendants (recursive; soft-deletes excluded). */
    private function descendantIds(string $root): array
    {
        $rows = DB::select(
            'WITH RECURSIVE jh AS ('
            .'   SELECT id FROM jurisdictions WHERE id = ? AND deleted_at IS NULL'
            .'   UNION ALL'
            .'   SELECT j.id FROM jurisdictions j JOIN jh ON j.parent_id = jh.id WHERE j.deleted_at IS NULL'
            .' ) SELECT id FROM jh',
            [$root]
        );

        return array_map(fn ($r) => (string) $r->id, $rows);
    }

    /** Root + all ancestors (recursive; soft-deletes excluded). */
    private function ancestorIds(string $root): array
    {
        $rows = DB::select(
            'WITH RECURSIVE ja AS ('
            .'   SELECT id, parent_id FROM jurisdictions WHERE id = ? AND deleted_at IS NULL'
            .'   UNION ALL'
            .'   SELECT j.id, j.parent_id FROM jurisdictions j JOIN ja ON j.id = ja.parent_id WHERE j.deleted_at IS NULL'
            .' ) SELECT id FROM ja',
            [$root]
        );

        return array_map(fn ($r) => (string) $r->id, $rows);
    }
}
