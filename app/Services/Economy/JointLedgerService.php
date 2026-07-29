<?php

namespace App\Services\Economy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase M — joint ledgers (Art. V §2 shared resources + Art. I freedom to
 * contract). The SOLE writer of the joint plane: joint_ledgers,
 * joint_ledger_parties, joint_ledger_movements.
 *
 * THE DESIGN, WHICH IS THE WHOLE POINT: a joint ledger holds no money of
 * its own. Its balance lives in an ESCROW economic account owned by the
 * ledger row (AccountService::open('joint_ledgers', …)), so:
 *
 *   - FUNDING is the existing F-IND-023 transfer to the escrow account —
 *     no new money path exists;
 *   - SETTLEMENT is AccountService::transfer escrow → recipient — a
 *     balanced posting on the ONE ledger, refused on insufficient balance
 *     like any other (no overdraft, no special case);
 *   - this service NEVER touches ledger_entries, market_transactions or
 *     account balances directly. The joint tables carry GOVERNANCE only:
 *     who must agree, and whether they have.
 *
 * The joint_ledgers.balance column is a CACHED MIRROR of the escrow
 * account, maintained here in the same transaction as every movement —
 * the escrow account is the truth, the mirror is for the read surface.
 *
 * APPROVAL RULES: 'all' = every party; 'majority' = floor(N/2)+1. A
 * movement settles the moment its rule is met — the approving act that
 * crosses the threshold is the one that moves the money.
 */
class JointLedgerService
{
    public function __construct(
        private AccountService $accounts,
    ) {}

