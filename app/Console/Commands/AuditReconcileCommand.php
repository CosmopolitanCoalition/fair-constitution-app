<?php

namespace App\Console\Commands;

use App\Models\AuditChainReconciliation;
use App\Models\OperatorAccount;
use App\Models\User;
use App\Services\ChainReconciliationService;
use Illuminate\Console\Command;

/**
 * Detect audit-chain breaks and, with a constitutional acknowledgement, re-ground
 * them. The chain is tamper-EVIDENT: a genuine break is never silently rewritten;
 * an authority (a government office, or — where none exists yet — the de-facto
 * operator collective) signs an acknowledgement WITH A REASON, recorded on the
 * chain, and verifyChain then treats the break as grounded.
 *
 * Default authority is the de-facto operator collective (the founder/first operator
 * on a lone box). --dry-run lists breaks without acknowledging.
 */
class AuditReconcileCommand extends Command
{
    protected $signature = 'audit:reconcile
        {--from= : start at this seq (default: the whole chain)}
        {--operator= : username of the operator who signs the acknowledgement}
        {--reason= : the acknowledgement reason recorded on the chain}
        {--dry-run : list breaks without acknowledging}';

    protected $description = 'Detect audit-chain breaks and re-ground them by recorded constitutional acknowledgement.';

    public function handle(ChainReconciliationService $recon): int
    {
        $from = $this->option('from') !== null ? (int) $this->option('from') : null;

        $unacked = array_values(array_filter($recon->detectBreaks($from), fn ($b) => ! $b['acknowledged']));

        if ($unacked === []) {
            $this->info('No unacknowledged chain breaks — the chain is grounded.');

            return self::SUCCESS;
        }

        $this->warn(count($unacked).' unacknowledged chain break(s):');
        foreach ($unacked as $b) {
            $this->line("  seq {$b['break_seq']} — observed parent ".substr($b['observed_prev_hash'], 0, 16).'…');
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing acknowledged.');

            return self::SUCCESS;
        }

        [$operator, $founder] = $this->resolveSigner();
        if ($operator === null && $founder === null) {
            $this->error('No operator account or founder (is_operator) user to sign the acknowledgement.');

            return self::FAILURE;
        }

        $signer = $operator !== null ? "operator {$operator->username}" : "founder {$founder->email} (de-facto operator)";
        $consent = $operator !== null
            ? ['operators' => [$operator->id], 'threshold' => 'de-facto operator collective']
            : ['founder_user' => $founder->id, 'threshold' => 'de-facto operator (founder; operator plane not yet bootstrapped)'];

        $reason = (string) ($this->option('reason')
            ?: 'Chain break grounded after the pre-advisory-lock append fix: concurrent committed appends '
              .'(scheduler + Horizon workers) forked the chain. No data altered — the broken-but-real record is '
              .'acknowledged and carried forward by the de-facto operator collective.');

        foreach ($unacked as $b) {
            $recon->acknowledge(
                $b['break_seq'],
                $reason,
                AuditChainReconciliation::AUTHORITY_OPERATOR_COLLECTIVE,
                $operator,
                $founder,
                $consent,
            );
            $this->info("  acknowledged + grounded seq {$b['break_seq']} (signed by {$signer}).");
        }

        $this->info('Re-grounded '.count($unacked).' break(s). Run `audit:verify` to confirm.');

        return self::SUCCESS;
    }

    /**
     * The de-facto operator signer: an operator-plane account if one exists, else
     * the founder (is_operator) user on an instance founded before the operator
     * plane. Returns [?OperatorAccount, ?User].
     *
     * @return array{0:?OperatorAccount,1:?User}
     */
    private function resolveSigner(): array
    {
        $query = OperatorAccount::query()->where('status', OperatorAccount::STATUS_ACTIVE)->whereNull('deleted_at');
        if ($this->option('operator') !== null) {
            $query->where('username', (string) $this->option('operator'));
        }
        $operator = $query->orderBy('created_at')->first();

        if ($operator !== null) {
            return [$operator, null];
        }

        $founder = User::query()->where('is_operator', true)->whereNull('deleted_at')->orderBy('created_at')->first();

        return [null, $founder];
    }
}
