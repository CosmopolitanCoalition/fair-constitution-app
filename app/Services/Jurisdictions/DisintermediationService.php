<?php

namespace App\Services\Jurisdictions;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\DisintermediationProcess;
use App\Models\Law;
use App\Models\LawMergeResolution;
use App\Models\LawVersion;
use App\Models\Legislature;
use App\Models\MultiJurisdictionVote;
use App\Services\AuditService;
use App\Services\EnactmentService;
use App\Services\MultiJurisdictionVoteService;
use Illuminate\Support\Facades\DB;

/**
 * F-LEG-030 — Disintermediation (Art. V §8). An intermediary jurisdiction
 * dissolves when ALL its constituents agree (UNANIMITY — a `disintermediation`
 * MultiJurisdictionVote at BASIS_UNANIMITY) AND its encompassing jurisdiction
 * consents. On passage its Acts are INCORPORATED into the encompassing
 * jurisdiction (via EnactmentService::amendLaw — every law's version history is
 * preserved) and its children re-point to the encompassing jurisdiction.
 */
class DisintermediationService
{
    public function __construct(
        private readonly MultiJurisdictionVoteService $mjv,
        private readonly EnactmentService $enactments,
        private readonly AuditService $audit,
    ) {}

    /** Adoption effect (ChamberActService::applyProposalAdoption) — opens the process. */
    public function adoptOpen(ChamberVoteProposal $proposal, ChamberVote $vote): array
    {
        $payload = (array) $proposal->payload;
        $legislature = $proposal->legislature()->firstOrFail();

        $process = $this->open(
            $legislature,
            (string) ($payload['intermediary_id'] ?? ''),
            (string) ($payload['encompassing_id'] ?? ''),
            array_map('strval', (array) ($payload['constituent_ids'] ?? [])),
        );

        return ['disintermediation_processes', (string) $process->id];
    }

    /** @param  list<string>  $constituentIds  the intermediary's constituent jurisdictions */
    public function open(Legislature $intermediaryLegislature, string $intermediaryJurisdictionId, string $encompassingJurisdictionId, array $constituentIds): DisintermediationProcess
    {
        if ($constituentIds === []) {
            throw new ConstitutionalViolation('Disintermediation dissolves an intermediary that HAS constituents.', 'Art. V §8');
        }

        return DB::transaction(function () use ($intermediaryLegislature, $intermediaryJurisdictionId, $encompassingJurisdictionId, $constituentIds) {
            $mjv = $this->mjv->open(
                'disintermediation', $intermediaryLegislature, $constituentIds,
                MultiJurisdictionVote::BASIS_UNANIMITY, null, 'disintermediation_processes', null,
            );

            $process = DisintermediationProcess::create([
                'intermediary_jurisdiction_id' => $intermediaryJurisdictionId,
                'encompassing_jurisdiction_id' => $encompassingJurisdictionId,
                'constituent_process_id' => (string) $mjv->id,
                'status' => DisintermediationProcess::STATUS_OPEN,
            ]);

            $mjv->forceFill(['subject_id' => (string) $process->id])->save();

            $this->audit->append('jurisdictions', 'disintermediation.opened', [
                'process_id' => (string) $process->id,
                'intermediary_jurisdiction_id' => $intermediaryJurisdictionId,
                'constituent_process_id' => (string) $mjv->id,
            ], 'F-LEG-030', null, $intermediaryJurisdictionId);

            return $process->refresh();
        });
    }

    public function recordEncompassingConsent(DisintermediationProcess $process, bool $consented, ?string $voteId = null): DisintermediationProcess
    {
        $process->forceFill([
            'encompassing_consent' => $consented,
            'encompassing_consent_vote_id' => $voteId,
        ])->save();

        return $process->refresh();
    }

    /** Finalize: requires constituent UNANIMITY AND encompassing consent. */
    public function finalize(DisintermediationProcess $process): DisintermediationProcess
    {
        $process = $process->refresh();
        $mjv = $process->constituentProcess;
        $unanimous = $mjv !== null && $mjv->status === MultiJurisdictionVote::STATUS_PASSED;

        if (! $unanimous || ! $process->encompassing_consent) {
            $process->forceFill(['status' => DisintermediationProcess::STATUS_FAILED])->save();
            throw new ConstitutionalViolation(
                'Disintermediation requires UNANIMITY of all constituent jurisdictions AND the encompassing '
                .'jurisdiction\'s consent (Art. V §8) — '
                .($unanimous ? 'the encompassing jurisdiction did not consent.' : 'the constituents were not unanimous.'),
                'Art. V §8'
            );
        }

        return DB::transaction(function () use ($process) {
            $this->mergeLaws($process);
            $this->reparentChildren($process);

            DB::table('jurisdictions')->where('id', $process->intermediary_jurisdiction_id)
                ->update(['lifecycle_status' => 'disintermediated']);

            $process->forceFill(['status' => DisintermediationProcess::STATUS_MERGED])->save();

            $this->audit->append('jurisdictions', 'disintermediation.merged', [
                'process_id' => (string) $process->id,
                'intermediary_jurisdiction_id' => (string) $process->intermediary_jurisdiction_id,
                'encompassing_jurisdiction_id' => (string) $process->encompassing_jurisdiction_id,
            ], 'F-LEG-030', null, (string) $process->intermediary_jurisdiction_id);

            return $process->refresh();
        });
    }

    /**
     * Incorporate every intermediary law into the encompassing jurisdiction,
     * preserving its full version history (amendLaw APPENDS a merge_incorporation
     * version — v1 is never overwritten). Each is recorded in
     * law_merge_resolutions.
     */
    private function mergeLaws(DisintermediationProcess $process): void
    {
        $laws = Law::query()
            ->where('jurisdiction_id', $process->intermediary_jurisdiction_id)
            ->whereNull('deleted_at')
            ->get();

        foreach ($laws as $law) {
            $currentText = (string) (LawVersion::query()
                ->where('law_id', $law->id)
                ->orderByDesc('version_no')
                ->value('text') ?? $law->title);

            // Append a merge_incorporation version — history preserved.
            $this->enactments->amendLaw(
                $law,
                $currentText,
                'merge_incorporation',
                'disintermediation',
                (string) $process->id,
                'F-LEG-030',
            );

            // The Act now lives under the encompassing jurisdiction.
            $law->forceFill(['jurisdiction_id' => (string) $process->encompassing_jurisdiction_id])->save();

            LawMergeResolution::create([
                'process_id' => (string) $process->id,
                'law_id' => (string) $law->id,
                'target_jurisdiction_id' => (string) $process->encompassing_jurisdiction_id,
                'decision' => LawMergeResolution::DECISION_INCORPORATE,
                'resulting_law_id' => (string) $law->id,
            ]);
        }
    }

    /** The intermediary's children re-point to the encompassing jurisdiction. */
    private function reparentChildren(DisintermediationProcess $process): void
    {
        DB::table('jurisdictions')
            ->where('parent_id', $process->intermediary_jurisdiction_id)
            ->whereNull('deleted_at')
            ->update(['parent_id' => $process->encompassing_jurisdiction_id]);
    }
}
