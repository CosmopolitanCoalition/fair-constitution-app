<?php

namespace App\Services\Jurisdictions;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Legislature;
use App\Models\LocalAutonomyProcess;
use App\Models\MultiJurisdictionVote;
use App\Services\AuditService;
use App\Services\ConstitutionalValidator;
use App\Services\Federation\PartitionExportService;
use App\Services\MultiJurisdictionVoteService;
use App\Support\CivicPopulation;
use Illuminate\Support\Facades\DB;

/**
 * G6 — earned-autonomy promotion (Phase G; the governed flip to authoritative R/W).
 *
 * The cardinal Phase G principle made constitutional: authority over a real place
 * is EARNED by population (a seated government, CLK-06) and GRANTED by that place's
 * current authoritative government — never handed out by an admin, never claimed
 * unilaterally. DUAL ratification, mirroring UnionService:
 *   • a SUPERMAJORITY of the PROMOTING jurisdiction's civic population (it seeks
 *     autonomy), AND
 *   • the PARENT's `local_autonomy` MultiJurisdictionVote (the current authoritative
 *     government grants it — the PROTECTED supermajority math).
 *
 * Only when BOTH meters pass does authority for the subtree flip to the gaining
 * cluster (`jurisdictions.authoritative_server_id`). Leadership (the Patroni axis)
 * is untouched — authority ≠ leadership. The encrypted operational bundle (G5) and
 * the fail-closed ballot re-wrap (G5a) carry the keys to the gaining side.
 */
class LocalAutonomyService
{
    public function __construct(
        private readonly MultiJurisdictionVoteService $mjv,
        private readonly PartitionExportService $partition,
        private readonly AuditService $audit,
    ) {}

    /**
     * Open an autonomy-promotion process. Requires the promoting jurisdiction to
     * have a SEATED government (an active legislature) and a parent to grant from.
     */
    public function open(Legislature $promoting, string $gainingServerId, ?string $gainingClusterId = null): LocalAutonomyProcess
    {
        if ($promoting->status !== Legislature::STATUS_ACTIVE) {
            throw new ConstitutionalViolation(
                'Earned autonomy can be sought only by a jurisdiction with a SEATED government (an active legislature) — '
                .'authority is earned by population, never claimed by a forming or vacant body.',
                'A Fair Constitution — earned autonomy (CLK-06 activation)'
            );
        }

        $jurisdictionId = (string) $promoting->jurisdiction_id;
        $parentId = DB::table('jurisdictions')->where('id', $jurisdictionId)->value('parent_id');

        if ($parentId === null) {
            throw new ConstitutionalViolation(
                'The root jurisdiction has no parent to be granted autonomy from — its authority is already its own.',
                'Art. V'
            );
        }

        return DB::transaction(function () use ($promoting, $jurisdictionId, $parentId, $gainingServerId, $gainingClusterId): LocalAutonomyProcess {
            // The granting leg — the current authoritative (parent) government must
            // consent. There is exactly ONE current grantor (the immediate parent),
            // so the cross-jurisdiction basis is UNANIMITY (1 of 1): the parent's own
            // internal 2/3 is the separate chamber vote that DRIVES this consent.
            $mjv = $this->mjv->open(
                'local_autonomy',
                $promoting,
                [(string) $parentId],
                MultiJurisdictionVote::BASIS_UNANIMITY,
                null,
                'local_autonomy_processes',
                null,
            );

            $process = LocalAutonomyProcess::create([
                'promoting_jurisdiction_id'   => $jurisdictionId,
                'promoting_legislature_id'    => (string) $promoting->id,
                'parent_jurisdiction_id'      => (string) $parentId,
                'gaining_server_id'           => $gainingServerId,
                'gaining_cluster_id'          => $gainingClusterId,
                'parent_process_id'           => (string) $mjv->id,
                'promoting_supermajority_met' => false,
                'status'                      => LocalAutonomyProcess::STATUS_OPEN,
            ]);

            $mjv->forceFill(['subject_id' => (string) $process->id])->save();

            $this->audit->append('legislature', 'local_autonomy.opened', [
                'process_id'                => (string) $process->id,
                'promoting_jurisdiction_id' => $jurisdictionId,
                'parent_jurisdiction_id'    => (string) $parentId,
                'gaining_server_id'         => $gainingServerId,
                'parent_process_id'         => (string) $mjv->id,
            ], 'F-LEG-036', null, $jurisdictionId);

            return $process->refresh();
        });
    }

    /**
     * Record the promoting jurisdiction's own population referendum. Threshold =
     * a supermajority of its CIVIC population (the whole population is the
     * electorate — CivicPopulation, the PROTECTED supermajority math).
     */
    public function markPromotingSupermajority(LocalAutonomyProcess $process, int $yesVotes): LocalAutonomyProcess
    {
        $population = CivicPopulation::of((string) $process->promoting_jurisdiction_id);
        $required = ConstitutionalValidator::supermajority($population);
        $met = $population > 0 && $yesVotes >= $required;

        $process->forceFill(['promoting_supermajority_met' => $met])->save();

        return $process->refresh();
    }

    /**
     * Finalize: requires BOTH the promoting supermajority AND the parent MJV passed.
     * On dual passage the subtree's authority flips to the gaining cluster. On a
     * missing meter it FAILS and flips nothing — earned autonomy is never unilateral.
     */
    public function finalize(LocalAutonomyProcess $process): LocalAutonomyProcess
    {
        $process = $process->refresh();
        $mjv = MultiJurisdictionVote::query()->find($process->parent_process_id);
        $parentGranted = $mjv !== null && $mjv->status === MultiJurisdictionVote::STATUS_PASSED;

        if (! $process->promoting_supermajority_met || ! $parentGranted) {
            $process->forceFill(['status' => LocalAutonomyProcess::STATUS_FAILED])->save();

            throw new ConstitutionalViolation(
                'Earned autonomy requires BOTH a supermajority of the PROMOTING jurisdiction AND the consent of the '
                .'current authoritative (PARENT) government — '
                .(! $process->promoting_supermajority_met
                    ? 'the promoting jurisdiction did not reach supermajority.'
                    : 'the parent government did not grant it.'),
                'A Fair Constitution — earned autonomy is governed, never unilateral'
            );
        }

        return DB::transaction(function () use ($process): LocalAutonomyProcess {
            // THE AUTHORITY FLIP — the subtree's authoritative_server_id moves to the
            // gaining cluster, relinquishing authority from the current (parent)
            // authoritative instance. Leadership (Patroni) is untouched. The
            // operational bundle (G5) + fail-closed re-wrap (G5a) deliver the keys.
            $subtree = array_values(array_unique(array_merge(
                [(string) $process->promoting_jurisdiction_id],
                $this->partition->descendants((string) $process->promoting_jurisdiction_id),
            )));

            DB::table('jurisdictions')->whereIn('id', $subtree)->update([
                'authoritative_server_id' => $process->gaining_server_id,
                'updated_at'              => now(),
            ]);

            $process->forceFill([
                'status'                            => LocalAutonomyProcess::STATUS_PASSED,
                'resulting_authoritative_server_id' => $process->gaining_server_id,
                'subtree_size'                      => count($subtree),
            ])->save();

            $this->audit->append('legislature', 'local_autonomy.passed', [
                'process_id'                => (string) $process->id,
                'promoting_jurisdiction_id' => (string) $process->promoting_jurisdiction_id,
                'gaining_server_id'         => (string) $process->gaining_server_id,
                'subtree_size'              => count($subtree),
            ], 'F-LEG-036', null, (string) $process->promoting_jurisdiction_id);

            return $process->refresh();
        });
    }
}
