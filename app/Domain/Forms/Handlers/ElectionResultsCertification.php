<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\CertificationPipeline;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\BoardProvenance;
use App\Models\Election;
use App\Models\ElectionAudit;
use App\Models\ElectionCertification;
use App\Models\Tabulation;
use App\Models\User;
use App\Services\ConstitutionalVersionService;

/**
 * F-ELB-004 — Election Results Certification (R-08).
 *
 * PHASE B / WI-B4 SCOPE — THE CERTIFICATION ENTRY POINT. This handler
 * owns the constitutional gate:
 *
 *  - every race of the election has a COMPLETE tabulation with a sealed
 *    record_hash (the audit chain already holds them);
 *  - idempotency: exactly one current certification per election; a
 *    second filing is only lawful as the superseding certification after
 *    an audit re-run with outcome 'corrected' (F-ELB-006 path);
 *  - board-member provenance: human actors certify through their seated
 *    member row on THIS board; the bootstrap board certifies through its
 *    synthetic system member row (certified_by_member_id is effectively
 *    NOT NULL — engine check, design §A B-9).
 *
 * THE SEAM (design §C / WI-B5): after the certification row commits state
 * (election → 'certified', count_record_hash sealed), the handler calls
 * CertificationPipeline::certify() INSIDE the same engine transaction.
 * WI-B5 rebinds the pipeline to the real seating block (winners →
 * legislature_members + terms, legislature forming → active, CLK-01 /
 * CLK-10 arming, election N+1 approval open — design §B.2.5). WI-B4
 * binds NoopCertificationPipeline, which records the deferral.
 */
class ElectionResultsCertification implements FormHandler
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
        return 'election.certified';
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
        $election = Election::query()->find($payload['election_id'] ?? null);

        if ($election === null) {
            throw new ConstitutionalViolation(
                'F-ELB-004 targets an unknown election.',
                'CGA Forms Catalog (F-ELB-004)'
            );
        }

        if (! in_array($election->status, [Election::STATUS_TABULATING, Election::STATUS_AUDIT_RERUN], true)) {
            throw new ConstitutionalViolation(
                "Election [{$election->id}] is not certifiable (status: {$election->status}; "
                . 'requires tabulating or audit_rerun).',
                'CGA Forms Catalog (F-ELB-004)'
            );
        }

        // G-VER — the count is sealed under the constitutional_version pinned when
        // the election opened, never the deployed one. The freeze (Art. II §7) should
        // make a mid-contest bump impossible; this is the belt-and-suspenders at the
        // certification boundary — if the version moved under a live count, REFUSE
        // rather than seal a result re-ruled by code that arrived mid-game. Elections
        // that predate version-pinning (null) are grandfathered.
        $pinned = $election->constitutional_version;

        if ($pinned !== null && $pinned !== app(ConstitutionalVersionService::class)->derive()) {
            throw new ConstitutionalViolation(
                "Election [{$election->id}] opened under constitutional_version [{$pinned}] but the deployed "
                . 'version has changed — certifying would seal a count under rules that moved mid-contest. '
                . 'A constitutional-version upgrade cannot disrupt an electoral process in flight.',
                'Art. II §7'
            );
        }

        $recordHashes = $this->raceRecordHashes($election);
        $member       = BoardProvenance::resolveMember($actor, $election, 'F-ELB-004');

        $superseded = $this->resolveIdempotency($election);

        // Hash over all race record hashes, order-independent (sorted by
        // race id) — sealed into the chain with this entry.
        ksort($recordHashes);
        $countRecordHash = hash('sha256', implode("\n", array_map(
            fn (string $raceId) => $raceId . ':' . $recordHashes[$raceId],
            array_keys($recordHashes),
        )));

        $certification = ElectionCertification::query()->create([
            'election_id'            => (string) $election->id,
            'election_board_id'      => (string) $member->election_board_id,
            'certified_by_member_id' => (string) $member->id,
            'certified_at'           => now(),
            'count_record_hash'      => $countRecordHash,
            'status'                 => ElectionCertification::STATUS_CERTIFIED,
        ]);

        $election->forceFill([
            'status'       => Election::STATUS_CERTIFIED,
            'certified_at' => now(),
        ])->save();

        $election->races()->update(['status' => Election::STATUS_CERTIFIED]);

        // WI-B5 seam — seating block runs here once the real pipeline is
        // bound; the no-op records the deferral in the audit payload.
        $pipelineExtra = $this->pipeline->certify($election, $certification);

        return array_merge([
            'election_id'              => (string) $election->id,
            'certification_id'         => (string) $certification->id,
            'count_record_hash'        => $countRecordHash,
            'races_certified'          => count($recordHashes),
            'superseded_certification' => $superseded,
        ], $pipelineExtra);
    }

    /**
     * Every race must carry a complete tabulation with a record hash —
     * the latest complete run per race wins (audit re-runs supersede).
     *
     * @return array<string, string> race_id => record_hash
     */
    private function raceRecordHashes(Election $election): array
    {
        $races = $election->races()->get(['id']);

        if ($races->isEmpty()) {
            throw new ConstitutionalViolation(
                'Election has no races to certify.',
                'CGA Forms Catalog (F-ELB-004)'
            );
        }

        $hashes = [];

        foreach ($races as $race) {
            $tabulation = Tabulation::query()
                ->where('race_id', (string) $race->id)
                ->where('status', Tabulation::STATUS_COMPLETE)
                ->whereNotNull('record_hash')
                ->orderByDesc('completed_at')
                ->first(['id', 'record_hash']);

            if ($tabulation === null) {
                throw new ConstitutionalViolation(
                    "Race [{$race->id}] has no complete tabulation with a sealed record hash — "
                    . 'certification requires every race counted.',
                    'CGA Forms Catalog (F-ELB-004)'
                );
            }

            $hashes[(string) $race->id] = (string) $tabulation->record_hash;
        }

        return $hashes;
    }

    /**
     * One current certification per election; superseding requires a
     * resolved audit re-run with outcome 'corrected' (F-ELB-006 path).
     *
     * @return string|null id of the certification this filing supersedes
     */
    private function resolveIdempotency(Election $election): ?string
    {
        $current = ElectionCertification::query()
            ->where('election_id', (string) $election->id)
            ->where('status', ElectionCertification::STATUS_CERTIFIED)
            ->first();

        if ($current === null) {
            return null;
        }

        $corrected = ElectionAudit::query()
            ->where('election_id', (string) $election->id)
            ->where('outcome', ElectionAudit::OUTCOME_CORRECTED)
            ->exists();

        if (! $corrected) {
            throw new ConstitutionalViolation(
                "Election [{$election->id}] is already certified — a second certification requires an "
                . "audit re-run with outcome 'corrected' (F-ELB-006).",
                'CGA Forms Catalog (F-ELB-004)'
            );
        }

        $current->forceFill(['status' => ElectionCertification::STATUS_SUPERSEDED_BY_AUDIT])->save();

        return (string) $current->id;
    }
}
