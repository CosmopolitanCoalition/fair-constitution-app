<?php

namespace App\Services\Organizations;

use App\Models\Organization;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Internal restructuring — ownership-structure conversions WITHIN private
 * hands (transfers-conversions mockup §restructure): sole proprietorship →
 * partnership → cooperative and every other lawful move between the six
 * structures. Distinct from the public↔private interchange (F-ORG-006):
 * no compensation event, no legislature — the organization stays private
 * throughout.
 *
 * THE CONSENT RULE COMES FROM THE CURRENT STRUCTURE'S OWN RULES — the
 * structure being LEFT decides how it is left:
 *
 *   equal_partnership · partnership   unanimity of holders
 *   stock                             holders of a majority of UNITS
 *   member_owned · worker_owned       majority of holders, one each
 *   nonprofit                         unanimity of holders on record
 *
 * Consent is per HOLDER (org_ownership_stakes), recorded a row at a time;
 * the consent that meets the rule adopts the new structure in the same
 * act. Structure history is preserved on the public record: the proposal
 * rows persist, and every act appends to the audit chain.
 */
class OrgRestructureService
{
    public function __construct(private AuditService $audit) {}

    /** @return array{restructure_id: string, status: string} */
    public function propose(Organization $org, string $toStructure, User $actor): array
    {
        if ($org->structure === null) {
            throw new RuntimeException(
                'This organization has no declared structure to restructure from — structure is declared at registration.'
            );
        }

        $lawful = ['stock', 'partnership', 'equal_partnership', 'member_owned', 'worker_owned', 'nonprofit'];

        if (! in_array($toStructure, $lawful, true)) {
            throw new InvalidArgumentException("Unknown target structure [{$toStructure}].");
        }

        if ($toStructure === $org->structure) {
            throw new InvalidArgumentException('That is already the organization\'s structure.');
        }

        $this->assertHolder($org, $actor);

        $open = DB::table('org_restructures')
            ->where('organization_id', $org->id)
            ->where('status', 'proposed')
            ->whereNull('deleted_at')
            ->exists();

        if ($open) {
            throw new RuntimeException('A restructuring proposal is already open — one at a time; consent to it or let it be abandoned.');
        }

        return DB::transaction(function () use ($org, $toStructure, $actor) {
            $id = (string) Str::uuid();

            DB::table('org_restructures')->insert([
                'id'                  => $id,
                'organization_id'     => (string) $org->id,
                'from_structure'      => (string) $org->structure,
                'to_structure'        => $toStructure,
                'status'              => 'proposed',
                'proposed_by_user_id' => (string) $actor->id,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            $this->audit->append(
                module: 'organizations',
                event: 'org.restructure_proposed',
                payload: [
                    'organization_id' => (string) $org->id,
                    'restructure_id'  => $id,
                    'from'            => (string) $org->structure,
                    'to'              => $toStructure,
                ],
                ref: 'F-ORG-009',
                actorId: (string) $actor->id,
                jurisdictionId: $org->jurisdiction_id === null ? null : (string) $org->jurisdiction_id,
            );

            // Proposing consents. Under a one-holder structure that alone
            // can meet the rule.
            $result = $this->recordConsent($id, $org, $actor);

            return ['restructure_id' => $id, 'status' => $result['status']];
        });
    }

    /** @return array{restructure_id: string, status: string, consents: int, needed: string} */
    public function consent(string $restructureId, User $actor): array
    {
        return DB::transaction(function () use ($restructureId, $actor) {
            $row = DB::table('org_restructures')->where('id', $restructureId)->lockForUpdate()->first();

            if ($row === null || $row->status !== 'proposed') {
                throw new RuntimeException('That restructuring is not awaiting consent.');
            }

            $org = Organization::query()->findOrFail((string) $row->organization_id);

            $this->assertHolder($org, $actor);

            $already = DB::table('org_restructure_consents')
                ->where('restructure_id', $restructureId)
                ->where('holder_type', 'users')
                ->where('holder_id', (string) $actor->id)
                ->exists();

            if ($already) {
                throw new RuntimeException('Your consent is already on the record — once is enough.');
            }

            $result = $this->recordConsent($restructureId, $org, $actor);

            return ['restructure_id' => $restructureId] + $result;
        });
    }

    /**
     * The evaluation, readable on the page: who has consented, what the
     * rule demands, whether it is met.
     *
     * @return array{rule: string, needed: string, consented: int, holders: int, met: bool, consented_units: string, total_units: string}
     */
    public function tally(string $restructureId): array
    {
        $row = DB::table('org_restructures')->where('id', $restructureId)->first();

        if ($row === null) {
            throw new RuntimeException('Unknown restructuring.');
        }

        $holders = $this->holders((string) $row->organization_id);

        $consentedIds = DB::table('org_restructure_consents')
            ->where('restructure_id', $restructureId)
            ->where('holder_type', 'users')
            ->pluck('holder_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $consentedUnits = '0';
        $totalUnits = '0';
        foreach ($holders as $holderId => $units) {
            $totalUnits = bcadd($totalUnits, $units, 6);
            if (in_array($holderId, $consentedIds, true)) {
                $consentedUnits = bcadd($consentedUnits, $units, 6);
            }
        }

        $rule = $this->ruleFor((string) $row->from_structure);

        $met = match ($rule) {
            'unanimity'      => count($holders) > 0 && count(array_intersect(array_keys($holders), $consentedIds)) === count($holders),
            'unit_majority'  => bccomp($totalUnits, '0', 6) === 1 && bccomp(bcmul($consentedUnits, '2', 6), $totalUnits, 6) === 1,
            'holder_majority' => count($holders) > 0 && count(array_intersect(array_keys($holders), $consentedIds)) * 2 > count($holders),
        };

        return [
            'rule'            => $rule,
            'needed'          => match ($rule) {
                'unanimity'       => 'every holder',
                'unit_majority'   => 'holders of a majority of units',
                'holder_majority' => 'a majority of holders',
            },
            'consented'       => count(array_intersect(array_keys($holders), $consentedIds)),
            'holders'         => count($holders),
            'met'             => $met,
            'consented_units' => $consentedUnits,
            'total_units'     => $totalUnits,
        ];
    }

    // ── internals ────────────────────────────────────────────────────────

    /** @return array{status: string, consents: int, needed: string} */
    private function recordConsent(string $restructureId, Organization $org, User $actor): array
    {
        DB::table('org_restructure_consents')->insert([
            'id'             => (string) Str::uuid(),
            'restructure_id' => $restructureId,
            'holder_type'    => 'users',
            'holder_id'      => (string) $actor->id,
            'consented_at'   => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $tally = $this->tally($restructureId);

        if ($tally['met']) {
            $this->adopt($restructureId, $org, $actor);

            return ['status' => 'adopted', 'consents' => $tally['consented'], 'needed' => $tally['needed']];
        }

        return ['status' => 'proposed', 'consents' => $tally['consented'], 'needed' => $tally['needed']];
    }

    /**
     * Adoption — the consent that met the rule changes the structure in the
     * same act. The proposal row persists as history; the audit chain
     * carries the adoption.
     */
    private function adopt(string $restructureId, Organization $org, User $actor): void
    {
        $row = DB::table('org_restructures')->where('id', $restructureId)->first();

        DB::table('org_restructures')->where('id', $restructureId)->update([
            'status'     => 'adopted',
            'adopted_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organizations')->where('id', $org->id)->update([
            'structure'  => (string) $row->to_structure,
            'updated_at' => now(),
        ]);

        $this->audit->append(
            module: 'organizations',
            event: 'org.restructured',
            payload: [
                'organization_id' => (string) $org->id,
                'restructure_id'  => $restructureId,
                'from'            => (string) $row->from_structure,
                'to'              => (string) $row->to_structure,
            ],
            ref: 'F-ORG-009',
            actorId: (string) $actor->id,
            jurisdictionId: $org->jurisdiction_id === null ? null : (string) $org->jurisdiction_id,
        );
    }

    /**
     * Live holders and their units. Consent is an OWNER's act — an org with
     * no stakes on record has nobody who can lawfully consent, and says so.
     *
     * @return array<string, string> user id => units
     */
    private function holders(string $organizationId): array
    {
        $rows = DB::table('org_ownership_stakes')
            ->where('organization_id', $organizationId)
            ->where('holder_type', 'users')
            ->whereNull('ended_at')
            ->get(['holder_id', 'units']);

        $holders = [];
        foreach ($rows as $r) {
            $holders[(string) $r->holder_id] = bcadd($holders[(string) $r->holder_id] ?? '0', (string) $r->units, 6);
        }

        return $holders;
    }

    private function assertHolder(Organization $org, User $actor): void
    {
        $holders = $this->holders((string) $org->id);

        if ($holders === []) {
            throw new RuntimeException(
                'This organization has no ownership stakes on record — there is nobody whose consent a restructuring could collect.'
            );
        }

        if (! array_key_exists((string) $actor->id, $holders)) {
            throw new RuntimeException('Only a holder of an ownership stake restructures what they co-own.');
        }
    }

    private function ruleFor(string $structure): string
    {
        return match ($structure) {
            'stock' => 'unit_majority',
            'member_owned', 'worker_owned' => 'holder_majority',
            default => 'unanimity', // partnership · equal_partnership · nonprofit
        };
    }
}
