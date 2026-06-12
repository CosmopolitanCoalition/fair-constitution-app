<?php

namespace App\Domain\Forms\Contracts;

use App\Models\Election;
use App\Models\ElectionAudit;
use App\Models\ElectionCertification;

/**
 * Seam between the F-ELB-004 / F-ELB-006 board handlers and the WI-B5
 * tabulation → certification → seating pipeline.
 *
 * WI-B4 (this work item) ships the certification ENTRY point: F-ELB-004
 * validates (all races tabulated, record hashes present, idempotency,
 * board-member provenance), writes the `election_certifications` row and
 * flips ESM-03 to `certified`, then calls certify() INSIDE the same engine
 * transaction. WI-B5 plugs the side-effect block in by rebinding this
 * interface in ConstitutionProvider (no handler change):
 *
 *   certify()          — winners → `legislature_members` (status 'elected',
 *                        vote_share_norm, seat_type from race seat_kind),
 *                        `terms` rows (lockstep), legislature forming →
 *                        active, arm CLK-01 / CLK-10, create election N+1 +
 *                        open its approval phase (design §B.2.5). Returns
 *                        extra audit payload: winners[], next_election_id.
 *   beginAuditRerun()  — dispatch TabulateElectionJob(kind='audit_rerun')
 *                        for the audited race(s) on the `long-running`
 *                        queue and link `election_audits.tabulation_id`
 *                        (design §C F-ELB-006).
 *
 * WI-B4 binds NoopCertificationPipeline (records the deferral in the audit
 * payload); the orchestrator rebinds after WI-B5 merges.
 */
interface CertificationPipeline
{
    /**
     * Run the post-certification seating block (WI-B5).
     *
     * @return array extra audit payload merged into the F-ELB-004 entry —
     *               e.g. ['winners' => [{user_id, race_id, seat_no}],
     *               'next_election_id' => ...]
     */
    public function certify(Election $election, ElectionCertification $certification): array;

    /**
     * Start the audit re-tabulation for an F-ELB-006 order (WI-B5).
     */
    public function beginAuditRerun(ElectionAudit $audit): void;
}
