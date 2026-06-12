<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Candidacy;
use App\Models\Election;
use App\Models\User;
use App\Services\RoleService;
use Carbon\CarbonInterface;

/**
 * F-CAN-003 — Candidacy Withdrawal (R-06).
 *
 * THE BALLOT LOCK (design §C): withdrawal is only possible BEFORE the
 * finalist cutoff. Once CLK-21 freezes the finalist set, the published
 * ballot never changes — a post-cutoff filing is rejected with citation.
 * Withdrawal is a permanent public record (terminal ESM-06 state).
 */
class CandidacyWithdrawal implements FormHandler
{
    public function __construct(
        private readonly RoleService $roles,
    ) {
    }

    public function module(): string
    {
        return 'elections';
    }

    public function event(): string
    {
        return 'candidacy.withdrawn';
    }

    public function requiredRoles(): array
    {
        return ['R-06'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $candidacy = CampaignProfileSetup::ownStandingCandidacy($actor, $payload['candidacy_id'] ?? null, 'F-CAN-003');

        $cutoffAt = Election::query()
            ->whereKey($candidacy->election_id)
            ->value('finalist_cutoff_at');

        self::assertBeforeCutoff(
            $cutoffAt !== null ? \Carbon\CarbonImmutable::parse($cutoffAt) : null,
            now()->toImmutable(),
        );

        $candidacy->forceFill([
            'status'       => Candidacy::STATUS_WITHDRAWN,
            'withdrawn_at' => now(),
        ])->save();

        $this->roles->flushUser((string) $candidacy->user_id);

        return [
            'candidacy_id' => (string) $candidacy->id,
            'election_id'  => (string) $candidacy->election_id,
        ];
    }

    /**
     * Pure ballot-lock guard (pinned DB-free): once the finalist cutoff
     * has passed, the ballot is frozen. A null cutoff means none has been
     * published yet — withdrawal remains open.
     */
    public static function assertBeforeCutoff(?CarbonInterface $finalistCutoffAt, CarbonInterface $now): void
    {
        if ($finalistCutoffAt !== null && $now->greaterThanOrEqualTo($finalistCutoffAt)) {
            throw new ConstitutionalViolation(
                'The finalist cutoff has passed — the published ballot is locked and withdrawal is no '
                . 'longer possible. The candidacy remains on the ballot.',
                'CLK-21 · open-ballot spec (ballot lock)'
            );
        }
    }
}
