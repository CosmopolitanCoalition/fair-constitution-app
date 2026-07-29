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
use App\Services\MultiJurisdictionVoteService;
use Illuminate\Support\Facades\DB;

/**
 * F-LEG-030 — Disintermediation (Art. V §8). An intermediary jurisdiction
 * dissolves when ALL its constituents agree (UNANIMITY — a `disintermediation`
 * MultiJurisdictionVote at BASIS_UNANIMITY) AND its encompassing jurisdiction
 * consents. On passage its Acts are INCORPORATED into its former CONSTITUENT
 * jurisdictions — Art. V §8's own words, and the operator's ruling 2026-07-28
 * (V3_SYNTHESIS_PLAN §10 item 2): each constituent receives its OWN copy of
 * every Act, carrying the full version history, so it can amend or repeal the
 * inherited law independently of its siblings. The encompassing jurisdiction
 * receives nothing; the constituents re-point to it as their new parent.
 */
class DisintermediationService
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
            $folded = $this->foldLawsToConstituents($process);
            $this->reparentChildren($process);

            DB::table('jurisdictions')->where('id', $process->intermediary_jurisdiction_id)
                ->update(['lifecycle_status' => 'disintermediated']);

            $process->forceFill(['status' => DisintermediationProcess::STATUS_MERGED])->save();

            $this->audit->append('jurisdictions', 'disintermediation.merged', [
                'process_id' => (string) $process->id,
                'intermediary_jurisdiction_id' => (string) $process->intermediary_jurisdiction_id,
                'encompassing_jurisdiction_id' => (string) $process->encompassing_jurisdiction_id,
                'acts_folded' => $folded['acts'],
                'constituents_inheriting' => $folded['constituents'],
            ], 'F-LEG-030', null, (string) $process->intermediary_jurisdiction_id);

            return $process->refresh();
        });
    }

    /**
     * Art. V §8: the intermediary "dissolves with its Acts being incorporated
     * into its former Constituent Jurisdictions" — the constituents inherit,
     * never the encompassing jurisdiction (operator ruling 2026-07-28).
     *
     * Each constituent receives its OWN Law row so it can amend or repeal the
     * inherited act independently: the full version history is copied verbatim
     * (v1 is never rewritten), then a `merge_incorporation` version is appended
     * marking the inheritance. One law_merge_resolutions row per act per
     * constituent points at the copy via resulting_law_id. The original row
     * stays under the dissolved intermediary as the archival record, marked
     * superseded — its own history untouched.
     *
     * @return array{acts: int, constituents: int}
     */
    private function foldLawsToConstituents(DisintermediationProcess $process): array
    {
        $constituentIds = $process->constituentProcess
            ?->consents()->pluck('jurisdiction_id')->map('strval')->all() ?? [];

        $laws = Law::query()
            ->where('jurisdiction_id', $process->intermediary_jurisdiction_id)
            ->whereNull('deleted_at')
            ->get();

        foreach ($laws as $law) {
            $versions = LawVersion::query()
                ->where('law_id', $law->id)
                ->orderBy('version_no')
                ->get();

            $currentText = (string) ($versions->last()?->text ?? $law->title);
            $inheritedVersionNo = (int) ($versions->last()?->version_no ?? 0) + 1;

            foreach ($constituentIds as $constituentId) {
                $copy = $law->replicate(['deleted_at']);
                $copy->forceFill([
                    'jurisdiction_id' => $constituentId,
                    // (legislature_id, act_number) is unique — each copy gets a
                    // constituent-marked number; the enacting legislature stays,
                    // because who enacted it is historical fact.
                    'act_number' => $law->act_number.' · inh:'.substr($constituentId, 0, 8),
                    'current_version_no' => $inheritedVersionNo,
                ])->save();

                // History rides verbatim — original numbering, text and dates.
                foreach ($versions as $v) {
                    LawVersion::create([
                        'law_id' => (string) $copy->id,
                        'version_no' => (int) $v->version_no,
                        'text' => (string) $v->text,
                        'text_hash' => (string) $v->text_hash,
                        'source' => (string) $v->source,
                        'source_ref_type' => $v->source_ref_type,
                        'source_ref_id' => $v->source_ref_id,
                        'created_at' => $v->created_at,
                    ]);
                }

                // The inheritance event, in the inherited law's own history.
                LawVersion::create([
                    'law_id' => (string) $copy->id,
                    'version_no' => $inheritedVersionNo,
                    'text' => $currentText,
                    'text_hash' => hash('sha256', $currentText),
                    'source' => 'merge_incorporation',
                    'source_ref_type' => 'disintermediation',
                    'source_ref_id' => (string) $process->id,
                    'created_at' => now(),
                ]);

                LawMergeResolution::create([
                    'process_id' => (string) $process->id,
                    'law_id' => (string) $law->id,
                    'target_jurisdiction_id' => $constituentId,
                    'decision' => LawMergeResolution::DECISION_INCORPORATE,
                    'resulting_law_id' => (string) $copy->id,
                ]);
            }

            // The dissolved intermediary keeps its row as the archival record.
            $law->forceFill(['status' => Law::STATUS_SUPERSEDED])->save();
        }

        return ['acts' => $laws->count(), 'constituents' => count($constituentIds)];
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
