<?php

namespace App\Services\Organizations;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Organization;
use App\Models\OrgContract;
use App\Models\OrgMembership;
use App\Models\OrgOwnershipStake;
use App\Models\OrgWorker;
use App\Models\User;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use Illuminate\Support\Str;

/**
 * D-O3 (PHASE_D_DESIGN_organizations §D) — the org registry: F-IND-012
 * registration (WF-ORG-01), ESM-18 status moves, profile management, and
 * F-ORG-007 voluntary dissolution (WF-ORG-10).
 *
 * Registration IS activation (the engine validation is WF-ORG-01's "Org
 * engine validates" step) — association is the ONLY requirement (R-03,
 * Art. I Economic Freedom). CGCs can never self-register (Art. III §5 —
 * legislature-only via F-LEG-019; validator-rejected pre-commit).
 *
 * `is_active`/`is_registered` are kept in sync with `status` for existing
 * readers (endorsement handlers, R-23 derivation); the boolean drop is a
 * deferred all-phases sweep (§E.4.1).
 */
class OrgRegistryService
{
    public function __construct(
        private readonly PublicRecordService $records,
        private readonly RoleService $roles,
    ) {
    }

    /**
     * F-IND-012 — register an organization (status 'active' immediately).
     *
     * @return array<string, mixed>
     */
    public function register(User $actor, array $payload): array
    {
        $type      = (string) ($payload['type'] ?? '');
        $structure = $payload['structure'] ?? null;
        $name      = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            throw new ConstitutionalViolation('Organization registration requires a name.', 'CGA Forms Catalog (F-IND-012)');
        }

        if (! in_array($type, [
            Organization::TYPE_POLITICAL_PARTY,
            Organization::TYPE_BUSINESS,
            Organization::TYPE_NONPROFIT,
            Organization::TYPE_INFORMAL,
        ], true)) {
            // The CGC branch is validator-rejected pre-commit with the
            // Art. III §5 citation; anything else is malformed.
            throw new ConstitutionalViolation(
                "Unknown organization type [{$type}] for self-registration.",
                'CGA Forms Catalog (F-IND-012)'
            );
        }

        if ($structure !== null && ! in_array($structure, Organization::STRUCTURES, true)) {
            throw new ConstitutionalViolation(
                "Unknown ownership structure [{$structure}].",
                'CGA Forms Catalog (F-IND-012)'
            );
        }

        $jurisdictionId = (string) ($payload['jurisdiction_id'] ?? '');

        $jurisdictionExists = \App\Models\Jurisdiction::query()->whereKey($jurisdictionId)->exists();

        if (! $jurisdictionExists) {
            throw new ConstitutionalViolation('F-IND-012 requires a valid jurisdiction_id.', 'CGA Forms Catalog (F-IND-012)');
        }

        $slug = $this->uniqueSlug($jurisdictionId, $name);

        $org = Organization::create([
            'jurisdiction_id'       => $jurisdictionId,
            'type'                  => $type,
            'structure'             => $structure,
            'name'                  => $name,
            'slug'                  => $slug,
            'purpose'               => isset($payload['purpose']) ? (string) $payload['purpose'] : null,
            'description'           => isset($payload['description']) ? (string) $payload['description'] : null,
            'ownership_type'        => 'private',
            'status'                => Organization::STATUS_ACTIVE,
            'is_active'             => true,
            'is_registered'         => true,
            'registered_at'         => now(),
            'agent_user_id'         => (string) $actor->getKey(),
            'registered_by_user_id' => (string) $actor->getKey(),
            'registered_via_form'   => 'F-IND-012',
            'worker_count'          => 0,
        ]);

        $record = $this->records->publish(
            kind: 'act',
            title: "Organization registered — {$name}",
            body: sprintf(
                '%s (%s%s) registered under Art. I Economic Freedom; agent: the registering individual. '
                . 'Association is the only requirement.',
                $name,
                $type,
                $structure !== null ? ", {$structure}" : ''
            ),
            attrs: [
                'actor_user_id'   => (string) $actor->getKey(),
                'jurisdiction_id' => $jurisdictionId,
                'via_form'        => 'F-IND-012',
                'subject_type'    => 'organizations',
                'subject_id'      => (string) $org->id,
            ],
        );

        $org->forceFill(['registration_record_id' => (string) $record->id])->save();

        // R-23 derives from agent_user_id.
        $this->roles->flushUser((string) $actor->getKey());

