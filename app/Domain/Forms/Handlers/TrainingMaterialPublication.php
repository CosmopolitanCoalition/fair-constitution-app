<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\User;

/**
 * F-EDU-002 — Training Material Publication (R-23). Art. III §5.
 *
 * The content plane's act, distinct from the learner's (F-EDU-001):
 * publishing or revising a training module under the public-domain
 * dedication. Filed by the authoring body's agent — per operator ruling 3
 * (2026-07-25) the authoring org is the Cosmopolitan Coalition of United
 * Earth (the Δ4 bridge, K2_ENGINE_PLAN §7).
 *
 * The IP dedication itself goes through CgcIpRegisterService::dedicate()
 * and NOTHING else — that service's dedicate-only surface is pinned by
 * CgcIpPublicDomainTest and this handler extends the contract, never
 * bypasses it. This filing records the publication act and carries the
 * dedication's register reference; the education_modules content write
 * joins when the K-2 schema lands (this wave, slot-sequenced).
 *
 * What it never records (K2_ENGINE_PLAN §5.0): the question bank's
 * correct_keys — the answer catalog is server-side only, and a payload
 * carrying any answer-key spelling is refused outright.
 */
class TrainingMaterialPublication implements FormHandler
{
    /** Payload keys that must never approach the chain (K2_ENGINE_PLAN §2/§5.0). */
    private const FORBIDDEN_KEYS = ['correct_keys', 'answer_key', 'answers'];

    private const ACTIONS = ['publish', 'revise'];

    public function module(): string
    {
        return 'education';
    }

    public function event(): string
    {
        return 'education.material_published';
    }

    public function requiredRoles(): array
    {
        return ['R-23'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'Publication is the authoring body\'s agent\'s act — system filing is not defined.',
                'CGA Forms Catalog (F-EDU-002)'
            );
        }

        $this->refuseAnswerContent($payload);

        $moduleKey = trim((string) ($payload['module_key'] ?? ''));
        $title = trim((string) ($payload['title'] ?? ''));
        $action = trim((string) ($payload['action'] ?? ''));

        if ($moduleKey === '' || $title === '') {
            throw new ConstitutionalViolation(
                'A publication names its module and title.',
                'CGA Forms Catalog (F-EDU-002)'
            );
        }

        if (! in_array($action, self::ACTIONS, true)) {
            throw new ConstitutionalViolation(
                'A publication is a publish or a revise.',
                'CGA Forms Catalog (F-EDU-002)'
            );
        }

        $record = [
            'module_key' => $moduleKey,
            'title'      => $title,
            'action'     => $action,
        ];

        $dedicationRef = trim((string) ($payload['ip_register_entry_id'] ?? ''));

        if ($dedicationRef !== '') {
            $record['ip_register_entry_id'] = $dedicationRef;
        }

        return $record;
    }

    /**
     * Refuse any payload carrying answer content at any depth — the
     * question bank never rides a publication filing (server-side catalog
     * only). Same posture as F-EDU-001's refusal; the engine's
     * SENSITIVE_KEYS guarantees even the rejection cannot leak a key.
     */
    private function refuseAnswerContent(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), self::FORBIDDEN_KEYS, true)) {
                throw new ConstitutionalViolation(
                    'A publication never carries the question bank\'s answers — the catalog is server-side only.',
                    'K2_ENGINE_PLAN §2 (the answer-key rail)'
                );
            }

            if (is_array($value)) {
                $this->refuseAnswerContent($value);
            }
        }
    }
}
