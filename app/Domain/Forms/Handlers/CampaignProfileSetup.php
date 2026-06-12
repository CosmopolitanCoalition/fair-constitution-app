<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Candidacy;
use App\Models\User;

/**
 * F-CAN-001 — Campaign Profile Setup (R-06).
 *
 * Length rails only (design §C — no content gatekeeping): platform
 * statement ≤ 10,000 chars; ≤ 20 position tags of ≤ 64 chars each.
 * Changes are appended to the public record — the audit entry carries
 * the new values verbatim (campaign profiles are public by nature).
 */
class CampaignProfileSetup implements FormHandler
{
    public const MAX_STATEMENT_CHARS = 10_000;
    public const MAX_TAGS            = 20;
    public const MAX_TAG_CHARS       = 64;

    public function module(): string
    {
        return 'elections';
    }

    public function event(): string
    {
        return 'candidacy.profile_updated';
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
        $candidacy = self::ownStandingCandidacy($actor, $payload['candidacy_id'] ?? null, 'F-CAN-001');

        $changes = [];

        if (array_key_exists('platform_statement', $payload)) {
            $statement = $payload['platform_statement'] !== null ? (string) $payload['platform_statement'] : null;

            if ($statement !== null && mb_strlen($statement) > self::MAX_STATEMENT_CHARS) {
                throw new ConstitutionalViolation(
                    'Platform statement exceeds ' . self::MAX_STATEMENT_CHARS . ' characters.',
                    'CGA Forms Catalog (F-CAN-001)'
                );
            }

            $candidacy->platform_statement = $statement;
            $changes['platform_statement'] = $statement;
        }

        if (array_key_exists('position_tags', $payload)) {
            $tags = self::cleanTags($payload['position_tags']);

            $candidacy->position_tags = $tags;
            $changes['position_tags'] = $tags;
        }

        if ($changes === []) {
            throw new ConstitutionalViolation(
                'F-CAN-001 carries no profile fields to update.',
                'CGA Forms Catalog (F-CAN-001)'
            );
        }

        $candidacy->save();

        return [
            'candidacy_id'   => (string) $candidacy->id,
            'election_id'    => (string) $candidacy->election_id,
            'fields_changed' => array_keys($changes),
        ] + $changes;
    }

    /**
     * Tag rails shared with F-IND-011's optional initial tags.
     *
     * @return list<string>
     */
    public static function cleanTags(mixed $tags): array
    {
        if (! is_array($tags) || ! array_is_list($tags)) {
            throw new ConstitutionalViolation(
                'position_tags must be a list of short strings.',
                'CGA Forms Catalog (F-CAN-001)'
            );
        }

        if (count($tags) > self::MAX_TAGS) {
            throw new ConstitutionalViolation(
                'At most ' . self::MAX_TAGS . ' position tags are allowed.',
                'CGA Forms Catalog (F-CAN-001)'
            );
        }

        $clean = [];

        foreach ($tags as $tag) {
            if (! is_string($tag) || trim($tag) === '' || mb_strlen($tag) > self::MAX_TAG_CHARS) {
                throw new ConstitutionalViolation(
                    'Each position tag must be a non-empty string of at most ' . self::MAX_TAG_CHARS . ' characters.',
                    'CGA Forms Catalog (F-CAN-001)'
                );
            }

            $clean[] = trim($tag);
        }

        return $clean;
    }

    /**
     * Shared R-06 ownership + standing check for the F-CAN handlers: the
     * actor's OWN candidacy, still standing (registered..finalist).
     */
    public static function ownStandingCandidacy(?User $actor, mixed $candidacyId, string $formId): Candidacy
    {
        $candidacy = Candidacy::query()->find($candidacyId);

        if ($candidacy === null) {
            throw new ConstitutionalViolation(
                "{$formId} targets an unknown candidacy.",
                "CGA Forms Catalog ({$formId})"
            );
        }

        if ($actor !== null && (string) $candidacy->user_id !== (string) $actor->getKey()) {
            throw new ConstitutionalViolation(
                "{$formId} may only be filed by the candidate themself.",
                "CGA Forms Catalog ({$formId})"
            );
        }

        $standing = in_array($candidacy->status, [
            Candidacy::STATUS_REGISTERED,
            Candidacy::STATUS_VALIDATED,
            Candidacy::STATUS_IN_POOL,
            Candidacy::STATUS_FINALIST,
        ], true);

        if (! $standing) {
            throw new ConstitutionalViolation(
                "Candidacy [{$candidacy->id}] is no longer standing (status: {$candidacy->status}).",
                "CGA Forms Catalog ({$formId})"
            );
        }

        return $candidacy;
    }
}
