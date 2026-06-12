<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Models\User;

/**
 * F-LEG-031 — Amendable Setting Change via Bill (R-09).
 *
 * PHASE A: validation/rejection path ONLY. The ConstitutionalValidator has
 * already bounds-checked the proposed value before this handler runs
 * (out-of-range values throw ConstitutionalViolation with citation and
 * are recorded as rejected=true chain entries — the operator-visible
 * demo). An in-range filing is RECORDED but NOT APPLIED:
 * constitutional_settings only mutates through the full legislative path
 * (bill lifecycle → peg-quorum vote → enactment → setting_changes row),
 * which lands in Phase C.
 *
 * Role gate: catalog says R-09 (Legislative Representative). Until seats
 * exist (Phase B elections) no user can hold R-09, and the Phase A
 * StubRoleResolver only derives R-01 — so the Phase A gate is R-01 to
 * keep the rejection demo exercisable. Tighten to ['R-09'] in Phase C
 * when the bill machinery takes over this form.
 */
class AmendableSettingChange implements FormHandler
{
    public function module(): string
    {
        return 'settings';
    }

    public function event(): string
    {
        return 'setting.change_filed';
    }

    public function requiredRoles(): array
    {
        return ['R-01']; // Phase A — becomes ['R-09'] with Phase C bill lifecycle
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        return [
            'setting_key'     => $payload['setting_key'],
            'proposed_value'  => $payload['value'],
            'jurisdiction_id' => $payload['jurisdiction_id'] ?? null,
            'applied'         => false,
            'note'            => 'Recorded only — the legislative enactment path (bill -> vote -> setting_changes) lands in Phase C.',
        ];
    }
}
