<?php

namespace App\Services\Organizations;

use App\Models\Jurisdiction;
use App\Models\Organization;
use App\Models\OrgMembership;
use App\Models\User;
use App\Services\Demo\SimBoardService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase J (W-0300) — the two real nonprofits, seeded into a synthetic world.
 *
 * THE LEGAL REALITY (operator's word 2026-07-26, not derivable from code):
 *   Cosmopolitan Party Foundation          the legal entity, the PARENT
 *   Cosmopolitan Coalition of United Earth a PROJECT of the Foundation, the CHILD
 *                                           (parent_organization_id), the authoring side
 *   Cosmopolitan Coalition Action Fund     does not exist and may never; nothing here
 *                                           reserves a row, a slug or a name for it
 *
 * WHERE (operator order 2026-09-14): both are registered at New York County,
 * New York, the jurisdiction the sim world load already holds (slug
 * usa-3-new-york). The plan of 2026-07-26 said Earth; the newer word wins.
 *
 * WHAT IS WRITTEN, and through which door:
 *   - both organisations through OrgRegistryService::register, the F-IND-012
 *     path, filed by a synthetic resident of the home jurisdiction (Art. I
 *     association is the only requirement; on a synthetic world the resident
 *     is synthetic too);
 *   - the parent link as a deliberate second step (register() cannot set it);
 *   - the Coalition's voluntary public-domain charter, one-way (model guard);
 *   - a member roster from the home jurisdiction's synthetic residents, private
 *     by default (org_memberships.is_public stays false: no one is outed by a seeder);
 *   - a member-elected board on the FOUNDATION, the entity that has governance
 *     (plan §7, open item 1), seated as a system act by SimBoardService.
 *
 * WHAT IS NOT: synthetic staff on a real organisation (plan §7: the honest
 * co-determination answer at this size is zero); the Action Fund; any US tax
 * category (context for us, never a field).
 *
 * Idempotent: every step looks before it writes, so the sim world load may
 * call ensure() on every run and a demo command may call it again. Callers
 * decide whether the world is synthetic-safe (GuardsSyntheticData); this
 * service only writes what it is asked to.
 */
class CoalitionSeedService
{
    public const HOME_SLUG = 'usa-3-new-york'; // New York County, New York

    public const FOUNDATION_NAME = 'Cosmopolitan Party Foundation';

    public const COALITION_NAME = 'Cosmopolitan Coalition of United Earth';

    /** Members enrolled from the home jurisdiction's residents (bounded). */
    public const MEMBER_SAMPLE = 24;

    /** Owner-side seats on the Foundation's board. */
    public const BOARD_SEATS = 5;

    public function __construct(
        private readonly OrgRegistryService $registry,
        private readonly SimBoardService $boards,
    ) {}

    /** The home jurisdiction, or null when this world holds no New York County. */
    public function home(): ?Jurisdiction
    {
        return Jurisdiction::query()->where('slug', self::HOME_SLUG)->whereNull('deleted_at')->first();
    }

    /** @return array<string,mixed> what exists after the call, with what was written */
    public function ensure(?User $actor = null): array
    {
        $home = $this->home();
        if ($home === null) {
            return ['status' => 'skipped', 'reason' => 'home jurisdiction absent: '.self::HOME_SLUG];
        }

        $residents = $this->residentPool((string) $home->id);
        $actor ??= $residents === [] ? null : User::query()->find($residents[0]);
        if ($actor === null) {
            return ['status' => 'skipped', 'reason' => 'no resident of the home jurisdiction to file F-IND-012'];
        }

        $written = [];

        $foundation = $this->find(self::FOUNDATION_NAME, (string) $home->id);
        if ($foundation === null) {
            $foundation = $this->register($actor, (string) $home->id, self::FOUNDATION_NAME,
                'A nonprofit foundation for cosmopolitan governance: it publishes the Fair Constitution template and this application, and it holds the governance of the Coalition programme.');
            $written[] = 'foundation';
        }

        $coalition = $this->find(self::COALITION_NAME, (string) $home->id);
        if ($coalition === null) {
            $coalition = $this->register($actor, (string) $home->id, self::COALITION_NAME,
                'A programme of the '.self::FOUNDATION_NAME.': the authoring and operating side, which writes and publishes the template, the application and its education material. It is not a separate legal entity.');
            $written[] = 'coalition';
        }

        // The parent link is a deliberate second step (plan §9): registration
        // never sets it, and a parent grants no power (plan §10).
        if ((string) $coalition->parent_organization_id !== (string) $foundation->id) {
            $coalition->forceFill(['parent_organization_id' => (string) $foundation->id])->save();
            $written[] = 'parent_link';
        }

        // The voluntary charter, one-way. The Coalition's work is public domain by
        // its own choice; the model refuses any later flip back.
        if (! $coalition->public_domain_charter) {
            $coalition->forceFill(['public_domain_charter' => true])->save();
            $written[] = 'public_domain_charter';
        }

        // Members: the home jurisdiction's residents, private by default.
        $enrolled = 0;
        foreach ([$foundation, $coalition] as $org) {
            $enrolled += $this->enrol($org, $residents);
        }
        if ($enrolled > 0) {
            $written[] = "memberships:{$enrolled}";
        }

        // The board sits on the Foundation, the entity with governance.
        $members = $this->memberIds($foundation);
        $seated = $this->boards->seatOrganizationBoard($foundation, self::BOARD_SEATS, array_slice($members, 0, self::BOARD_SEATS), (string) $home->id);
        if ($seated > 0) {
            $written[] = "board_seats:{$seated}";
        }

        return [
            'status' => 'ok',
            'home' => ['id' => (string) $home->id, 'name' => $home->name, 'slug' => $home->slug],
            'foundation' => ['id' => (string) $foundation->id, 'slug' => $foundation->slug, 'board_id' => $foundation->fresh()->board_id],
            'coalition' => ['id' => (string) $coalition->id, 'slug' => $coalition->slug, 'parent_organization_id' => (string) $coalition->parent_organization_id, 'public_domain_basis' => $coalition->fresh()->publicDomainBasis()],
            'written' => $written,
        ];
    }

    /** Retire what ensure() wrote (demo teardown only; the caller guards the world). */
    public function teardown(): array
    {
        $home = $this->home();
        if ($home === null) {
            return ['removed' => 0];
        }

        $removed = 0;
        foreach ([self::COALITION_NAME, self::FOUNDATION_NAME] as $name) {
            $org = $this->find($name, (string) $home->id);
            if ($org === null) {
                continue;
            }
            DB::transaction(function () use ($org) {
                if ($org->board_id !== null) {
                    DB::table('board_seats')->where('board_id', $org->board_id)->update(['deleted_at' => now()]);
                    DB::table('boards')->where('id', $org->board_id)->update(['status' => 'dissolved', 'deleted_at' => now()]);
                }
                DB::table('org_memberships')->where('organization_id', (string) $org->id)->update(['status' => OrgMembership::STATUS_ENDED, 'ended_at' => now(), 'deleted_at' => now()]);
                $org->forceFill(['status' => Organization::STATUS_DISSOLVED, 'is_active' => false, 'dissolved_at' => now(), 'dissolution_reason' => 'demo teardown'])->save();
                $org->delete();
            });
            $removed++;
        }

        return ['removed' => $removed];
    }

    private function find(string $name, string $jurisdictionId): ?Organization
    {
        return Organization::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('name', $name)
            ->where('type', Organization::TYPE_NONPROFIT)
            ->whereNull('deleted_at')
            ->first();
    }

    private function register(User $actor, string $jurisdictionId, string $name, string $purpose): Organization
    {
        $result = $this->registry->register($actor, [
            'type' => Organization::TYPE_NONPROFIT,
            'structure' => Organization::STRUCTURE_NONPROFIT,
            'name' => $name,
            'jurisdiction_id' => $jurisdictionId,
            'purpose' => $purpose,
        ]);

        return Organization::query()->findOrFail($result['organization_id']);
    }

    /** @param list<string> $residents */
    private function enrol(Organization $org, array $residents): int
    {
        $existing = OrgMembership::query()
            ->where('organization_id', (string) $org->id)
            ->whereNull('deleted_at')
            ->pluck('user_id')->map(fn ($id) => (string) $id)->all();

        $now = now();
        $rows = [];
        foreach (array_slice($residents, 0, self::MEMBER_SAMPLE) as $userId) {
            if (in_array($userId, $existing, true)) {
                continue;
            }
            $rows[] = [
                'id' => (string) Str::uuid(),
                'organization_id' => (string) $org->id,
                'user_id' => $userId,
                'kind' => OrgMembership::KIND_MEMBER,
                'status' => OrgMembership::STATUS_ACTIVE,
                'applied_at' => $now,
                'accepted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($rows !== []) {
            DB::table('org_memberships')->insert($rows);
        }

        return count($rows);
    }

    /** @return list<string> */
    private function memberIds(Organization $org): array
    {
        return OrgMembership::query()
            ->where('organization_id', (string) $org->id)
            ->where('status', OrgMembership::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->orderBy('user_id')
            ->pluck('user_id')->map(fn ($id) => (string) $id)->all();
    }

    /** The home jurisdiction's synthetic residents, bounded, deterministic. */
    private function residentPool(string $jurisdictionId): array
    {
        return DB::table('residency_confirmations as rc')
            ->join('users as u', 'u.id', '=', 'rc.user_id')
            ->where('rc.jurisdiction_id', $jurisdictionId)->where('rc.is_active', true)
            ->where('u.email', 'like', 'sim-%@demo.invalid')
            ->orderBy('rc.user_id')->limit(self::MEMBER_SAMPLE)
            ->pluck('rc.user_id')->map(fn ($id) => (string) $id)->all();
    }
}
