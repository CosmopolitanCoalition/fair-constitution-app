<?php

namespace App\Services\Economy;

use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * PROTECTED — Phase L slice L-2. The ONLY writer of ledger_entries.
 *
 * Every value movement in the app is a balanced posting through post().
 * Nothing else debits or credits anything: no controller, no job, no other
 * service, no seeder. LedgerIntegrityTest source-scans app/ to enforce that,
 * the way CgcIpPublicDomainTest guards the public-domain register.
 *
 * WHY that matters: with one writer, "Σdebits = Σcredits per currency" is a
 * property of the system rather than a report someone runs. The public ledger
 * is auditable precisely because there is no side door.
 *
 * DIRECTION SEMANTICS, stated plainly because accounting convention is
 * ambiguous and the ambiguity would be a bug:
 *
 *     credit = value INTO the account   (balance increases)
 *     debit  = value OUT of the account (balance decreases)
 *
 * A posting is balanced when Σdebits == Σcredits within its entry_group, per
 * currency — i.e. value is conserved. Minting is the one lawful exception and
 * it does not live here: an issuance credits an account with no matching
 * debit, so it goes through IssuanceService (slice L-3), which records an
 * append-only issuance_event and posts the credit with kind='issuance'. Money
 * may only come into existence where the constitution says it may (Art. V §5).
 *
 * APPEND-ONLY + HASH-CHAINED, on the same pattern as audit_log:
 *     hash(n) = sha256(prev_hash ‖ canonical_json(payload))
 * It reuses AuditService::chainHash/canonicalJson rather than forking the
 * primitives — one hash discipline in the codebase, not two. A correction is
 * a new balanced posting, never an edit; the DB trigger enforces that even
 * against a direct SQL statement.
 */
class LedgerService
{
    /**
     * Transaction-scoped advisory lock serializing every ledger append (the
     * bytes of "LEDGR"). Distinct from the audit chain's key so the two
     * chains never contend with each other. One appender at a time → the
     * head read is always current → the chain cannot fork.
     */
    public const APPEND_LOCK_KEY = 0x4c45444752;

    public const DIRECTION_DEBIT  = 'debit';
    public const DIRECTION_CREDIT = 'credit';

    /**
     * Post one balanced set of legs and return the entry_group uuid.
     *
     * @param  string  $kind   what moved the money (transfer|stipend|levy|
     *                         grant|appropriation|issuance|order|dues)
     * @param  array<int, array{account_type:string, account_id:string, currency_id:string, direction:string, amount:numeric}>  $legs
     * @param  bool  $allowUnbalanced  ONLY for issuance/burn, where value
     *                                 enters or leaves the system lawfully.
     *                                 Callers other than IssuanceService must
     *                                 never pass true.
     */
    public function post(
        string $kind,
        array $legs,
        ?string $refType = null,
        ?string $refId = null,
        bool $allowUnbalanced = false,
    ): string {
        $this->assertWellFormed($legs);

        if (! $allowUnbalanced) {
            $this->assertBalanced($legs);
        }

        $entryGroup = (string) Str::uuid();

        $write = function () use ($kind, $legs, $refType, $refId, $entryGroup): string {
            // Serialize every appender so no two anchor on the same head.
            DB::statement('SELECT pg_advisory_xact_lock(?)', [self::APPEND_LOCK_KEY]);

            $head = DB::selectOne('SELECT hash FROM ledger_entries ORDER BY seq DESC LIMIT 1');
            $prevHash = $head->hash ?? AuditService::GENESIS_PREV_HASH;

            foreach ($legs as $leg) {
                // Canonicalize the amount on the way IN with the same
                // function the verify walk uses on the way out. Postgres
                // returns numeric(24,6) zero-padded ("10.000000"), so hashing
                // the caller's raw string ("10", "10.00", 10.5) would produce
                // a chain that cannot be re-verified from the stored row.
                $payload = [
                    'entry_group'  => $entryGroup,
                    'account_type' => $leg['account_type'],
                    'account_id'   => $leg['account_id'],
                    'currency_id'  => $leg['currency_id'],
                    'direction'    => $leg['direction'],
                    'amount'       => $this->trimAmount((string) $leg['amount']),
                    'kind'         => $kind,
                    'ref_type'     => $refType,
                    'ref_id'       => $refId,
                ];

                $canonical = AuditService::canonicalJson($payload);
                $hash      = AuditService::chainHash($prevHash, $canonical);

                DB::table('ledger_entries')->insert([
                    'id'           => (string) Str::uuid(),
                    'entry_group'  => $entryGroup,
                    'account_type' => $leg['account_type'],
                    'account_id'   => $leg['account_id'],
                    'currency_id'  => $leg['currency_id'],
                    'direction'    => $leg['direction'],
                    'amount'       => $leg['amount'],
                    'kind'         => $kind,
                    'ref_type'     => $refType,
                    'ref_id'       => $refId,
                    'prev_hash'    => $prevHash,
                    'hash'         => $hash,
                    'created_at'   => now(),
                ]);

                $this->applyToBalance($leg);

                $prevHash = $hash;
            }

            return $entryGroup;
        };

        return DB::transactionLevel() > 0 ? $write() : DB::transaction($write);
    }

