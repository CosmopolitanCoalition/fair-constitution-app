<?php

namespace App\Services\Demo;

use App\Models\{Election, Legislature, SimRun, Vacancy};
use App\Services\{AuditService, ElectionLifecycleService};
use App\Services\Demo\Stages\{CountingStage, SeatingStage};
use Illuminate\Support\Facades\DB;

/** Operator-authorized synthetic recovery; ordinary elections retain their guards. */
class SimElectionRecovery
{
    public function recover(SimRun $run, string $electionId, ?\Closure $beat = null): array
    {
        if (! ($run->options['repair_election_recovery'] ?? false) || ! ($run->options['repair_apply_authorized'] ?? false)) {
            throw new \RuntimeException('Election recovery has not been enabled for this repair run.');
        }
        if ($reason = app(SimRunControl::class)->refusalReason()) { throw new \RuntimeException($reason); }
        return DB::transaction(function () use ($run, $electionId, $beat): array {
            $election = Election::whereKey($electionId)->lockForUpdate()->firstOrFail();
            $source = (string) $run->options['repair_source_run'];
            $metrics = DB::table('sim_items')->where('run_id', $source)->where('kind', 'election_scope')
                ->where('unit_key', $election->jurisdiction_id)->value('metrics');
            if ((json_decode($metrics ?? '{}', true)['election_id'] ?? null) !== $electionId || $election->kind !== Election::KIND_GENERAL) {
                throw new \RuntimeException('Only the source simulation general election can be recovered.');
            }
            return $election->status === Election::STATUS_CERTIFIED
                ? $this->supplement($run, $election, $beat) : $this->completeUnfinished($run, $election, $beat);
        });
    }

    private function completeUnfinished(SimRun $run, Election $election, ?\Closure $beat): array
    {
        if (! in_array($election->status, [Election::STATUS_SCHEDULED, Election::STATUS_APPROVAL_OPEN], true)) {
            throw new \RuntimeException('Recovery cannot reopen a closed nomination or certified count.');
        }
        $superseded = [];
        foreach ($election->races()->orderBy('id')->get() as $race) {
            $beat && $beat();
            $candidates = DB::table('candidacies')->where('race_id', $race->id)->whereNotIn('status', ['rejected', 'withdrawn'])->count();
            if ($candidates >= (int) $race->seats) { continue; }
            $records = DB::table('tabulations')->where('race_id', $race->id)->where('status', 'complete')->get(['id', 'record_hash']);
            foreach ($records as $record) {
                $superseded[] = ['id' => $record->id, 'race_id' => $race->id, 'record_hash' => $record->record_hash];
                DB::table('tabulations')->where('id', $record->id)->update(['status' => 'superseded', 'updated_at' => now()]);
            }
        }
        // Only status changes on obsolete counts. Hashes, rounds and results
        // remain intact; adequate completed counts are never superseded.
        app(AuditService::class)->append('simworld', 'sim.election_count_recovery', [
            'repair_run_id' => $run->id, 'election_id' => $election->id, 'superseded' => $superseded,
            'reason' => 'Operator-authorized correction of incomplete synthetic candidate fields',
        ], 'WF-SYS-04', jurisdictionId: $election->jurisdiction_id);
        return ['superseded' => $superseded] + $this->finish($run, $election, $beat);
    }

