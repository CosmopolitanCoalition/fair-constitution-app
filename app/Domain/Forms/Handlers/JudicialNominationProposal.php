<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Models\User;
use App\Services\Judiciary\JudicialNominationService;

final class JudicialNominationProposal implements FormHandler
{
    public function __construct(private JudicialNominationService $nominations) {}

    public function module(): string
    {
        return 'judiciary';
    }

    public function event(): string
    {
        return 'judicial.nomination_proposed';
    }

    public function requiredRoles(): array
    {
        return ['R-09', 'R-10', 'R-11'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        return $this->nominations->propose($actor, $payload);
    }
}