    /**
     * Open a joint ledger: name the co-owners and the rule up front.
     *
     * @param  array<int, string>  $partyAccountIds  the co-owners' ACCOUNTS
     *         (accounts-never-people holds on this plane too)
     * @return array{ledger_id: string, escrow_account_id: string}
     */
    public function open(
        string $name,
        ?string $purpose,
        string $currencyId,
        array $partyAccountIds,
        string $approvalRule = 'all',
        bool $public = false,
    ): array {
        $parties = array_values(array_unique(array_filter($partyAccountIds)));

        if (count($parties) < 2) {
            throw new InvalidArgumentException('A joint ledger is CO-owned — it needs at least two distinct party accounts.');
        }

        if (! in_array($approvalRule, ['all', 'majority'], true)) {
            throw new InvalidArgumentException("Unknown approval rule [{$approvalRule}] — all or majority.");
        }

        $known = DB::table('economic_accounts')
            ->whereIn('id', $parties)
            ->where('currency_id', $currencyId)
            ->whereNull('deleted_at')
            ->count();

        if ($known !== count($parties)) {
            throw new InvalidArgumentException('Every party must be an open account in this currency.');
        }

        return DB::transaction(function () use ($name, $purpose, $currencyId, $parties, $approvalRule, $public) {
            $ledgerId = (string) Str::uuid();

            DB::table('joint_ledgers')->insert([
                'id'            => $ledgerId,
                'name'          => $name,
                'purpose'       => $purpose,
                'currency_id'   => $currencyId,
                'balance'       => 0,
                'approval_rule' => $approvalRule,
                'public'        => $public,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            foreach ($parties as $accountId) {
                DB::table('joint_ledger_parties')->insert([
                    'id'              => (string) Str::uuid(),
                    'joint_ledger_id' => $ledgerId,
                    'account_id'      => $accountId,
                    'role'            => 'signer',
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }

            // The escrow — an ordinary economic account owned by the ledger.
            $escrow = $this->accounts->open('joint_ledgers', $ledgerId, $currencyId, 'joint_ledger');

            return ['ledger_id' => $ledgerId, 'escrow_account_id' => (string) $escrow->id];
        });
    }

    /**
     * Propose a movement out of the ledger. Proposing counts as the
     * proposer's approval — and if the rule is already met (a majority of
     * two is two... but of three is two), it settles immediately.
     *
     * @return array{movement_id: string, status: string}
     */
    public function propose(
        string $ledgerId,
        string $proposerAccountId,
        string $toAccountId,
        string $amount,
        ?string $memo = null,
    ): array {
        $ledger = $this->ledgerRow($ledgerId);

        $this->assertParty($ledgerId, $proposerAccountId);

        if (! preg_match('/^\d+(\.\d+)?$/', $amount) || bccomp($amount, '0', 6) !== 1) {
            throw new InvalidArgumentException('A movement amount must be greater than zero.');
        }

        $escrowId = $this->escrowAccountId($ledgerId);

        if ($toAccountId === $escrowId) {
            throw new InvalidArgumentException('A movement leaves the ledger — funding it is a plain transfer TO its account.');
        }

        // The recipient must be a real open account — a movement to nowhere
        // would settle into a black hole.
        $recipientOk = DB::table('economic_accounts')
            ->where('id', $toAccountId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $recipientOk) {
            throw new InvalidArgumentException('Unknown recipient account.');
        }

        return DB::transaction(function () use ($ledger, $ledgerId, $proposerAccountId, $toAccountId, $amount, $memo) {
            $movementId = (string) Str::uuid();

            DB::table('joint_ledger_movements')->insert([
                'id'              => $movementId,
                'joint_ledger_id' => $ledgerId,
                'to_account_id'   => $toAccountId,
                'amount'          => $amount,
                'memo'            => $memo,
                'approvals'       => json_encode([$proposerAccountId]),
                'status'          => 'pending',
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            $status = 'pending';

            if ($this->ruleMet($ledger->approval_rule, 1, $this->partyCount($ledgerId))) {
                $this->settle($movementId);
                $status = 'settled';
            }

            return ['movement_id' => $movementId, 'status' => $status];
        });
    }

    /**
     * Approve a pending movement. The approval that meets the rule settles
     * it — money moves in the same transaction as the consent that
     * completed the agreement.
     *
     * @return array{movement_id: string, status: string, approvals: int, needed: int}
     */
    public function approve(string $movementId, string $approverAccountId): array
    {
        return DB::transaction(function () use ($movementId, $approverAccountId) {
            $movement = DB::table('joint_ledger_movements')->where('id', $movementId)->lockForUpdate()->first();

            if ($movement === null || $movement->status !== 'pending') {
                throw new RuntimeException('That movement is not awaiting agreement.');
            }

            $ledger = $this->ledgerRow((string) $movement->joint_ledger_id);

            $this->assertParty($ledger->id, $approverAccountId);

            $approvals = json_decode((string) $movement->approvals, true) ?: [];

            if (in_array($approverAccountId, $approvals, true)) {
                throw new RuntimeException('You have already approved this movement — one signature is on the record.');
            }

            $approvals[] = $approverAccountId;

            DB::table('joint_ledger_movements')->where('id', $movementId)->update([
                'approvals'  => json_encode($approvals),
                'updated_at' => now(),
            ]);

            $needed = $this->neededCount($ledger->approval_rule, $this->partyCount($ledger->id));
            $status = 'pending';

            if (count($approvals) >= $needed) {
                $this->settle($movementId);
                $status = 'settled';
            }

            return [
                'movement_id' => $movementId,
                'status'      => $status,
                'approvals'   => count($approvals),
                'needed'      => $needed,
            ];
        });
    }

    /** The escrow account backing a ledger — funding sends HERE. */
    public function escrowAccountId(string $ledgerId): string
    {
        $currencyId = (string) DB::table('joint_ledgers')->where('id', $ledgerId)->value('currency_id');

        $id = $this->accounts->accountIdFor('joint_ledgers', $ledgerId, $currencyId);

        if ($id === null) {
            throw new RuntimeException('This joint ledger has no escrow account — it was not opened through the service.');
        }

        return $id;
    }

    /** Refresh the cached mirror from the escrow truth. */
    public function refreshBalance(string $ledgerId): void
    {
        DB::table('joint_ledgers')->where('id', $ledgerId)->update([
            'balance'    => DB::raw(
                '(SELECT balance FROM economic_accounts WHERE id = ' . DB::getPdo()->quote($this->escrowAccountId($ledgerId)) . ')'
            ),
            'updated_at' => now(),
        ]);
    }

    // ── internals ────────────────────────────────────────────────────────

    /**
     * Settlement: ONE AccountService transfer, escrow → recipient. The
     * refusals are the account plane's own — insufficient escrow balance is
     * the same no-overdraft answer a wallet gives.
     */
    private function settle(string $movementId): void
    {
        $movement = DB::table('joint_ledger_movements')->where('id', $movementId)->first();
        $ledger   = $this->ledgerRow((string) $movement->joint_ledger_id);
        $escrowId = $this->escrowAccountId($ledger->id);

        $entryGroup = $this->accounts->transfer(
            $escrowId,
            (string) $movement->to_account_id,
            (string) $ledger->currency_id,
            (string) $movement->amount,
            'transfer',
            $movement->memo === null ? null : (string) $movement->memo,
        );

        DB::table('joint_ledger_movements')->where('id', $movementId)->update([
            'status'      => 'settled',
            'entry_group' => $entryGroup,
            'updated_at'  => now(),
        ]);

        $this->refreshBalance($ledger->id);
    }

    private function ledgerRow(string $ledgerId): object
    {
        $ledger = DB::table('joint_ledgers')->where('id', $ledgerId)->whereNull('deleted_at')->first();

        if ($ledger === null) {
            throw new RuntimeException('Unknown joint ledger.');
        }

        return $ledger;
    }

    private function assertParty(string $ledgerId, string $accountId): void
    {
        $isParty = DB::table('joint_ledger_parties')
            ->where('joint_ledger_id', $ledgerId)
            ->where('account_id', $accountId)
            ->exists();

        if (! $isParty) {
            throw new RuntimeException('Only a co-owner acts on a joint ledger — your account is not a party to this one.');
        }
    }

    private function partyCount(string $ledgerId): int
    {
        return DB::table('joint_ledger_parties')->where('joint_ledger_id', $ledgerId)->count();
    }

    private function neededCount(string $rule, int $parties): int
    {
        return $rule === 'all' ? $parties : intdiv($parties, 2) + 1;
    }

    private function ruleMet(string $rule, int $approvals, int $parties): bool
    {
        return $approvals >= $this->neededCount($rule, $parties);
    }
}
