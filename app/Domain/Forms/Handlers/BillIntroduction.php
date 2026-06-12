<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesLegislativeActor;
use App\Models\Legislature;
use App\Models\User;
use App\Services\BillService;

/**
 * F-LEG-003 — Bill Introduction (R-09). ESM-07 entry point.
 *
 * Validations live in BillService::introduce (§C.1): sponsor seat,
 * act_type, scale ⊆ legislature subtree (Art. V §4, fixed at
 * introduction), scope judiciary chain, setting bills bounds-checked
 * PRE-VOTE via the PROTECTED checkSettingChange. Creates bills + version
 * 1 + the public record kind 'bill'.
 */
class BillIntroduction implements FormHandler
{
    use ResolvesLegislativeActor;

    public function __construct(private readonly BillService $bills)
    {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'bill.introduced';
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
        $legislature = Legislature::query()->find($payload['legislature_id'] ?? null);

        if ($legislature === null) {
            throw new ConstitutionalViolation('Unknown legislature.', 'Art. II §2 · as implemented');
        }

        $sponsor = $this->currentMemberOf($actor, (string) $legislature->id);

        $bill = $this->bills->introduce($legislature, $sponsor, $payload);

        return [
            'bill_id'             => (string) $bill->id,
            'title'               => $bill->title,
            'act_type'            => $bill->act_type,
            'scale'               => $bill->scale,
            'targets_setting_key' => $bill->targets_setting_key,
            'sponsor_member_id'   => (string) $sponsor->id,
            'jurisdiction_id'     => (string) $legislature->jurisdiction_id,
            'status'              => $bill->status,
        ];
    }
}
