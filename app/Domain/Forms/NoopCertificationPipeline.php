<?php

namespace App\Domain\Forms;

use App\Domain\Forms\Contracts\CertificationPipeline;
use App\Models\Election;
use App\Models\ElectionAudit;
use App\Models\ElectionCertification;

/**
 * Default (WI-B4) certification pipeline: F-ELB-004 records the
 * certification and F-ELB-006 records the audit order, but the seating
 * side-effect block (winners → members → terms → legislature active →
 * CLK-01/CLK-10 → N+1 approval open) and the audit re-tabulation dispatch
 * land with WI-B5, which the orchestrator rebinds in ConstitutionProvider.
 */
class NoopCertificationPipeline implements CertificationPipeline
{
    public function certify(Election $election, ElectionCertification $certification): array
    {
        return [
            'winners'          => [],
            'next_election_id' => null,
            'seating'          => 'deferred — WI-B5 pipeline binding pending',
        ];
    }

    public function beginAuditRerun(ElectionAudit $audit): void
    {
        // TabulateElectionJob(kind='audit_rerun') dispatch lands in WI-B5.
    }
}
