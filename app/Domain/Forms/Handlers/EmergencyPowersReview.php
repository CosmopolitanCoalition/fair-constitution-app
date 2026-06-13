<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\JudicialActor;
use App\Models\CourtCase;
use App\Models\EmergencyPower;
use App\Models\User;
use App\Services\EmergencyPowerService;
use App\Services\Judiciary\CaseService;

/**
 * F-JDG-007 — Emergency Powers Review (Art. II §7 "Emergency Powers are subject
 * to Judicial review", WF-JUD-05). A seated judge (R-19/R-20) opens a review on
 * a live power (sua sponte or triggered by an F-IND-016 challenge of the power)
 * and decides it:
 *
 *  - upheld   → the power returns to active/renewed.
 *  - narrowed → area/methods limited (Art. II §7 area/methods limits).
 *  - struck   → the power ends immediately; emergency-enabled rules/orders
 *    citing it expire (the CLK-03 cascade).
 *
 * The civic-process-disruption basis is the judicial complement to the engine's
 * EMERGENCY_PROTECTED_FORMS floor. CLK-03 keeps running throughout — review
 * never extends a power past its ceiling.
 */
class EmergencyPowersReview implements FormHandler
{
    public function __construct(
        private readonly EmergencyPowerService $powers,
        private readonly CaseService $cases,
    ) {}

    public function module(): string
    {
        return 'judiciary';
    }

    public function event(): string
    {
        return 'emergency.reviewed';
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
        // F-JDG-007 is an EMERGENCY_PROTECTED_FORM (a court cannot be touched BY
        // an emergency power — Art. II §7). The shield forbids the
        // `emergency_power_id` / `enabling_*` keys on protected forms, so the
        // power UNDER REVIEW is named by `reviewed_power_id` (the review acts ON
        // the power, it is never ENABLED by it).
        $power = EmergencyPower::query()->find((string) ($payload['reviewed_power_id'] ?? ''));

        if ($power === null) {
            throw new ConstitutionalViolation('F-JDG-007 names the power it reviews (reviewed_power_id).', 'Art. II §7');
        }

        $judiciaryId = (string) ($payload['judiciary_id'] ?? '');

        if ($judiciaryId === '') {
            throw new ConstitutionalViolation('F-JDG-007 names the reviewing court (judiciary_id).', 'Art. II §7');
        }

        $seat = JudicialActor::seat($actor, $judiciaryId, 'F-JDG-007');

        // Open the review case (the power's judicial_review_case_id).
        $case = $this->cases->open([
            'judiciary_id' => $judiciaryId,
            'jurisdiction_id' => (string) $power->jurisdiction_id,
            'kind' => CourtCase::KIND_CONSTITUTIONAL,
            'title' => sprintf('Emergency powers review — %s', $power->label),
            'statement_of_claim' => sprintf('Art. II §7 review of emergency power %s.', $power->id),
            'filed_via_form' => 'F-IND-016',
            'filed_by_user_id' => $actor !== null ? (string) $actor->getKey() : null,
        ]);

        $this->powers->openReview($power, (string) $case->id);

        $review = $this->powers->decideReview($power->refresh(), [
            'review_basis' => (string) ($payload['review_basis'] ?? 'methods'),
            'outcome' => (string) ($payload['outcome'] ?? ''),
            'narrowed_area_jurisdiction_id' => isset($payload['narrowed_area_jurisdiction_id']) ? (string) $payload['narrowed_area_jurisdiction_id'] : null,
            'narrowed_methods' => $payload['narrowed_methods'] ?? null,
            'opinion_text' => (string) ($payload['opinion_text'] ?? ''),
            'judiciary_id' => $judiciaryId,
            'case_id' => (string) $case->id,
            'challenge_id' => isset($payload['challenge_id']) ? (string) $payload['challenge_id'] : null,
        ]);

        return [
            'emergency_power_id' => (string) $power->id,
            'review_id' => (string) $review->id,
            'outcome' => (string) $review->outcome,
            'review_case_id' => (string) $case->id,
            'power_status' => (string) $power->refresh()->status,
            'reviewed_by_seat' => (string) $seat->id,
            'jurisdiction_id' => (string) $power->jurisdiction_id,
        ];
    }
}
