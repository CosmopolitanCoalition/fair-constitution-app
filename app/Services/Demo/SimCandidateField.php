<?php

namespace App\Services\Demo;

use App\Models\Candidacy;
use App\Models\Election;
use App\Services\Demo\Stages\IdentityStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stable, election-wide assignment. Existing results and assignments are immutable. */
class SimCandidateField
{
    public function fill(string $electionId, ?string $runId, int $version, ?\Closure $beat = null, bool $noFloor = false): array
    {
        return DB::transaction(function () use ($electionId, $runId, $version, $beat, $noFloor): array {
            $election = Election::query()->whereKey($electionId)->lockForUpdate()->firstOrFail();
            if (! in_array($election->status, [Election::STATUS_SCHEDULED, Election::STATUS_APPROVAL_OPEN], true)) {
                throw new \RuntimeException('Candidate repair requires an open nomination election; closed/certified results are preserved.');
            }
            $races = DB::table('election_races')->where('election_id', $electionId)->whereNull('deleted_at')->orderBy('id')->get();
            foreach ($races as $race) { $race->pool = $this->footprint($race, (string) $election->jurisdiction_id); }
            $scopes = $races->groupBy(fn ($race) => implode(',', $race->pool));
            $levels = DB::table('jurisdictions')->whereIn('id', $races->pluck('pool')->flatten()->unique())->pluck('adm_level', 'id');
            $scopes = $scopes->sortBy(fn ($group, $id) => [-max(array_map(fn ($p) => (int) ($levels[$p] ?? 0), $group->first()->pool)), count($group->first()->pool), (string) $id]);
            $existing = DB::table('candidacies')->where('election_id', $electionId)->get(['user_id','race_id','status']);
            $used = array_fill_keys($existing->pluck('user_id')->all(), true);
            // A supplementary contest fills an empty seat, never awards an
            // already-serving legislator a second seat in the same chamber.
            if ($election->kind === Election::KIND_SPECIAL) {
                foreach (DB::table('legislature_members')->where('legislature_id', $election->legislature_id)
                    ->whereNull('deleted_at')->whereNull('vacated_at')->whereNotNull('user_id')
                    ->whereIn('status', ['elected', 'seated'])->pluck('user_id') as $id) { $used[$id] = true; }
            }
            $rows = []; $tooFew = [];
            foreach ($scopes as $scope => $group) {
                $pool = $group->first()->pool;
                $beat && $beat();
                $needs = []; $ownSlots = 0;
                foreach ($group as $race) {
                    $slots = (int) $race->seats + 1; $ownSlots += $slots;
                    $present = $existing->where('race_id', $race->id)->whereNotIn('status', [Candidacy::STATUS_REJECTED, Candidacy::STATUS_WITHDRAWN])->count();
                    $missing = max(0, $slots - $present);
                    if ($missing > 0 && DB::table('tabulations')->where('race_id', $race->id)->where('status', 'complete')->exists()) {
                        // Do not add candidates after a recorded count. This is
                        // a recount/recovery decision, not roster maintenance.
                        if ($present < (int) $race->seats) {
                            throw new \RuntimeException('A counted race has insufficient candidates; preserve its result and request election recovery.');
                        }
                        $missing = 0; // a full uncontested result is already settled
                    }
                    $needs[$race->id] = $missing;
                }
                $needed = array_sum($needs);
                if ($needed === 0) { continue; }
                $roster = $this->residents($pool, $needed, array_keys($used));
                if (count($roster) < $needed && ! $noFloor) {
                    // Existing cross-scope assignments also consume this pool.
                    // The identity stage still enforces the real-population ceiling.
                    foreach ($pool as $place) {
                        $reserved = $used === [] ? 0 : DB::table('residency_confirmations')->where('jurisdiction_id', $place)
                            ->where('is_active', true)->whereIn('user_id', array_keys($used))->distinct()->count('user_id');
                        IdentityStage::run($place, $runId, $version, $beat, 0.0, $needed + $reserved);
                        $roster = $this->residents($pool, $needed, array_keys($used));
                        if (count($roster) >= $needed) { break; }
                    }
                }
                if (count($roster) < $needed) {
                    $populations = array_map(fn ($place) => IdentityStage::populationOf($place, $version), $pool);
                    $population = in_array(null, $populations, true) ? null : array_sum($populations);
                    if ($existing->isEmpty() && $population !== null && $population < $ownSlots) {
                        $tooFew[$scope] = ['residents' => count($roster), 'needed' => $ownSlots, 'population' => $population];
                        continue;
                    }
                    throw new \RuntimeException("Distinct eligible candidate pool exhausted in {$scope}: ".count($roster)." available, {$needed} missing; existing candidacies preserved.");
                }
                $cursor = 0; $now = now();
                foreach ($needs as $raceId => $missing) {
                    for ($i = 0; $i < $missing; $i++) {
                        $user = $roster[$cursor++]; $used[$user] = true;
                        $rows[] = ['id' => (string) Str::uuid(), 'election_id' => $electionId, 'race_id' => $raceId,
                            'user_id' => $user, 'status' => Candidacy::STATUS_VALIDATED, 'position_tags' => '[]',
                            'residency_attested_at' => $now, 'validated_at' => $now, 'created_at' => $now, 'updated_at' => $now];
                    }
                }
            }
            if ($tooFew !== []) {
                if (count($tooFew) !== $scopes->count()) { throw new \RuntimeException('Only some race scopes are short by law; election requires review.'); }
                return ['candidacies' => 0, 'too_few' => $tooFew];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                $beat && $beat();
                DB::table('candidacies')->insert($chunk); // never discard election-wide collisions
            }
            return ['candidacies' => DB::table('candidacies')->where('election_id', $electionId)->count(), 'too_few' => []];
        });
    }

    private function residents(array $scopes, int $needed, array $used): array
    {
        $pool = [];
        foreach ($scopes as $scope) {
            // Each probe uses the partial (jurisdiction_id,user_id) index and
            // LIMIT before merging. Never sort an entire multi-place electorate.
            foreach (DB::table('residency_confirmations')->where('jurisdiction_id', $scope)->where('is_active', true)
                ->when($used !== [], fn ($q) => $q->whereNotIn('user_id', $used))
                ->orderBy('user_id')->limit($needed)->pluck('user_id') as $id) { $pool[$id] = true; }
        }
        $ids = array_keys($pool); sort($ids, SORT_STRING); return array_slice($ids, 0, $needed);
    }

    /** Same boundaries as RaceFootprint, including district and Type B panel unions. */
    private function footprint(object $race, string $fallback): array
    {
        $ids = ! empty($race->district_id)
            ? DB::table('legislature_district_jurisdictions')->where('district_id', $race->district_id)->pluck('jurisdiction_id')->all()
            : (! empty($race->type_b_panel_id)
                ? DB::table('legislature_type_b_panel_jurisdictions')->where('panel_id', $race->type_b_panel_id)->pluck('jurisdiction_id')->all()
                : [$race->jurisdiction_id ?: $fallback]);
        // RaceFootprint's LEFT JOIN/COALESCE uses the race scope for absent or
        // subdivision-only membership rows; keep exactly that existing meaning.
        $ids = array_map(fn ($id) => $id ?: ($race->jurisdiction_id ?: $fallback), $ids ?: [null]);
        $ids = array_values(array_unique($ids)); sort($ids, SORT_STRING); return $ids;
    }
}
