<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\Committee;
use App\Models\CommitteePreference;
use App\Models\User;

/**
 * F-LEG-010 — Committee Preference Ranking (chamber ops §C.3).
 *
 * Ranked committee ids, most preferred first; rankings ⊆ the chamber's
 * live committees. Re-submittable until an F-SPK-005 run consumes them
 * (the run snapshots all inputs into its audit payload, so later edits
 * affect only future runs). Non-submitters default to committee creation
 * order at assignment time.
 */
class CommitteePreferenceRanking implements FormHandler
{
    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'committee.preferences_filed';
    }

    public function requiredRoles(): array
    {
        return ['R-09'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $legislature = ChamberActor::legislature($payload, 'F-LEG-010');
        $member      = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-010');

        $rankings = array_values(array_map('strval', (array) ($payload['rankings'] ?? [])));

        if ($rankings === [] || count($rankings) !== count(array_unique($rankings))) {
            throw new ConstitutionalViolation(
                'Preference rankings are a non-empty, non-repeating ordered list of committee ids.',
                'CGA Forms Catalog (F-LEG-010)'
            );
        }

        $open = Committee::query()
            ->where('legislature_id', $legislature->id)
            ->live()
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $unknown = array_diff($rankings, $open);

        if ($unknown !== []) {
            throw new ConstitutionalViolation(
                'Preference rankings name committees of this chamber; unknown: ' . implode(', ', $unknown) . '.',
                'CGA Forms Catalog (F-LEG-010)'
            );
        }

        CommitteePreference::query()->updateOrCreate(
            ['legislature_id' => (string) $legislature->id, 'member_id' => (string) $member->id],
            ['rankings' => $rankings, 'submitted_at' => now()],
        );

        return [
            'legislature_id' => (string) $legislature->id,
            'member_id'      => (string) $member->id,
            'rankings'       => $rankings,
        ];
    }
}
