<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\JudicialActor;
use App\Models\ConstitutionalChallenge;
use App\Models\CourtCase;
use App\Models\JudicialSeat;
use App\Models\User;
use App\Services\Judiciary\ConstitutionalChallengeService;
use Illuminate\Support\Facades\DB;

/**
 * F-JDG-004 — Constitutional Finding (Art. IV §5.2 first half, WF-JUD-05).
 *
 * Filed by a SEATED judge (R-19 appointed / R-20 elected) of the challenge's
 * judiciary. The standard applied is Art. II §8 ("All other Judgements can be
 * overturned only by proven contradictions in law and errors found in the
 * cases") — the engine records the determination, never adjudicates it.
 *
 *  - finds_contradiction=false ⇒ the challenge is dismissed (terminal; no
 *    remedy, no clocks).
 *  - finds_contradiction=true ⇒ finding_issued; the challenge then REQUIRES an
 *    F-JDG-005 to set the windows.
 *
 * Double-jeopardy guard (Art. II §8, hardened): the finding path operates on
 * LEGISLATION (a `laws` row), never on a closed criminal verdict.
 */
class ConstitutionalFinding implements FormHandler
{
    public function __construct(
        private readonly ConstitutionalChallengeService $challenges,
    ) {}

    public function module(): string
    {
        return 'judiciary';
    }

    public function event(): string
    {
        return 'challenge.finding';
    }

    public function requiredRoles(): array
    {
        return ['R-19', 'R-20'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $challenge = $this->resolveChallenge($payload);

        // The acting judge must be SEATED on THIS court (R-19/R-20 proves a
        // seat in SOME court; the filing must come from this one).
        $seat = JudicialActor::seat($actor, (string) $challenge->judiciary_id, 'F-JDG-004');

        // The hearing must have reached deliberation/decision (the case is
        // ripe). A constitutional challenge case opened by F-IND-016 is heard
        // through the cases lifecycle; the finding issues at deliberation+.
        $this->assertDecidable($challenge);

        // Double jeopardy (Art. II §8): the finding operates on legislation —
        // the offending subject is a `laws` row, never a closed criminal
        // verdict. (case verdict overturns ride the appeal machinery.)
        $offendingLawId = (string) ($payload['offending_law_id'] ?? $challenge->challenged_law_id);

        if (! DB::table('laws')->where('id', $offendingLawId)->whereNull('deleted_at')->exists()) {
            throw new ConstitutionalViolation(
                'A constitutional finding names a law in error (Art. IV §5.2) — it never re-opens a closed '
                .'criminal judgement (Art. II §8).',
                'Art. II §8'
            );
        }

        $finding = $this->challenges->recordFinding($challenge, [
            'finds_contradiction' => (bool) ($payload['finds_contradiction'] ?? false),
            'contradiction_against' => (string) ($payload['contradiction_against'] ?? $challenge->claimed_basis),
            'superior_authority_law_id' => isset($payload['superior_authority_law_id']) ? (string) $payload['superior_authority_law_id'] : null,
            'constitutional_citation' => isset($payload['constitutional_citation']) ? (string) $payload['constitutional_citation'] : null,
            'offending_law_id' => $offendingLawId,
            'offending_version_no' => isset($payload['offending_version_no']) ? (int) $payload['offending_version_no'] : null,
            'opinion_text' => (string) ($payload['opinion_text'] ?? ''),
            'full_court' => (bool) ($payload['full_court'] ?? false),
            'panel_snapshot' => $this->panelSnapshot($challenge),
        ]);

        return [
            'challenge_id' => (string) $challenge->id,
            'finding_id' => (string) $finding->id,
            'finds_contradiction' => (bool) $finding->finds_contradiction,
            'status' => (string) $challenge->refresh()->status,
            'filed_by_seat' => (string) $seat->id,
            'jurisdiction_id' => (string) $challenge->jurisdiction_id,
        ];
    }

    private function resolveChallenge(array $payload): ConstitutionalChallenge
    {
        $id = $payload['challenge_id'] ?? null;

        $challenge = is_string($id) ? ConstitutionalChallenge::query()->find($id) : null;

        if ($challenge === null && isset($payload['case_id']) && is_string($payload['case_id'])) {
            $challenge = ConstitutionalChallenge::query()->where('case_id', $payload['case_id'])->first();
        }

        if ($challenge === null) {
            throw new ConstitutionalViolation('F-JDG-004 names the challenge it finds on (challenge_id).', 'Art. IV §5');
        }

        return $challenge;
    }

    private function assertDecidable(ConstitutionalChallenge $challenge): void
    {
        if ($challenge->status !== ConstitutionalChallenge::STATUS_UNDER_REVIEW) {
            throw new ConstitutionalViolation(
                "A finding issues on a challenge under review (status: {$challenge->status}).",
                'Art. IV §5'
            );
        }

        if ($challenge->case_id === null) {
            return; // a parked-then-heard challenge may finalize without a formal case in edge flows
        }

        $caseStatus = CourtCase::query()->whereKey((string) $challenge->case_id)->value('status');

        if (! in_array($caseStatus, [CourtCase::STATUS_HEARD, CourtCase::STATUS_DELIBERATION, CourtCase::STATUS_DECIDED], true)) {
            throw new ConstitutionalViolation(
                'A constitutional finding issues after the case is heard (the court must hear the challenge '
                .'before resolving the question of law) — Art. IV §5.',
                'Art. IV §5'
            );
        }
    }

    /** The seated bench of the challenge's court (audit completeness). */
    private function panelSnapshot(ConstitutionalChallenge $challenge): array
    {
        return JudicialSeat::query()
            ->where('judiciary_id', (string) $challenge->judiciary_id)
            ->where('status', JudicialSeat::STATUS_SEATED)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }
}
