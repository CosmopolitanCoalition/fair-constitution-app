<?php

namespace Tests\Constitutional;

use App\Models\Economy\Currency;
use App\Services\Economy\AccountService;
use App\Services\Economy\JointLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Joint ledgers — Art. V §2 (shared resources) + Art. I (freedom to
 * contract). The properties that make a co-owned account constitutional:
 *
 *   1. NO MOVEMENT WITHOUT AGREEMENT — money leaves only when the approval
 *      rule is met, and the approval that meets it is the act that moves
 *      the money.
 *   2. ONLY A CO-OWNER ACTS — a non-party can neither propose nor approve.
 *   3. ONE SIGNATURE PER SIGNER — approving twice is refused.
 *   4. ONE SET OF RAILS — the balance lives in an ordinary escrow account;
 *      settlement is an ordinary balanced transfer; an underfunded escrow
 *      refuses exactly like a wallet (no overdraft, no special joint money).
 *      LedgerIntegrityTest's fleet-wide write scan already proves this
 *      service never touches ledger_entries itself.
 */
class JointLedgerTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'joint_ledger_pg';

    private JointLedgerService $joint;
    private AccountService $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->joint = app(JointLedgerService::class);
        $this->accounts = app(AccountService::class);
    }

    // ── shape refusals — no live stack needed ────────────────────────────

    public function test_a_joint_ledger_needs_two_distinct_parties(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least two distinct/');

        $one = (string) Str::uuid();
        $this->joint->open('Solo', null, (string) Str::uuid(), [$one, $one]);
    }

    public function test_an_unknown_approval_rule_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/all or majority/');

        $this->joint->open('Odd rule', null, (string) Str::uuid(), [(string) Str::uuid(), (string) Str::uuid()], 'plurality');
    }

    // ── live pins ────────────────────────────────────────────────────────

    public function test_the_approval_that_meets_the_rule_is_what_moves_the_money(): void
    {
        $this->onLivePg(function () {
            [$currencyId, $a, $b] = $this->fixture();

            $opened = $this->joint->open('Test fund', 'pinned', $currencyId, [$a, $b], 'all');
            $escrow = $opened['escrow_account_id'];

            // Fund the escrow the ordinary way — a plain balanced transfer.
            $this->accounts->transfer($a, $escrow, $currencyId, '40', 'transfer', 'fund');

            $proposed = $this->joint->propose($opened['ledger_id'], $a, $b, '15', 'shared expense');

            // One signature of two under 'all' — the money must NOT move.
            $this->assertSame('pending', $proposed['status']);
            $this->assertSame(0, bccomp($this->accounts->balance($escrow), '40', 6), 'escrow untouched while pending');

            $bBefore = $this->accounts->balance($b);

            $approved = $this->joint->approve($proposed['movement_id'], $b);

            // The second signature completes the rule — settlement is the
            // SAME act.
            $this->assertSame('settled', $approved['status']);
            $this->assertSame(0, bccomp($this->accounts->balance($escrow), '25', 6), 'escrow paid out');
            $this->assertSame(0, bccomp($this->accounts->balance($b), bcadd($bBefore, '15', 6), 6), 'recipient received');

            $movement = DB::table('joint_ledger_movements')->where('id', $proposed['movement_id'])->first();
            $this->assertSame('settled', $movement->status);
            $this->assertNotNull($movement->entry_group, 'settlement is a real posting with an entry group');

            // The mirror matches the escrow truth.
            $this->assertSame(
                0,
                bccomp((string) DB::table('joint_ledgers')->where('id', $opened['ledger_id'])->value('balance'), '25', 6),
                'the cached balance mirrors the escrow account'
            );
        });
    }

    public function test_a_non_party_can_neither_propose_nor_approve(): void
    {
        $this->onLivePg(function () {
            [$currencyId, $a, $b] = $this->fixture();
            $outsider = $this->account($currencyId, '50');

            $opened = $this->joint->open('Members only', null, $currencyId, [$a, $b], 'all');
            $this->accounts->transfer($a, $opened['escrow_account_id'], $currencyId, '10', 'transfer', 'fund');

            try {
                $this->joint->propose($opened['ledger_id'], $outsider, $b, '5');
                $this->fail('a non-party must not propose');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('not a party', $e->getMessage());
            }

            $proposed = $this->joint->propose($opened['ledger_id'], $a, $b, '5');

            try {
                $this->joint->approve($proposed['movement_id'], $outsider);
                $this->fail('a non-party must not approve');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('not a party', $e->getMessage());
            }
        });
    }

    public function test_a_signer_cannot_approve_twice(): void
    {
        $this->onLivePg(function () {
            [$currencyId, $a, $b] = $this->fixture();
            $c = $this->account($currencyId, '0');

            $opened = $this->joint->open('Three signers', null, $currencyId, [$a, $b, $c], 'all');
            $this->accounts->transfer($a, $opened['escrow_account_id'], $currencyId, '10', 'transfer', 'fund');

            $proposed = $this->joint->propose($opened['ledger_id'], $a, $b, '5');

            // The proposer's signature is already on it.
            try {
                $this->joint->approve($proposed['movement_id'], $a);
                $this->fail('proposing already signed — a second signature from the same signer is refused');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('already approved', $e->getMessage());
            }
        });
    }

    public function test_an_underfunded_escrow_refuses_like_a_wallet(): void
    {
        $this->onLivePg(function () {
            [$currencyId, $a, $b] = $this->fixture();

            $opened = $this->joint->open('Thin fund', null, $currencyId, [$a, $b], 'all');
            $this->accounts->transfer($a, $opened['escrow_account_id'], $currencyId, '3', 'transfer', 'fund');

            $proposed = $this->joint->propose($opened['ledger_id'], $a, $b, '10');

            try {
                $this->joint->approve($proposed['movement_id'], $b);
                $this->fail('settlement beyond the escrow balance must refuse');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('no overdraft', $e->getMessage());
            }

            // The refusal rolled the whole act back: the movement still
            // waits, and the refused approval is NOT on the record — consent
            // and settlement are one act, so neither happened.
            $movement = DB::table('joint_ledger_movements')->where('id', $proposed['movement_id'])->first();
            $this->assertSame('pending', $movement->status);
            $this->assertCount(1, json_decode((string) $movement->approvals, true));
        });
    }

    public function test_majority_rule_settles_at_floor_half_plus_one(): void
    {
        $this->onLivePg(function () {
            [$currencyId, $a, $b] = $this->fixture();
            $c = $this->account($currencyId, '0');

            $opened = $this->joint->open('Majority fund', null, $currencyId, [$a, $b, $c], 'majority');
            $this->accounts->transfer($a, $opened['escrow_account_id'], $currencyId, '10', 'transfer', 'fund');

            $proposed = $this->joint->propose($opened['ledger_id'], $a, $b, '4');
            $this->assertSame('pending', $proposed['status'], '1 of 3 is not a majority');

            $approved = $this->joint->approve($proposed['movement_id'], $c);
            $this->assertSame('settled', $approved['status'], '2 of 3 is the majority — it settles');
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            $conn->rollBack();
            DB::setDefaultConnection($original);
        }
    }

    /** @return array{0: string, 1: string, 2: string} currency, account A (funded 100), account B */
    private function fixture(): array
    {
        $root = DB::table('jurisdictions')->whereNull('parent_id')->whereNull('deleted_at')->first();

        if ($root === null) {
            $this->markTestSkipped('no root jurisdiction on this box');
        }

        $currencyId = (string) Str::uuid();
        DB::table('currencies')->insert([
            'id'              => $currencyId,
            'jurisdiction_id' => $root->id,
            'name'            => 'Joint Test Unit',
            'code'            => 'J' . substr((string) Str::uuid(), 0, 6),
            'symbol'          => '¤',
            'precision'       => 2,
            'unit_kind'       => Currency::KIND_ABSTRACT,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return [$currencyId, $this->account($currencyId, '100'), $this->account($currencyId, '0')];
    }

    private function account(string $currencyId, string $balance): string
    {
        $id = (string) Str::uuid();

        DB::table('economic_accounts')->insert([
            'id'          => $id,
            'kind'        => 'user',
            'currency_id' => $currencyId,
            'balance'     => $balance,
            'status'      => 'open',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return $id;
    }
}
