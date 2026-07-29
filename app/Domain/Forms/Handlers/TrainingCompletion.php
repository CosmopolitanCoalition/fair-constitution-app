<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\User;
use App\Services\AchievementService;
use App\Services\Education\TrainingStipendService;
use Illuminate\Support\Facades\DB;

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
 * decoration legs — the education_progress latch, the ACH-EDU-001 mint,
 * the once-only stipend keyed to the mint's freshness — only ENRICH
 * acceptance; all three ride the engine's one transaction (§5.1).
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

    /** The once-per-person decoration whose FRESH mint is the stipend's proof. */
    private const AWARD_KEY = 'ACH-EDU-001';

    public function __construct(
        private readonly AchievementService $achievements,
        private readonly TrainingStipendService $stipend,
    ) {
    }

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

        // The named training must exist and be live — a completion of a
        // module nobody published is not a completion. (Every DB touch sits
        // BELOW every shape refusal, so the refusal paths stay storage-free.)
        $module = DB::table('education_modules as m')
            ->join('education_tracks as t', 't.id', '=', 'm.track_id')
            ->where('m.key', $moduleKey)->whereNull('m.deleted_at')->where('m.status', 'live')
            ->where('t.key', $trackKey)->whereNull('t.deleted_at')->where('t.status', 'live')
            ->first(['m.id']);

        if ($module === null) {
            throw new ConstitutionalViolation(
                'This training does not exist — completions name a published module of a live track.',
                'CGA Forms Catalog (F-EDU-001)'
            );
        }

        // The node-local resume latch (one-way, the markStep shape): a
        // completion never un-completes; a retake refreshes the score only.
        DB::table('education_progress')->upsert(
            [[
                'user_id'      => (string) $actor->getKey(),
                'module_id'    => (string) $module->id,
                'state'        => 'completed',
                'score_pct'    => $score,
                'completed_at' => now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]],
            ['user_id', 'module_id'],
            ['state', 'score_pct', 'updated_at'],
        );

        // Decorations, in the ruled order: the achievement mints through the
        // ONE ledger writer (idempotent on user + award key), and the
        // one-time stipend pays IFF the row was NEWLY minted — the ledger IS
        // the once-only proof (§5.0.1), no second bookkeeping. Neither leg
        // touches the audit payload: the public chain records the
        // completion, never the money (reader privacy).
        if ($this->achievements->awardSelf($actor, self::AWARD_KEY)) {
            $this->stipend->payOnce($actor);
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
