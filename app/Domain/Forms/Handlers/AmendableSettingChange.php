<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesLegislativeActor;
use App\Models\Bill;
use App\Models\Legislature;
use App\Models\User;
use App\Services\BillService;

/**
 * F-LEG-031 — Amendable Setting Change via Bill (R-09).
 *
 * PHASE C UPGRADE (PHASE_C_DESIGN_votes_laws §C — supersedes the Phase A
 * record-only handler): an in-range filing now INTRODUCES a pre-targeted
 * setting bill (act_type 'setting_change') through BillService. The
 * setting itself mutates only at ENACTMENT (bill → peg-quorum floor vote
 * → EnactmentService: setting_changes ledger row + constitutional_settings
 * update + last_amended_by_act_id + SettingsResolver bust + dependent
 * clock timers re-derived) — exactly the legislative path the Phase A
 * docblock promised.
 *
 * The Phase A behavior is preserved verbatim where it matters: the
 * PROTECTED ConstitutionalValidator::checkSettingChange still runs
 * pre-handler (engine validate step), so out-of-range values reject
 * pre-commit with citation + a rejected=true chain entry. Role gate
 * tightened R-01 → R-09 as promised (seats exist since Phase B).
 */
class AmendableSettingChange implements FormHandler
{
    use ResolvesLegislativeActor;

    public function __construct(private readonly BillService $bills)
    {
    }

    public function module(): string
    {
        return 'settings';
    }

    public function event(): string
    {
        return 'setting.bill_introduced';
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
        $jurisdictionId = (string) ($payload['jurisdiction_id'] ?? '');

        $legislature = Legislature::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('status', Legislature::STATUS_ACTIVE)
            ->first();

        if ($legislature === null) {
            throw new ConstitutionalViolation(
                'No active legislature governs this jurisdiction — settings amend only through its legislative process.',
                'Art. VII'
            );
        }

        $sponsor = $this->currentMemberOf($actor, (string) $legislature->id);

        $key   = (string) $payload['setting_key'];
        $value = $payload['value'];

        $bill = $this->bills->introduce($legislature, $sponsor, [
            'title'               => (string) ($payload['title'] ?? "Amend {$key}"),
            'law_text'            => sprintf(
                "The amendable constitutional setting [%s] for this jurisdiction is set to %s, within the hardened bounds of the Fair Constitution.",
                $key,
                json_encode($value)
            ),
            'act_type'            => Bill::TYPE_SETTING_CHANGE,
            'targets_setting_key' => $key,
            'proposed_value'      => $value,
        ]);

        return [
            'bill_id'         => (string) $bill->id,
            'setting_key'     => $key,
            'proposed_value'  => $value,
            'jurisdiction_id' => $jurisdictionId,
            'applied'         => false,
            'note'            => 'Setting bill introduced — the value applies at enactment (floor vote under peg quorum), never at filing.',
        ];
    }
}
