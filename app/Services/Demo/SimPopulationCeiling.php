<?php

namespace App\Services\Demo;

use App\Models\{Election, Legislature, SimRun};
use App\Services\AuditService;
use App\Support\{HostCapacity, QuorumLaw};
use Illuminate\Support\Facades\DB;

/** Correct an uncounted synthetic panel allocation, never an elected office. */
class SimPopulationCeiling
{
    /** Read-only evidence; unknown population is not zero. */
    public function adjustments(Election $election, ?\Closure $beat = null): array
    {
        if ($election->kind !== Election::KIND_GENERAL
            || ! in_array($election->status, [Election::STATUS_SCHEDULED, Election::STATUS_APPROVAL_OPEN], true)) { return []; }
        $changes = [];
        $candidates = DB::table('candidacies')->where('election_id', $election->id)->get(['race_id','user_id','status']);
        foreach ($election->races()->where('seat_kind', 'type_b')->whereNotNull('type_b_panel_id')->orderBy('id')->get() as $race) {
            $beat && $beat();
            $panel = DB::table('legislature_type_b_panels')->where('id', $race->type_b_panel_id)->whereNull('deleted_at')->first();
            if (! $panel || $panel->legislature_id !== $election->legislature_id) { throw new \RuntimeException('Population ceiling: panel ownership is missing or changed.'); }
            $people = 0; $members = 0; $cursor = null; $known = true;
            do {
                $rows = DB::table('legislature_type_b_panel_jurisdictions as m')
                    ->leftJoin('jurisdictions as j', 'j.id', '=', 'm.jurisdiction_id')
                    ->where('m.panel_id', $panel->id)
                    ->when($cursor, fn ($q) => $q->where('m.jurisdiction_id', '>', $cursor))
                    ->orderBy('m.jurisdiction_id')->limit(HostCapacity::sweepChunk())
                    ->get(['m.jurisdiction_id', 'j.population', 'j.deleted_at']);
                foreach ($rows as $row) {
                    $members++;
                    if ($row->population === null || $row->deleted_at !== null || (int) $row->population < 0) { $known = false; }
                    else { $people += (int) $row->population; }
                }
                $cursor = $rows->last()?->jurisdiction_id;
                $beat && $beat();
            } while ($rows->count() === HostCapacity::sweepChunk());
            if (! $known || $members === 0) { continue; }
            $present = $candidates->where('race_id', $race->id)->whereNotIn('status', ['rejected','withdrawn'])->count();
            $capacity = min($people, $this->distinctCapacity($race, $election, $people, $present, $candidates->pluck('user_id')->all()));
            if ((int) $race->seats <= $capacity && (int) $panel->seats <= $capacity) { continue; }
            if ((int) $race->seats < (int) $panel->seats) { throw new \RuntimeException('Population ceiling: race and panel allocations disagree.'); }
            $changes[] = ['race_id' => $race->id, 'panel_id' => $panel->id, 'grouping_id' => $panel->grouping_id,
                'population' => $people, 'race_seats' => (int) $race->seats, 'panel_seats' => (int) $panel->seats,
                'distinct_capacity' => $capacity,
                'seats' => min((int) $race->seats, (int) $panel->seats, $capacity)];
        }
        return $changes;
    }

    /**
     * Existing people cannot occupy two seats in one chamber. Count only the
     * bounded set already committed, against real population (not demo sample).
     * Retain existing assignments even if legacy provenance is imperfect.
     */
    public function distinctCapacity(object $race, Election $election, int $population, int $present, array $used): int
    {
        if ($population === 0) { return 0; }
        $used = array_values(array_unique($used));
        if ($population >= (int) $race->seats + count($used)) { return (int) $race->seats; }
        $reserved = [];
        foreach (app(SimCandidateField::class)->footprint($race, $election->jurisdiction_id) as $scope) {
            foreach (array_chunk($used, HostCapacity::sweepChunk()) as $chunk) {
                foreach (DB::table('residency_confirmations')->where('jurisdiction_id', $scope)->where('is_active', true)
                    ->whereIn('user_id', $chunk)->pluck('user_id') as $id) { $reserved[$id] = true; }
            }
        }
        return min((int) $race->seats, $present + max(0, $population - count($reserved)));
    }

