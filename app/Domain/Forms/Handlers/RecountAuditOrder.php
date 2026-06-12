<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\CertificationPipeline;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\BoardProvenance;
use App\Models\Election;
use App\Models\ElectionAudit;
use App\Models\ElectionCertification;
use App\Models\User;

/**
 * F-ELB-006 — Recount/Audit Order (R-08).
 *
 * The "recount" reframing (design §A B-9): always an AUDIT RE-RUN of the
 * stored ballots through the protected counting engine — never a hand
 * count. Engine gates:
 *
 *  - only a CERTIFIED election can be audited (the order challenges a
 *    sealed result — there is nothing to recount before certification);
 *  - a stated cause is REQUIRED (F-ELB-006 contract).
 *
 * Mutation: election_audits row + ESM-03 → audit_rerun. The actual
 * re-tabulation (TabulateElectionJob kind='audit_rerun' on the
 * long-running queue, linking election_audits.tabulation_id) is WI-B5's —
 * reached through CertificationPipeline::beginAuditRerun(), no-op until
 * the orchestrator rebinds. Outcome 'corrected' later supersedes the
 * certification via a fresh F-ELB-004.
 */
class RecountAuditOrder implements FormHandler
{
    public function __construct(
        private readonly CertificationPipeline $pipeline,
    ) {
    }

    public function module(): string
    {
        return 'elections';
    }

    public function event(): string
    {
        return 'election.audit_ordered';
    }

    public function requiredRoles(): array
    {
        return ['R-08'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $cause = trim((string) ($payload['cause'] ?? ''));

        if ($cause === '') {
            throw new ConstitutionalViolation(
                'F-ELB-006 requires a stated cause — audit re-runs are never ordered silently.',
                'CGA Forms Catalog (F-ELB-006)'
            );
        }

        $election = Election::query()->find($payload['election_id'] ?? null);

        if ($election === null) {
            throw new ConstitutionalViolation(
                'F-ELB-006 targets an unknown election.',
                'CGA Forms Catalog (F-ELB-006)'
            );
        }

        $certified = ElectionCertification::query()
            ->where('election_id', (string) $election->id)
            ->where('status', ElectionCertification::STATUS_CERTIFIED)
            ->exists();

        if (! $certified) {
            throw new ConstitutionalViolation(
                "Election [{$election->id}] has no current certification — an audit re-run challenges a "
                . 'sealed result.',
                'CGA Forms Catalog (F-ELB-006)'
            );
        }

        $raceId = $payload['race_id'] ?? null;

        if ($raceId !== null && ! $election->races()->whereKey($raceId)->exists()) {
            throw new ConstitutionalViolation(
                "Race [{$raceId}] does not belong to election [{$election->id}].",
                'CGA Forms Catalog (F-ELB-006)'
            );
        }

        // Provenance: the order must come from this election's board (or
        // the bootstrap system row on a system filing).
        BoardProvenance::resolveMember($actor, $election, 'F-ELB-006');

        $audit = ElectionAudit::query()->create([
            'election_id' => (string) $election->id,
            'race_id'     => $raceId !== null ? (string) $raceId : null,
            'cause'       => $cause,
            'ordered_by'  => $actor?->getKey() !== null ? (string) $actor->getKey() : null,
            'ordered_at'  => now(),
        ]);

        $election->forceFill(['status' => Election::STATUS_AUDIT_RERUN])->save();

        // WI-B5 seam: dispatch the audit re-tabulation.
        $this->pipeline->beginAuditRerun($audit);

        return [
            'audit_id'    => (string) $audit->id,
            'election_id' => (string) $election->id,
            'race_id'     => $raceId !== null ? (string) $raceId : null,
            'cause'       => $cause,
        ];
    }
}