        return [
            'organization_id' => (string) $org->id,
            'name'            => $name,
            'type'            => $type,
            'structure'       => $structure,
            'slug'            => $slug,
            'jurisdiction_id' => $jurisdictionId,
            'status'          => Organization::STATUS_ACTIVE,
            'record_id'       => (string) $record->id,
        ];
    }

    /** ESM-18 status move, with the legacy booleans kept in sync. */
    public function setStatus(Organization $org, string $status): void
    {
        $org->forceFill([
            'status'        => $status,
            'is_active'     => $status === Organization::STATUS_ACTIVE,
            'is_registered' => $status !== Organization::STATUS_DISSOLVED,
            'dissolved_at'  => $status === Organization::STATUS_DISSOLVED ? now() : $org->dissolved_at,
        ])->save();
    }

    /**
     * F-ORG-007 — voluntary dissolution (WF-ORG-10): obligations settled
     * (no open contracts — engine-checked), memberships/workers ended,
     * stakes closed, packages archived; records + audit preserved.
     * CGCs are rejected (F-LEG-027 only).
     *
     * @return array<string, mixed>
     */
    public function dissolve(Organization $org, ?User $actor, ?string $reason): array
    {
        if ($org->is_cgc) {
            throw new ConstitutionalViolation(
                'A Common Good Corporation dissolves only by legislative act (F-LEG-027).',
                'Art. III §5'
            );
        }

        if ($org->status === Organization::STATUS_DISSOLVED) {
            throw new ConstitutionalViolation('Organization is already dissolved.', 'CGA Forms Catalog (F-ORG-007)');
        }

        $openContracts = OrgContract::query()
            ->where('organization_id', $org->id)
            ->whereIn('status', [OrgContract::STATUS_OFFERED, OrgContract::STATUS_ACTIVE])
            ->count();

        if ($openContracts > 0) {
            throw new ConstitutionalViolation(
                "Dissolution requires settled obligations — {$openContracts} contract(s) are still offered/active "
                . '(end or void them first).',
                'CGA Forms Catalog (F-ORG-007) · WF-ORG-10'
            );
        }

        $endedMemberships = OrgMembership::query()
            ->where('organization_id', $org->id)
            ->whereIn('status', [OrgMembership::STATUS_APPLIED, OrgMembership::STATUS_ACTIVE])
            ->update([
                'status'     => OrgMembership::STATUS_ENDED,
                'ended_at'   => now(),
                'end_reason' => 'dissolved',
                'updated_at' => now(),
            ]);

        $endedWorkers = OrgWorker::query()
            ->forEmployer(OrgWorker::EMPLOYER_ORGANIZATIONS, (string) $org->id)
            ->whereIn('status', [OrgWorker::STATUS_APPLIED, OrgWorker::STATUS_ACTIVE])
            ->update(['status' => OrgWorker::STATUS_ENDED, 'ended_at' => now(), 'updated_at' => now()]);

        $closedStakes = OrgOwnershipStake::query()
            ->where('organization_id', $org->id)
            ->open()
            ->update(['ended_at' => now(), 'updated_at' => now()]);

        \App\Models\OrgDocumentPackage::query()
            ->where('organization_id', $org->id)
            ->where('status', \App\Models\OrgDocumentPackage::STATUS_ACTIVE)
            ->update(['status' => \App\Models\OrgDocumentPackage::STATUS_RETIRED, 'updated_at' => now()]);

        $org->forceFill(['dissolution_reason' => $reason])->save();
        $this->setStatus($org, Organization::STATUS_DISSOLVED);

        if ($org->board_id !== null) {
            \App\Models\Board::query()->whereKey($org->board_id)->update(['status' => \App\Models\Board::STATUS_DISSOLVED]);
        }

        // Headcount recompute (queued — never synchronous).
        \App\Jobs\Organizations\RecomputeWorkerHeadcountJob::dispatch(OrgWorker::EMPLOYER_ORGANIZATIONS, (string) $org->id);

        $record = $this->records->publish(
            kind: 'act',
            title: "Organization dissolved — {$org->name}",
            body: $reason,
            attrs: [
                'actor_user_id'   => $actor?->getKey() !== null ? (string) $actor->getKey() : null,
                'jurisdiction_id' => (string) $org->jurisdiction_id,
                'via_form'        => 'F-ORG-007',
                'via_workflow'    => 'WF-ORG-10',
                'subject_type'    => 'organizations',
                'subject_id'      => (string) $org->id,
            ],
        );

        $this->roles->flush();

        return [
            'organization_id'   => (string) $org->id,
            'memberships_ended' => (int) $endedMemberships,
            'workers_ended'     => (int) $endedWorkers,
            'stakes_closed'     => (int) $closedStakes,
            'record_id'         => (string) $record->id,
        ];
    }

    private function uniqueSlug(string $jurisdictionId, string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;
        $n    = 1;

        while (Organization::withTrashed()
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base . '-' . (++$n);
        }

        return $slug;
    }
}
