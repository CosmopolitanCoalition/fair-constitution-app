<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesChairActor;
use App\Models\Bill;
use App\Models\Committee;
use App\Models\User;
use App\Services\BillService;

/**
 * F-CHR-003 — Bill Referral to Floor (chamber ops §C.5 [IFACE]).
 *
 * The gate: enabled only after the committee vote passes — the bill must
 * stand REPORTED (BillService::committeeReported flips it on the
 * committee_bill adoption). The chair (or acting alternate) of the
 * bill's OWN committee files; the referral moves the bill on_floor and
 * opens its floor vote (per-kind peg thresholds at the floor stage —
 * q-ledger #q7).
 */
class BillReferralToFloor implements FormHandler
{
    use ResolvesChairActor;

    public function __construct(
        private readonly BillService $bills,
    ) {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'bill.referred_to_floor';
    }

    public function requiredRoles(): array
    {
        return ['R-12', 'R-13'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $bill = Bill::query()->find($payload['bill_id'] ?? null);

        if ($bill === null) {
            throw new ConstitutionalViolation('F-CHR-003 requires a valid bill_id.', 'CGA Forms Catalog');
        }

        if ($bill->status !== Bill::STATUS_REPORTED) {
            throw new ConstitutionalViolation(
                "Referral to floor follows a passed committee vote — the bill stands [{$bill->status}], not reported.",
                'Art. II §2 · as implemented'
            );
        }

        $committee = Committee::query()->find($bill->committee_id);

        if ($committee === null) {
            throw new ConstitutionalViolation(
                'The bill has no committee — direct-to-floor bills move by motion, not F-CHR-003.',
                'CGA Forms Catalog (F-CHR-003)'
            );
        }

        $chair = $this->chairActor($actor, $committee, ['committee_id' => $committee->id] + $payload, 'F-CHR-003');

        $vote = $this->bills->moveToFloor($bill, null, $chair);

        return [
            'bill_id'       => (string) $bill->id,
            'committee_id'  => (string) $committee->id,
            'referred_by'   => (string) $chair->id,
            'bill_status'   => $bill->refresh()->status,
            'floor_vote_id' => $vote !== null ? (string) $vote->id : null,
        ];
    }
}
