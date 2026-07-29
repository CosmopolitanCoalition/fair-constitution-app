<?php

namespace App\Services\Education;

use App\Domain\Engine\TrainingRequired;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * THE TRAINING GATE — one rule, one home (K2_ENGINE_PLAN §5.2, operator
 * ruling A5: "acquiring is free, acting asks").
 *
 * Answers ONE question: does a qualifying F-EDU-001 completion exist for
 * this user and this role's track? Consulted by the ConstitutionalEngine
 * (and nothing else) between authorization and validation, so the refusal
 * binds at the FIRST role-authority act — never at candidacy,
 * certification, seating, appointment, registration, presence, speech, or
 * any rights-act.
 *
 * THE READING RULE (recorded doctrine, desk ACK 2026-07-29): the gate
 * reads ONLY accepted F-EDU-001 chain entries.
 *   - NEVER the achievement ledger — CI-1 stays absolute: achievements
 *     decorate, they never confer power. This file imports no Achievement
 *     anything (source-pinned by EducationNoGateTest).
 *   - NEVER education_progress — that table is node-local resume state;
 *     the filed F-EDU-001 federates under Full Faith & Credit, so a
 *     role-holder trained on another node is actionable here.
 *   - Answer-key secrecy untouched: the completion record carries track +
 *     module + pass + time, nothing the §2 rail protects.
 *
 * STRUCTURAL RAIL: the rights families are refused HERE, not just by
 * config absence — trackFor() returns null for F-IND/F-CAN/F-SOC/F-EDU
 * regardless of what config/cga/education.php says, so no config edit can
 * ever gate a right (Art. I; §6.5 pin).
 */
class TrainingGateService
{
    /** Families the gate can never touch, whatever the config says. */
    private const NEVER_GATED_PREFIXES = ['F-IND', 'F-CAN', 'F-SOC', 'F-EDU'];

    /** The gated track for a canonical form id, or null when ungated. */
    public function trackFor(string $canonicalId): ?string
    {
        foreach (self::NEVER_GATED_PREFIXES as $prefix) {
            if (str_starts_with($canonicalId, $prefix)) {
                return null;
            }
        }

        $track = config('cga.education.gated_forms')[$canonicalId] ?? null;

        return is_string($track) && $track !== '' ? $track : null;
    }

    /**
     * The one question. Accepted F-EDU-001 entries only — the engine
     * records rejections under a '.rejected' event AND rejected=true, and
     * both filters guard here.
     */
    public function hasCompleted(User $user, string $track): bool
    {
        return AuditEntry::query()
            ->where('ref', 'F-EDU-001')
            ->where('event', 'education.training_completed')
            ->where('rejected', false)
            ->where('actor_user_id', (string) $user->getKey())
            ->where('payload->track_key', $track)
            ->exists();
    }

    /**
     * A REDIRECT NEEDS A DESTINATION (A5's own precondition). The gate is
     * lawful because the training is "free, in-app, and minutes long — a
     * non-barrier by construction"; until a live track with a live module
     * is actually PUBLISHED for this key, that premise fails and the gate
     * stays open. A gate with no door is a wall, and A5 ruled a redirect,
     * not a wall — a virgin world's first legislature is never blocked by
     * a curriculum nobody has published yet. Publishing the content ARMS
     * the gate; no switch, no flag.
     */
    public function hasLiveTraining(string $track): bool
    {
        return DB::table('education_tracks as t')
            ->join('education_modules as m', 'm.track_id', '=', 't.id')
            ->where('t.key', $track)->where('t.status', 'live')->whereNull('t.deleted_at')
            ->where('m.status', 'live')->whereNull('m.deleted_at')
            ->exists();
    }

    /**
     * The engine's consultation point. System filings (null actor) pass —
     * jobs and clock handlers hold no role to train for. Ungated forms
     * pass. An unarmed track (nothing published to learn) passes. A
     * trained holder passes. Everyone else gets the redirect.
     *
     * @throws TrainingRequired
     */
    public function assertMayAct(?User $actor, string $canonicalId): void
    {
        if ($actor === null) {
            return;
        }

        $track = $this->trackFor($canonicalId);

        if ($track === null || ! $this->hasLiveTraining($track) || $this->hasCompleted($actor, $track)) {
            return;
        }

        throw new TrainingRequired(
            'This act exercises a role\'s authority, and the role\'s training has not been completed yet — '
            .'the training is free, in-app, and minutes long; pass its comprehension check and retry.',
            track: $track,
            surfaceId: config('cga.education.surface_by_track')[$track] ?? null,
            lessonHref: config('cga.education.lesson_href_by_track')[$track] ?? '/learn',
        );
    }
}