    /** Retire only never-filled capacity; certified races/counts/officeholders remain historical facts. */
    public function reconcileCertifiedCapacity(Election $election, \Illuminate\Support\Collection $members, string $source, ?\Closure $beat = null): array
    {
        if ($election->status !== Election::STATUS_CERTIFIED || $election->kind !== Election::KIND_GENERAL) { return []; }
        if (DB::transactionLevel() < 1) { throw new \LogicException('Certified capacity must be part of the atomic election recovery.'); }
        $changes = []; $leg = Legislature::whereKey($election->legislature_id)->lockForUpdate()->firstOrFail();
        foreach ($election->races()->where('seat_kind', 'type_b')->whereNotNull('type_b_panel_id')->get() as $race) {
            $beat && $beat();
            $panel = DB::table('legislature_type_b_panels')->where('id', $race->type_b_panel_id)->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $panel || $panel->legislature_id !== $leg->id) { throw new \RuntimeException('Capacity correction: panel ownership changed.'); }
            $present = $members->where('elected_in_race_id', $race->id)->count();
            // Supplementary winners belong to new races but occupy the original
            // panel's vacancies. Include them before considering any reduction.
            $present += DB::table('vacancies')->where('seat_type', 'election_races')->where('seat_id', $race->id)->where('status', 'filled')->count();
            if ($present >= (int) $panel->seats) { continue; }
            $places = DB::table('legislature_type_b_panel_jurisdictions as m')->leftJoin('jurisdictions as j', 'j.id', '=', 'm.jurisdiction_id')
                ->where('m.panel_id', $panel->id)->get(['j.population','j.deleted_at']);
            if ($places->isEmpty() || $places->contains(fn ($p) => $p->population === null || $p->deleted_at !== null || (int) $p->population < 0)) { continue; }
            $capacity = max($present, $this->distinctCapacity($race, $election, (int) $places->sum('population'), $present, $members->pluck('user_id')->all()));
            if ($capacity >= (int) $panel->seats) { continue; }
            $tab = DB::table('tabulations')->where('race_id', $race->id)->where('status', 'complete')->whereNotNull('record_hash')
                ->whereIn('kind', ['initial','audit_rerun'])->orderByDesc('completed_at')->first();
            if (! $tab || (int) DB::table('race_results')->where('tabulation_id', $tab->id)->max('seat_no') > $capacity) {
                throw new \RuntimeException('Capacity correction cannot retire an occupied certified slot.');
            }
            $changes[] = ['race_id' => $race->id, 'panel_id' => $panel->id, 'grouping_id' => $panel->grouping_id,
                'previous_seats' => (int) $panel->seats, 'seats' => $capacity, 'population' => (int) $places->sum('population'),
                'preserved_occupied' => $present, 'preserved_count_hash' => $tab->record_hash];
        }
        if ($changes === []) { return []; }
        $groups = array_unique(array_column($changes, 'grouping_id'));
        $group = count($groups) === 1 ? DB::table('legislature_type_b_groupings')->where('id', $groups[0])->lockForUpdate()->first() : null;
        if (! $group || $group->status !== 'active' || $group->deleted_at !== null || $group->legislature_id !== $leg->id
            || (int) $group->seats_total !== (int) $leg->type_b_seats) { throw new \RuntimeException('Capacity correction requires the unchanged active grouping.'); }
        $delta = 0;
        foreach ($changes as $change) {
            DB::table('legislature_type_b_panels')->where('id', $change['panel_id'])->update(['seats' => $change['seats'], 'updated_at' => now()]);
            $delta += $change['previous_seats'] - $change['seats'];
        }
        $total = (int) $leg->total_seats - $delta; $typeB = (int) $leg->type_b_seats - $delta;
        if ($typeB < 0 || $total < $members->count()) { throw new \RuntimeException('Capacity correction cannot remove occupied representation.'); }
        DB::table('legislature_type_b_groupings')->where('id', $group->id)->update(['seats_total' => $typeB, 'updated_at' => now()]);
        $leg->forceFill(['total_seats' => $total, 'type_b_seats' => $typeB, 'quorum_required' => QuorumLaw::required($total)])->save();
        app(AuditService::class)->append('simworld', 'sim.unfilled_capacity_corrected', ['source_run_id' => $source,
            'election_id' => $election->id, 'changes' => $changes, 'removed_never_filled_seats' => $delta,
            'reason' => 'Operator ruling: chamber size cannot require more distinct representatives than the population permits.'], 'WF-SYS-04', jurisdictionId: $election->jurisdiction_id);
        return $changes;
    }

    public function reconcile(Election $election, ?string $sourceRun, ?\Closure $beat = null): array
    {
        return DB::transaction(function () use ($election, $sourceRun, $beat): array {
            $election = Election::whereKey($election->id)->lockForUpdate()->firstOrFail();
            $changes = $this->adjustments($election, $beat);
            if ($changes === []) { return []; }
            if (! $sourceRun || ! SimRun::whereKey($sourceRun)->exists()) { throw new \RuntimeException('Population ceiling correction requires a simulation run.'); }
            $leg = Legislature::whereKey($election->legislature_id)->lockForUpdate()->firstOrFail();
            if (DB::table('legislature_members')->where('legislature_id', $leg->id)->whereNull('deleted_at')
                ->whereIn('status', ['elected', 'seated'])->exists()) { throw new \RuntimeException('Population ceiling cannot change a serving legislature.'); }
            $groups = array_unique(array_column($changes, 'grouping_id'));
            if (count($groups) !== 1) { throw new \RuntimeException('Population ceiling requires one current panel grouping.'); }
            $group = DB::table('legislature_type_b_groupings')->where('id', $groups[0])->lockForUpdate()->first();
            if (! $group || $group->status !== 'active' || $group->deleted_at !== null || $group->legislature_id !== $leg->id
                || (int) $group->seats_total !== (int) $leg->type_b_seats) { throw new \RuntimeException('Population ceiling: active grouping and legislature disagree.'); }
            $delta = 0;
            foreach ($changes as $change) {
                $beat && $beat();
                if (DB::table('tabulations')->where('race_id', $change['race_id'])->exists()) {
                    throw new \RuntimeException('Population ceiling cannot rewrite an existing count.');
                }
                $race = $election->races()->whereKey($change['race_id'])->lockForUpdate()->firstOrFail();
                // Soft retirement retains the old advertised field and all
                // candidacies. There is no election to count for zero seats.
                if ($change['seats'] === 0) { $race->delete(); }
                else {
                    // Retain the election's frozen finalist multiplier.
                    if ((int) $race->finalist_count % (int) $race->seats !== 0) { throw new \RuntimeException('Population ceiling: invalid frozen finalist multiplier.'); }
                    $multiplier = intdiv((int) $race->finalist_count, (int) $race->seats);
                    $race->forceFill(['seats' => $change['seats'], 'finalist_count' => $change['seats'] * $multiplier])->save();
                }
                DB::table('legislature_type_b_panels')->where('id', $change['panel_id'])
                    ->update(['seats' => $change['seats'], 'bonus_seats' => 0, 'updated_at' => now()]);
                $delta += $change['panel_seats'] - $change['seats'];
            }
            $typeB = (int) $leg->type_b_seats - $delta;
            $total = (int) $leg->total_seats - $delta;
            if ($typeB < 0 || $total < 0) { throw new \RuntimeException('Population ceiling allocation underflow.'); }
            // Group membership/signature is unchanged; only unavailable seats
            // disappear. Apply the existing quorum formula to the corrected size.
            DB::table('legislature_type_b_groupings')->where('id', $group->id)->update(['seats_total' => $typeB, 'updated_at' => now()]);
            $leg->forceFill(['type_b_seats' => $typeB, 'total_seats' => $total, 'quorum_required' => QuorumLaw::required($total)])->save();
            app(AuditService::class)->append('simworld', 'sim.population_ceiling_corrected', [
                'source_run_id' => $sourceRun, 'election_id' => $election->id, 'changes' => $changes,
                'removed_seats' => $delta, 'type_b_seats' => $typeB, 'total_seats' => $total,
            ], 'WF-SYS-04', jurisdictionId: $election->jurisdiction_id);
            return $changes;
        });
    }
}
