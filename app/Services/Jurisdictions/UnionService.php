<?php

namespace App\Services\Jurisdictions;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\JurisdictionMap;
use App\Models\Legislature;
use App\Models\MultiJurisdictionVote;
use App\Models\UnionProcess;
use App\Services\AuditService;
use App\Services\ConstitutionalValidator;
use App\Services\MultiJurisdictionVoteService;
use App\Support\CivicPopulation;
use Illuminate\Support\Facades\DB;

/**
 * F-LEG-029 — Union formation / joining / exit (Art. V §7). DUAL ratification:
 *   • a SUPERMAJORITY of the APPLICANT population (a civic referendum), AND
 *   • a SUPERMAJORITY of the UNION's CONSTITUENT jurisdictions (a `union`
 *     MultiJurisdictionVote — the PROTECTED supermajority math).
 * BOTH meters must pass before the union change takes effect.
 */
class UnionService
{
    public function __construct(
        private readonly MultiJurisdictionVoteService $mjv,
        private readonly AuditService $audit,
    ) {}

    /** Adoption effect (ChamberActService::applyProposalAdoption) — opens the process. */
    public function adoptOpen(ChamberVoteProposal $proposal, ChamberVote $vote): array
    {
        $payload = (array) $proposal->payload;
        $legislature = $proposal->legislature()->firstOrFail();

        $process = $this->open(
            (string) ($payload['kind'] ?? UnionProcess::KIND_JOIN),
            $legislature,
            array_map('strval', (array) ($payload['applicant_ids'] ?? [])),
            array_map('strval', (array) ($payload['constituent_ids'] ?? [])),
            $payload['union_jurisdiction_id'] ?? null,
        );

        return ['union_processes', (string) $process->id];
    }

    /**
     * Open a union process — opens the constituent supermajority MJV; the
     * applicant referendum is recorded separately (markApplicantReferendum).
     *
     * @param  list<string>  $applicantIds
     * @param  list<string>  $constituentIds  union constituents (for formation, the founding jurisdictions)
     */
    public function open(string $kind, Legislature $initiating, array $applicantIds, array $constituentIds, ?string $unionJurisdictionId = null): UnionProcess
    {
        if (! in_array($kind, [UnionProcess::KIND_FORMATION, UnionProcess::KIND_JOIN, UnionProcess::KIND_EXIT], true)) {
            throw new ConstitutionalViolation("Unknown union process kind [{$kind}].", 'Art. V §7');
        }
        if ($kind === UnionProcess::KIND_FORMATION && count($applicantIds) < 2) {
            throw new ConstitutionalViolation('Forming a union requires two or more independent jurisdictions.', 'Art. V §7');
        }
        if ($applicantIds === []) {
            throw new ConstitutionalViolation('A union process names at least one applicant jurisdiction.', 'Art. V §7');
        }

        return DB::transaction(function () use ($kind, $initiating, $applicantIds, $constituentIds, $unionJurisdictionId) {
            $mjv = $this->mjv->open(
                'union', $initiating, $constituentIds,
                MultiJurisdictionVote::BASIS_SUPERMAJORITY, null, 'union_processes', null,
            );

            $process = UnionProcess::create([
                'kind' => $kind,
                'applicant_jurisdiction_ids' => array_values($applicantIds),
                'union_jurisdiction_id' => $unionJurisdictionId,
                'constituent_process_id' => (string) $mjv->id,
                'initiating_legislature_id' => (string) $initiating->id,
                'compatibility_diff' => $this->compatibilityDiff($applicantIds),
                'status' => UnionProcess::STATUS_OPEN,
            ]);

            $mjv->forceFill(['subject_id' => (string) $process->id])->save();

            $this->audit->append('legislature', 'union.opened', [
                'union_process_id' => (string) $process->id,
                'kind' => $kind,
                'constituent_process_id' => (string) $mjv->id,
            ], 'F-LEG-029', null, (string) $initiating->jurisdiction_id);

            return $process->refresh();
        });
    }

