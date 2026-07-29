<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\User;

/**
 * F-EDU-001 — Training Completion (R-01). GOVERNING EFFECTIVELY; operator
 * ruling 6 (2026-07-28): completing a training IS a constitutional act — it
 * ties to achievements and the one-time civic stipend, so it files through
 * the engine, never a side API.
 *
 * EVERY training is open to EVERY user (K2_ENGINE_PLAN §5.0.2): the role
 * list is the universal R-01 and no handler or validator ever asks "may
 * this person take this training" — the question is unaskable by
 * construction. F-EDU-001 itself is never act-gated: it is the door the
 * training gate's redirect points to (ruling A5 — acquiring is free,
 * acting asks), and a locked door there would deadlock the gate.
 *
 * THE RECORD IS THE WHOLE GATE-RELEVANT TRUTH. The accepted chain entry
 * (track + module + pass + time) is the ONLY thing TrainingGateService
 * reads (§5.2 READING RULE) — never the achievement ledger (CI-1), never
 * education_progress (node-local; this filing federates under FF&C). The
 * decoration legs — achievement mint, the once-only stipend keyed to it,
 * the education_progress latch — join in this wave's later build steps and
 * only ENRICH acceptance.
 *
 * THE §2 RAIL, enforced here as well as at the engine: the payload records
 * completion, never answers — not the learner's, not the correct ones.
 * Grading happened server-side before this filing exists; a payload
 * carrying any answer-key spelling is refused outright (and the engine's
 * SENSITIVE_KEYS guarantees even that refusal cannot leak a key).
 */
class TrainingCompletion implements FormHandler
{
    /** Payload keys that must never approach the chain (K2_ENGINE_PLAN §2/§5.0). */
    private const FORBIDDEN_KEYS = ['correct_keys', 'answer_key', 'answers'];

    public function module(): string
    {
        return 'education';
    }

    public function event(): string
    {
        return 'education.training_completed';
    }

    public function requiredRoles(): array
    {
        return ['R-01'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'Completing a training is a person\'s own act — system filing is not defined.',
                'CGA Forms Catalog (F-EDU-001)'
            );
        }

        $this->refuseAnswerContent($payload);

        $trackKey = trim((string) ($payload['track_key'] ?? ''));
        $moduleKey = trim((string) ($payload['module_key'] ?? ''));

        if ($trackKey === '' || $moduleKey === '') {
            throw new ConstitutionalViolation(
                'A completion names its track and module.',
                'CGA Forms Catalog (F-EDU-001)'
            );
        }

        if (($payload['passed'] ?? null) !== true) {
            throw new ConstitutionalViolation(
                'Only a PASSED comprehension check files a completion — a failed attempt is never recorded (retakes are unlimited by design).',
                'K2_ENGINE_PLAN §3.5 · as implemented'
            );
        }

        $score = $payload['score_pct'] ?? null;

        if (! is_int($score) || $score < 0 || $score > 100) {
            throw new ConstitutionalViolation(
                'A completion carries its integer score (0–100).',
                'CGA Forms Catalog (F-EDU-001)'
            );
        }

        $record = [
            'track_key'  => $trackKey,
            'module_key' => $moduleKey,
            'score_pct'  => $score,
            'passed'     => true,
        ];

        $surfaceId = trim((string) ($payload['surface_id'] ?? ''));

        if ($surfaceId !== '') {
            $record['surface_id'] = $surfaceId;
        }

        return $record;
    }

    /**
     * Refuse any payload carrying answer content at any depth. The engine's
     * SENSITIVE_KEYS would strip these from the rejection snapshot anyway —
     * this refusal exists so broken grading code fails LOUDLY instead of
     * silently filing a completion whose provenance included answers.
     */
    private function refuseAnswerContent(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), self::FORBIDDEN_KEYS, true)) {
                throw new ConstitutionalViolation(
                    'A completion records the pass, never answers — the learner\'s or the correct ones.',
                    'K2_ENGINE_PLAN §2 (the answer-key rail)'
                );
            }

            if (is_array($value)) {
                $this->refuseAnswerContent($value);
            }
        }
    }
}
