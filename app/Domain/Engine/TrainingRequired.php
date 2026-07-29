<?php

namespace App\Domain\Engine;

/**
 * The act-gate's refusal (operator ruling A5 — K2_ENGINE_PLAN §5.2).
 *
 * A redirect, not a wall: an untrained role-holder's first ROLE-AUTHORITY
 * act is refused with the structured payload the client needs to land on
 * the track's Learn content — pass the comprehension quiz, file F-EDU-001,
 * retry the act, and it proceeds. The gate is open permanently after that
 * (the completion record is durable and federates).
 *
 * It IS a ConstitutionalViolation: the engine records the refusal as a
 * first-class rejected chain entry with its citation, exactly like every
 * other constitutional denial. bootstrap/app.php renders it specially —
 * JSON callers get `training_required` alongside message + citation;
 * browser callers are redirected to the lesson itself.
 */
class TrainingRequired extends ConstitutionalViolation
{
    public function __construct(
        string $message,
        public readonly string $track,
        public readonly ?string $surfaceId,
        public readonly string $lessonHref,
    ) {
        parent::__construct($message, 'GOVERNING EFFECTIVELY (ruling A5) · as implemented');
    }

    /** The structured half of the refusal (§5.2: track, surface id, lesson href). */
    public function trainingRequired(): array
    {
        return [
            'track'       => $this->track,
            'surface_id'  => $this->surfaceId,
            'lesson_href' => $this->lessonHref,
        ];
    }
}