    /**
     * Record the applicant population referendum outcome. Threshold = a
     * supermajority of the APPLICANT civic population (the whole population is
     * the electorate — CivicPopulation).
     */
    public function markApplicantReferendum(UnionProcess $process, int $yesVotes): UnionProcess
    {
        $population = array_sum(array_map(fn ($j) => CivicPopulation::of((string) $j), $process->applicant_jurisdiction_ids));
        $required = ConstitutionalValidator::supermajority((int) $population);
        $met = $population > 0 && $yesVotes >= $required;

        $process->forceFill(['applicant_supermajority_met' => $met])->save();

        return $process->refresh();
    }

    /** Finalize: requires BOTH meters. Applies the union change on passage. */
    public function finalize(UnionProcess $process): UnionProcess
    {
        $process = $process->refresh();
        $mjv = $process->constituentProcess;
        $constituentPassed = $mjv !== null && $mjv->status === MultiJurisdictionVote::STATUS_PASSED;

        if (! $process->applicant_supermajority_met || ! $constituentPassed) {
            $process->forceFill(['status' => UnionProcess::STATUS_FAILED])->save();
            throw new ConstitutionalViolation(
                'A union change requires BOTH a supermajority of the APPLICANT population AND a supermajority of '
                .'the UNION constituents (Art. V §7) — '
                .(! $process->applicant_supermajority_met
                    ? 'the applicant population did not reach supermajority.'
                    : 'the union constituents did not reach supermajority.'),
                'Art. V §7'
            );
        }

        return DB::transaction(function () use ($process) {
            $resultingId = $this->applyEffect($process);

            $process->forceFill(['status' => UnionProcess::STATUS_PASSED, 'resulting_jurisdiction_id' => $resultingId])->save();

            $this->audit->append('legislature', 'union.'.$process->kind.'_passed', [
                'union_process_id' => (string) $process->id,
                'resulting_jurisdiction_id' => $resultingId,
            ], 'F-LEG-029');

            return $process->refresh();
        });
    }

    /**
     * Apply the union change: reparent the applicants under the union node and
     * stamp their lifecycle, opening an active jurisdiction_maps version. (The
     * full new-encompassing-bicameral-legislature build for a FORMATION reuses
     * ActivationService once the union node's geometry is set — kept out of the
     * hot path here; the topology + lifecycle effect is the constitutional one.)
     */
    private function applyEffect(UnionProcess $process): ?string
    {
        $applicants = $process->applicant_jurisdiction_ids;
        $unionId = $process->union_jurisdiction_id;

        $lifecycle = $process->kind === UnionProcess::KIND_EXIT ? 'self_governing' : 'in_union';

        if ($unionId !== null && $process->kind !== UnionProcess::KIND_EXIT) {
            DB::table('jurisdictions')->whereIn('id', $applicants)->update(['parent_id' => $unionId]);
        }
        DB::table('jurisdictions')->whereIn('id', $applicants)->update(['lifecycle_status' => $lifecycle]);

        if ($unionId !== null) {
            $next = (int) JurisdictionMap::query()->where('root_jurisdiction_id', $unionId)->max('version_no') + 1;
            JurisdictionMap::create([
                'root_jurisdiction_id' => $unionId,
                'name' => 'Union '.$process->kind.' '.$process->id,
                'status' => JurisdictionMap::STATUS_ACTIVE,
                'version_no' => $next,
                'origin' => 'union',
                'origin_process_id' => (string) $process->id,
                'effective_start' => now()->toDateString(),
            ]);
        }

        return $unionId;
    }

    /**
     * A compatibility diff over constitutional_settings across the applicants —
     * the amendable variables that must be codified to align (Art. V §7).
     *
     * @param  list<string>  $applicantIds
     * @return array<string,mixed>
     */
    private function compatibilityDiff(array $applicantIds): array
    {
        $keys = ['election_interval_months', 'legislature_min_seats', 'legislature_max_seats', 'voting_method'];
        $diff = [];

        foreach ($keys as $key) {
            $values = DB::table('constitutional_settings')
                ->whereIn('jurisdiction_id', $applicantIds)
                ->pluck($key)->filter()->unique()->values()->all();
            $diff[$key] = ['values' => $values, 'aligned' => count($values) <= 1];
        }

        return $diff;
    }
}
