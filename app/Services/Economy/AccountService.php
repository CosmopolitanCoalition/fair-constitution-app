<?php

namespace App\Services\Economy;

use App\Models\Economy\EconomicAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * PROTECTED — Phase M slice M-1. Wallets, and the one restricted link.
 *
 * THE PRIVACY MODEL, which is the whole point of this class (operator ruling
 * 2026-07-25: economic records SYNC between nodes; privacy is READER privacy,
 * like a ballot):
 *
 *   - An economic_account is PSEUDONYMOUS. Every ledger entry, transaction,
 *     receipt, listing, order and asset references an ACCOUNT id and never a
 *     user id. That whole graph replicates in plaintext, so any node can
 *     verify the ledger sums and validate a spend.
 *   - economic_account_bindings is the ONLY table that says who owns an
 *     account, and it is the only restricted object in the economy.
 *
 * Read the world's ledger from any node and you learn what moved between
 * which accounts, and nothing about who.
 *
 * Stated with the honesty BallotCrypto uses about its own limits: the
 * AUTHORITATIVE instance for a person's residency can resolve their binding,
 * because it has to — it runs their stipend and answers judicial process. So
 * the guarantee is "no OTHER node learns who owns an account", never "nobody
 * can". UI copy must say exactly that and no more.
 *
 * Note this is why encryption alone could never have satisfied the ruling: a
 * peer holds its own app key, so an encrypted balance is either unreadable to
 * the node that must validate the spend, or readable to that node's operator.
 * Pseudonymity is what makes the sync safe.
 */
class AccountService
{
    public function __construct(private LedgerService $ledger) {}

