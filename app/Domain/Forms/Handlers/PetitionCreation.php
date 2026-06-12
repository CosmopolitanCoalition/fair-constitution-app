<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\User;
use App\Services\PetitionService;

/**
 * F-IND-009 — Petition Creation (R-05 per the registry; R-05 derives from
 * R-03 + the act of creating — RoleService grants it with the
 * association, so the gate is effectively R-03: any associated resident
 * may petition, Art. I).
 *
 * Validation + snapshots live in PetitionService::create (§E): law_text
 * non-empty, act_type (no dual_supermajority by petition), scale ⊆ the
 * creator's association chain, setting petitions bounds-checked through
 * the PROTECTED path; civic-population basis + resolved threshold pct +
 * threshold_count snapshot (CLK-17). Created → Gathering is atomic at
 * filing; the CLK-17 threshold-watch timer arms.
 */
class PetitionCreation implements FormHandler
{
    public function __construct(private readonly PetitionService $petitions)
    {
    }

    public function module(): string
    {
        return 'civic';
    }

    public function event(): string
    {
        return 'petition.created';
    }

    public function requiredRoles(): array
    {
        return ['R-05'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'A petition is created by a resident — system filing is not defined.',
                'Art. II §6'
            );
        }

        $petition = $this->petitions->create($actor, $payload);

        return [
            'petition_id'      => (string) $petition->id,
            'title'            => $petition->title,
            'act_type'         => $petition->act_type,
            'jurisdiction_id'  => (string) $petition->jurisdiction_id,
            'population_basis' => (int) $petition->population_basis,
            'threshold_pct'    => (string) $petition->threshold_pct,
            'threshold_count'  => (int) $petition->threshold_count,
            'status'           => $petition->status,
        ];
    }
}