    private function supplement(SimRun $run, Election $original, ?\Closure $beat): array
    {
        $leg = Legislature::whereKey($original->legislature_id)->lockForUpdate()->firstOrFail();
        if (! $leg->term_ends_on || $leg->term_ends_on->isPast()) {
            throw new \RuntimeException('Supplementary seats require an unexpired original chamber term.');
        }
        $members = DB::table('legislature_members')->where('legislature_id', $leg->id)->whereNull('deleted_at')
            ->whereNull('vacated_at')->whereNotNull('user_id')->whereIn('status', ['elected', 'seated'])->get();
        $originalMembers = $members->where('election_id', $original->id);
        if ($originalMembers->isEmpty() || $originalMembers->contains(fn ($m) => (string) $m->term_ends_on !== $leg->term_ends_on->toDateString())) {
            throw new \RuntimeException('The original election is not the current chamber term; preserve subsequent officeholders.');
        }
        $typeBNo = (int) $members->where('seat_type', 'b')->max('seat_no');
        $specials = [];
        foreach ($original->races()->orderBy('id')->get() as $race) {
            $beat && $beat();
            $tab = DB::table('tabulations')->where('race_id', $race->id)->where('status', 'complete')
                ->whereNotNull('record_hash')->orderByDesc('completed_at')->first();
            if (! $tab) { throw new \RuntimeException('Certified race lacks its sealed count; preserve it for review.'); }
            $occupied = DB::table('race_results')->where('tabulation_id', $tab->id)->whereNotNull('seat_no')->pluck('seat_no')->map(fn ($n) => (int) $n)->all();
            for ($slot = 1; $slot <= (int) $race->seats; $slot++) {
                if (in_array($slot, $occupied, true)) { continue; }
                $beat && $beat();
                $vacancy = Vacancy::where('seat_type', 'election_races')->where('seat_id', $race->id)->where('unfilled_seat_no', $slot)->first();
                if (! $vacancy) {
                    $number = $race->seat_kind === 'type_b' ? ++$typeBNo : $slot;
                    $vacancy = Vacancy::create(['seat_type' => 'election_races', 'seat_id' => $race->id,
                        'unfilled_seat_no' => $slot, 'replacement_seat_no' => $number,
                        'legislature_id' => $leg->id, 'jurisdiction_id' => $original->jurisdiction_id,
                        'status' => Vacancy::STATUS_DECLARED, 'declared_via_form' => 'sim_repair',
                        'detected_at' => now(), 'declared_at' => now()]);
                    app(AuditService::class)->append('elections', 'vacancy.never_filled', [
                        'vacancy_id' => $vacancy->id, 'source_election_id' => $original->id, 'race_id' => $race->id,
                        'unfilled_seat_no' => $slot, 'replacement_seat_no' => $number, 'repair_run_id' => $run->id,
                    ], 'WF-SYS-04', jurisdictionId: $original->jurisdiction_id);
                }
                if ($vacancy->status === Vacancy::STATUS_FILLED) { continue; }
                $special = app(ElectionLifecycleService::class)->scheduleSpecial($vacancy);
                $specials[] = ['election_id' => $special->id, 'vacancy_id' => $vacancy->id] + $this->finish($run, $special, $beat);
            }
        }
        return ['specials' => $specials, 'original_election_id' => $original->id];
    }

    private function finish(SimRun $run, Election $election, ?\Closure $beat): array
    {
        $source = (string) $run->options['repair_source_run']; $version = (int) ($run->options['version'] ?? 1);
        $fields = app(SimCandidateField::class)->fill($election->id, $source, $version, $beat, (bool) ($run->options['no_floor'] ?? false));
        if ($fields['too_few'] !== []) { throw new \RuntimeException('Election cannot exceed its real-population ceiling.'); }
        $counts = CountingStage::run($election->id, $source, $version, $beat);
        $expected = 0;
        foreach ($election->races()->get() as $race) {
            $beat && $beat(); $expected += (int) $race->seats;
            $tab = DB::table('tabulations')->where('race_id', $race->id)->where('status', 'complete')
                ->whereNotNull('record_hash')->orderByDesc('completed_at')->value('id');
            if (! $tab || DB::table('race_results')->where('tabulation_id', $tab)->whereNotNull('seat_no')->count() !== (int) $race->seats) {
                throw new \RuntimeException('Recovery count did not elect the full advertised field; no partial certification applied.');
            }
        }
        $seating = SeatingStage::run($election->id, $source, $version, $beat);
        if (! $seating['certified'] || $seating['seated'] !== $expected) { throw new \RuntimeException('Recovered election did not fully certify: '.($seating['skipped'] ?? 'seat count mismatch')); }
        return ['fielding' => $fields, 'counting' => $counts, 'seating' => $seating];
    }
}
