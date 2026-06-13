<?php

namespace App\Services\Judiciary;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\ConstitutionalChallenge;
use App\Models\ConstitutionalFinding;
use App\Models\Law;
use App\Models\LawVersion;
use App\Models\RemedyRecommendation;
use App\Services\AuditService;
use App\Services\EnactmentService;
use App\Services\PublicRecordService;
use Illuminate\Support\Facades\DB;

/**
 * JudicialRemedyService (PHASE_E_DESIGN_challenge_law §B.6) — owns the §5.5
 * direct law edit, THE Phase E exit criterion. When the legislature lets both
 * windows expire without amending (Path 1) or overriding (Path 2), the
 * judiciary applies its own remedy to the law text directly:
 *
 *   "If The Legislature does not modify the law nor override the Judiciary
 *    within the window, then the Judiciary applies its own remedy to the law
 *    directly to make the law non-contradictory and bring it in line with The
 *    Constitution." — Art. IV §5.5
 *
 * The edit appends a `law_versions` row (source='judicial_remedy'); version
 * history is PRESERVED (the prior version is never mutated or deleted). The
 * judicial-remedy source is the ONE source EnactmentService::amendLaw admits
 * past a CLK-19 referendum shield — the exact §5.5 guarantee that even a
 * population-supermajority-shielded law yields to a constitutional remedy.
 */
class JudicialRemedyService
{
    public function __construct(
        private readonly EnactmentService $enactments,
        private readonly PublicRecordService $records,
        private readonly AuditService $audit,
        private readonly ConstitutionalChallengeService $challenges,
    ) {}

    /**
     * Apply the recommended remedy directly to the offending law (§5.5).
     * Idempotent: a challenge no longer at legislative_window_open is a no-op
     * (already amended/overridden/remedied).
     *
     * Gated by the caller (the CLK-11 fire job or the F-JDG-006 handler) on
     * now ≥ max(veto_closes_at, remedy_due_at) — both windows must have closed.
     */
    public function applyRemedy(ConstitutionalChallenge $challenge): ?Law
    {
        return DB::transaction(function () use ($challenge): ?Law {
            $fresh = ConstitutionalChallenge::query()->whereKey($challenge->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->status !== ConstitutionalChallenge::STATUS_LEGISLATIVE_WINDOW_OPEN) {
                return null; // resolved before the remedy ran — the fire/handler self-guards
            }

            $recommendation = RemedyRecommendation::query()->find((string) $fresh->remedy_id);
            $finding = ConstitutionalFinding::query()->find((string) $fresh->finding_id);

            if ($recommendation === null || $finding === null) {
                throw new ConstitutionalViolation(
                    'A judicial remedy requires a recommendation and a finding (Art. IV §5).',
                    'Art. IV §5'
                );
            }

            // BOTH windows must have closed (§5.5 "does not modify ... nor
            // override ... within the window"). The clock-fired path arms CLK-11
            // to the max so a single fire satisfies this; the explicit handler
            // re-checks it.
            $latest = $recommendation->veto_closes_at->greaterThan($recommendation->remedy_due_at)
                ? $recommendation->veto_closes_at
                : $recommendation->remedy_due_at;

            if (now()->lessThan($latest)) {
                throw new ConstitutionalViolation(
                    'The judiciary applies its own remedy only AFTER both the remedy timeframe and the veto '
                    .'window have expired (Art. IV §5.5).',
                    'Art. IV §5'
                );
            }

            $law = Law::query()->whereKey((string) $finding->offending_law_id)->lockForUpdate()->firstOrFail();

            if ($recommendation->remedy_kind === RemedyRecommendation::KIND_MODIFY) {
                // §5.5 — append a law_versions row source='judicial_remedy';
                // history preserved. This path PIERCES a CLK-19 referendum
                // shield (EnactmentService::amendLaw admits judicial_remedy).
                $this->enactments->amendLaw(
                    law: $law,
                    text: (string) $recommendation->recommended_text,
                    source: LawVersion::SOURCE_JUDICIAL_REMEDY,
                    sourceRefType: 'constitutional_challenges',
                    sourceRefId: (string) $fresh->id,
                    viaForm: 'F-JDG-006',
                );
                // Law::STATUS_AMENDED is set by amendLaw — the modified law
                // remains in force at its new version.
            } else {
                // remove — append a final repeal version (history preserved)
                // AND flip the status to STRUCK (the judiciary removed it under
                // Art. IV §5, distinct from legislative `repealed`).
                $this->enactments->amendLaw(
                    law: $law,
                    text: sprintf('[STRUCK by judicial remedy — Art. IV §5.5] Act %s is removed for irreconcilable '
                        .'constitutional contradiction; the offending text is superseded by this repeal version.', $law->act_number),
                    source: LawVersion::SOURCE_JUDICIAL_REMEDY,
                    sourceRefType: 'constitutional_challenges',
                    sourceRefId: (string) $fresh->id,
                    viaForm: 'F-JDG-006',
                );

                $law->forceFill(['status' => Law::STATUS_STRUCK])->save();
            }

            $law->refresh();
            $newVersionId = DB::table('law_versions')
                ->where('law_id', (string) $law->id)
                ->orderByDesc('version_no')
                ->value('id');
            $newVersionNo = (int) $law->current_version_no;
            $newVersionHash = DB::table('law_versions')
                ->where('law_id', (string) $law->id)
                ->where('version_no', $newVersionNo)
                ->value('text_hash');

            $this->challenges->cancelTimers($recommendation, 'subject resolved — judicial remedy applied (Path 3)');

            $record = $this->records->publish(
                kind: 'act',
                title: sprintf(
                    'Judicial remedy applied — Act %s v%d: law brought into constitutional order (Art. IV §5)',
                    $law->act_number,
                    $newVersionNo
                ),
                body: 'The legislature neither modified the offending law nor overrode the finding within the '
                    .'windows; the judiciary applied its own remedy directly (Art. IV §5.5). Version history is '
                    .'preserved — the superseded version remains in the record.',
                attrs: [
                    'jurisdiction_id' => (string) $fresh->jurisdiction_id,
                    'via_form' => 'F-JDG-006',
                    'subject_type' => 'constitutional_challenges',
                    'subject_id' => (string) $fresh->id,
                ],
            );

            $fresh->forceFill([
                'status' => ConstitutionalChallenge::STATUS_JUDICIAL_REMEDY_APPLIED,
                'resolution_path' => ConstitutionalChallenge::PATH_JUDICIAL_REMEDY,
                'resolution_ref_type' => 'law_versions',
                'resolution_ref_id' => $newVersionId !== null ? (string) $newVersionId : null,
                'closed_at' => now(),
            ])->save();

            $fresh->forceFill(['status' => ConstitutionalChallenge::STATUS_CLOSED])->save();

            $this->audit->append(
                module: 'judiciary',
                event: 'challenge.judicial_remedy_applied',
                payload: [
                    'challenge_id' => (string) $fresh->id,
                    'law' => $law->act_number,
                    'remedy_kind' => $recommendation->remedy_kind,
                    'version_no' => $newVersionNo,
                    'text_hash' => $newVersionHash,
                    'law_status' => $law->status,
                ],
                ref: 'F-JDG-006',
                jurisdictionId: (string) $fresh->jurisdiction_id,
            );

            return $law;
        });
    }
}
