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
            if ((int) $race->seats <= $people && (int) $panel->seats <= $people) { continue; }
            if ((int) $race->seats < (int) $panel->seats) { throw new \RuntimeException('Population ceiling: race and panel allocations disagree.'); }
            $changes[] = ['race_id' => $race->id, 'panel_id' => $panel->id, 'grouping_id' => $panel->grouping_id,
                'population' => $people, 'race_seats' => (int) $race->seats, 'panel_seats' => (int) $panel->seats,
                'seats' => min((int) $race->seats, (int) $panel->seats, $people)];
        }
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