    /**
     * Open (or return) the account for an owner in a currency.
     *
     * Idempotent: a resident has one wallet per currency, and asking twice
     * must not mint a second one.
     *
     * `joint_ledgers` joined the owner kinds in Wave 2 (announce-extend-pin,
     * migration 2026_07_29_000020): a joint ledger's money lives in an
     * ordinary ESCROW economic account owned by the ledger row, so joint
     * movements ride the same rails as every other movement. The binding
     * stays the one restricted object; a ledger-owned account identifies no
     * person.
     */
    public function open(string $ownerType, string $ownerId, string $currencyId, string $kind = 'user'): EconomicAccount
    {
        if (! in_array($ownerType, ['users', 'organizations', 'joint_ledgers'], true)) {
            throw new InvalidArgumentException("Unknown economic account owner type [{$ownerType}].");
        }

        return DB::transaction(function () use ($ownerType, $ownerId, $currencyId, $kind) {
            $existing = $this->accountIdFor($ownerType, $ownerId, $currencyId);

            if ($existing !== null) {
                return EconomicAccount::query()->findOrFail($existing);
            }

            $account = EconomicAccount::create([
                'kind'        => $kind,
                'currency_id' => $currencyId,
                'balance'     => 0,
                'status'      => EconomicAccount::STATUS_OPEN,
            ]);

            DB::table('economic_account_bindings')->insert([
                'id'         => (string) Str::uuid(),
                'account_id' => $account->id,
                'owner_type' => $ownerType,
                'owner_id'   => $ownerId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $account;
        });
    }

    /**
     * Resolve an owner's account id. This is the restricted lookup — every
     * other read path in the economy works from account ids alone.
     */
    public function accountIdFor(string $ownerType, string $ownerId, string $currencyId): ?string
    {
        return DB::table('economic_account_bindings as b')
            ->join('economic_accounts as a', 'a.id', '=', 'b.account_id')
            ->where('b.owner_type', $ownerType)
            ->where('b.owner_id', $ownerId)
            ->where('a.currency_id', $currencyId)
            ->whereNull('a.deleted_at')
            ->value('b.account_id');
    }

    /**
     * Move value between two accounts. One posting, both legs, one
     * market_transactions row for the wallet view.
     *
     * @param  string  $kind  transfer|order|stipend|levy|dues|grant|donation
     */
    public function transfer(
        string $fromAccountId,
        string $toAccountId,
        string $currencyId,
        string $amount,
        string $kind = 'transfer',
        ?string $memo = null,
    ): string {
        if ($fromAccountId === $toAccountId) {
            throw new InvalidArgumentException('An account cannot pay itself.');
        }

        $this->assertSufficient($fromAccountId, $amount);

        return DB::transaction(function () use ($fromAccountId, $toAccountId, $currencyId, $amount, $kind, $memo) {
            $entryGroup = $this->ledger->post($kind, [
                [
                    'account_type' => 'economic_accounts',
                    'account_id'   => $fromAccountId,
                    'currency_id'  => $currencyId,
                    'direction'    => LedgerService::DIRECTION_DEBIT,
                    'amount'       => $amount,
                ],
                [
                    'account_type' => 'economic_accounts',
                    'account_id'   => $toAccountId,
                    'currency_id'  => $currencyId,
                    'direction'    => LedgerService::DIRECTION_CREDIT,
                    'amount'       => $amount,
                ],
            ]);

            $this->applyBalance($fromAccountId, '-' . $amount);
            $this->applyBalance($toAccountId, $amount);

            DB::table('market_transactions')->insert([
                'id'              => (string) Str::uuid(),
                'from_account_id' => $fromAccountId,
                'to_account_id'   => $toAccountId,
                'currency_id'     => $currencyId,
                'amount'          => $amount,
                'kind'            => $kind,
                'memo'            => $memo,
                'entry_group'     => $entryGroup,
                'created_at'      => now(),
            ]);

            return $entryGroup;
        });
    }

    /**
     * Credit an account from outside the individual economy (a stipend run, a
     * grant disbursement). The counter-leg is a treasury account, so value is
     * still conserved — this is not minting.
     */
    public function creditFromTreasury(
        string $treasuryAccountId,
        string $toAccountId,
        string $currencyId,
        string $amount,
        string $kind,
    ): string {
        return DB::transaction(function () use ($treasuryAccountId, $toAccountId, $currencyId, $amount, $kind) {
            $entryGroup = $this->ledger->post($kind, [
                [
                    'account_type' => 'treasury_accounts',
                    'account_id'   => $treasuryAccountId,
                    'currency_id'  => $currencyId,
                    'direction'    => LedgerService::DIRECTION_DEBIT,
                    'amount'       => $amount,
                ],
                [
                    'account_type' => 'economic_accounts',
                    'account_id'   => $toAccountId,
                    'currency_id'  => $currencyId,
                    'direction'    => LedgerService::DIRECTION_CREDIT,
                    'amount'       => $amount,
                ],
            ]);

            $this->applyBalance($toAccountId, $amount);

            return $entryGroup;
        });
    }

    public function balance(string $accountId): string
    {
        return (string) (DB::table('economic_accounts')->where('id', $accountId)->value('balance') ?? '0');
    }

    /**
     * No overdrafts. There is no credit facility in the individual economy —
     * borrowing is a jurisdiction-level instrument (Art. V §4), not a wallet
     * feature, so an account simply cannot go below zero.
     */
    private function assertSufficient(string $accountId, string $amount): void
    {
        if (bccomp($this->balance($accountId), $amount, 6) === -1) {
            throw new InvalidArgumentException('Insufficient balance — the individual economy has no overdraft.');
        }
    }

    private function applyBalance(string $accountId, string $delta): void
    {
        if (! preg_match('/^-?\d+(\.\d+)?$/', $delta)) {
            throw new InvalidArgumentException("Illegal numeric [{$delta}].");
        }

        DB::table('economic_accounts')
            ->where('id', $accountId)
            ->update([
                'balance'    => DB::raw('balance + ' . $delta),
                'updated_at' => now(),
            ]);
    }
}