    /**
     * Walk the chain recomputing every link.
     *
     * @return true|int  true when intact, otherwise the seq of the first
     *                   broken link.
     */
    public function verifyChain(): true|int
    {
        $expectedPrev = AuditService::GENESIS_PREV_HASH;

        $rows = DB::table('ledger_entries')
            ->select(['seq', 'entry_group', 'account_type', 'account_id', 'currency_id',
                'direction', 'amount', 'kind', 'ref_type', 'ref_id', 'prev_hash', 'hash'])
            ->orderBy('seq')
            ->cursor();

        foreach ($rows as $row) {
            if ($row->prev_hash !== $expectedPrev) {
                return (int) $row->seq;
            }

            $canonical = AuditService::canonicalJson([
                'entry_group'  => $row->entry_group,
                'account_type' => $row->account_type,
                'account_id'   => $row->account_id,
                'currency_id'  => $row->currency_id,
                'direction'    => $row->direction,
                'amount'       => $this->trimAmount($row->amount),
                'kind'         => $row->kind,
                'ref_type'     => $row->ref_type,
                'ref_id'       => $row->ref_id,
            ]);

            if (AuditService::chainHash($row->prev_hash, $canonical) !== $row->hash) {
                return (int) $row->seq;
            }

            $expectedPrev = $row->hash;
        }

        return true;
    }

    /**
     * Σdebits − Σcredits per currency across the whole ledger. Every value
     * is zero on a healthy ledger EXCEPT where issuance has lawfully created
     * money, so this returns the raw figures rather than a boolean — the
     * caller decides what it expects.
     *
     * @return array<string, string>  currency_id => signed total
     */
    public function imbalanceByCurrency(): array
    {
        $rows = DB::select(
            "SELECT currency_id,
                    SUM(CASE WHEN direction = 'debit' THEN amount ELSE -amount END) AS delta
             FROM ledger_entries
             GROUP BY currency_id"
        );

        $out = [];
        foreach ($rows as $row) {
            $out[$row->currency_id] = (string) $row->delta;
        }

        return $out;
    }

    /** @param array<int, array<string, mixed>> $legs */
    private function assertWellFormed(array $legs): void
    {
        if ($legs === []) {
            throw new InvalidArgumentException('A ledger posting needs at least one leg.');
        }

        foreach ($legs as $i => $leg) {
            foreach (['account_type', 'account_id', 'currency_id', 'direction', 'amount'] as $field) {
                if (! isset($leg[$field])) {
                    throw new InvalidArgumentException("Ledger leg {$i} is missing [{$field}].");
                }
            }

            if (! in_array($leg['direction'], [self::DIRECTION_DEBIT, self::DIRECTION_CREDIT], true)) {
                throw new InvalidArgumentException(
                    "Ledger leg {$i} direction must be debit or credit, got [{$leg['direction']}]."
                );
            }

            if (bccomp((string) $leg['amount'], '0', 6) !== 1) {
                throw new InvalidArgumentException(
                    "Ledger leg {$i} amount must be greater than zero (a movement of nothing is not a movement)."
                );
            }
        }
    }

    /** @param array<int, array<string, mixed>> $legs */
    private function assertBalanced(array $legs): void
    {
        $byCurrency = [];

        foreach ($legs as $leg) {
            $cid = $leg['currency_id'];
            $byCurrency[$cid] ??= ['debit' => '0', 'credit' => '0'];
            $byCurrency[$cid][$leg['direction']] = bcadd(
                $byCurrency[$cid][$leg['direction']],
                (string) $leg['amount'],
                6
            );
        }

        foreach ($byCurrency as $cid => $sides) {
            if (bccomp($sides['debit'], $sides['credit'], 6) !== 0) {
                throw new InvalidArgumentException(sprintf(
                    'Unbalanced posting for currency %s: debits %s, credits %s. Value is conserved — every movement has both sides.',
                    $cid,
                    $sides['debit'],
                    $sides['credit']
                ));
            }
        }
    }

    /** @param array<string, mixed> $leg */
    private function applyToBalance(array $leg): void
    {
        if ($leg['account_type'] !== 'treasury_accounts') {
            // economic_accounts arrive in slice M-1.
            return;
        }

        $delta = $leg['direction'] === self::DIRECTION_CREDIT
            ? (string) $leg['amount']
            : '-' . (string) $leg['amount'];

        DB::table('treasury_accounts')
            ->where('id', $leg['account_id'])
            ->update([
                'balance'    => DB::raw('balance + ' . $this->sqlNumeric($delta)),
                'updated_at' => now(),
            ]);
    }

    /**
     * numeric(24,6) comes back from postgres zero-padded ("10.000000"); the
     * hash was taken over what the caller passed. Normalize both sides to a
     * trimmed decimal so a verify walk reproduces the append-time bytes.
     */
    private function trimAmount(string $amount): string
    {
        if (! str_contains($amount, '.')) {
            return $amount;
        }

        return rtrim(rtrim($amount, '0'), '.') ?: '0';
    }

    /** Guard against anything but a decimal reaching raw SQL. */
    private function sqlNumeric(string $value): string
    {
        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Illegal numeric [{$value}].");
        }

        return $value;
    }
}
