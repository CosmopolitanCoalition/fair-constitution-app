<?php

namespace App\Services\Demo;

use App\Models\SimRun;
use App\Services\Economy\LedgerService;
use App\Support\HostCapacity;
use App\Support\SimTimer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Step 5 only: bounded payments and their fenced DONE records commit together. */
class SimStipendBatch
{
    use \Illuminate\Database\DetectsLostConnections;

    private const TRANSACTION_SECONDS = 15;

    public static function capacity(?int $remainingBytes = null): int
    {
        $remainingBytes ??= min(480, HostCapacity::workerRecycleHeavyMb()) * 1048576 - memory_get_usage(true);
        // Conservative headroom per prepared scope; each retains at most the
        // existing 25-wallet sample. This does not change the worker pool.
        return max(1, min(4, intdiv(max(0, $remainingBytes), 8 * 1048576)));
    }

    /** @return array{done:int, review:int, released:int} */
    public function run(SimRun $run, array $items, string $token, ?\Closure $beat = null, ?\Closure $continue = null): array
    {
        if ($items === [] || count($items) > 4 || count(array_unique(array_column($items, 'id'))) !== count($items)) {
            throw new \InvalidArgumentException('A stipend batch needs one to four distinct claimed items.');
        }
        foreach ($items as $item) {
            if ($item->kind !== 'stipend_scope') { throw new \InvalidArgumentException('Stipend batches accept only stipend scopes.'); }
        }
        if (DB::transactionLevel() !== 0) { throw new \LogicException('The stipend batch must own its transaction.'); }
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $ids = array_column($items, 'id');
        $active = null;
        $committed = [];
        $previousTimeout = null;
        $allowed = fn () => ($continue === null || $continue())
            && ($run->refresh()->phase === 'stipends') && $run->isClaimable();
        SimTimer::open('stage.stipend_batch');
        try {
            $prepared = [];
            SimTimer::open('stipend_batch.prepare');
            try {
                foreach ($items as $item) {
                    if (! $allowed()) { return $this->release($run->id, $ids, $token, 'batch stopped before payment'); }
                    $active = $item->id;
                    $prepared[$item->id] = app(SimEconomyService::class)->prepareStipendFor((string) $item->jurisdiction_id, $beat, true);
                }
            } finally { SimTimer::close('stipend_batch.prepare'); }
            $active = null;
            if (! $allowed()) { return $this->release($run->id, $ids, $token, 'batch stopped before payment'); }
            $beat && $beat();
            // PostgreSQL arms transaction_timeout when the transaction starts,
            // not when SET LOCAL changes it inside an existing transaction.
            $previousTimeout = DB::selectOne("SELECT setting FROM pg_settings WHERE name='transaction_timeout'")->setting;
            $budget = self::TRANSACTION_SECONDS * 1000;
            DB::selectOne("SELECT set_config('transaction_timeout', ?, false)", [
                (string) min($budget, (int) $previousTimeout > 0 ? (int) $previousTimeout : $budget),
            ]);
            $pdo = $connection->getPdo(); // preparation may have reconnected
            SimTimer::open('stipend_batch.transaction');
            try {
                $committed = DB::transaction(function () use ($run, $items, $ids, $token, $prepared, &$active): array {
                    $began = hrtime(true);
                    $checkDeadline = static function () use ($began): void {
                        if (hrtime(true) - $began > self::TRANSACTION_SECONDS * 1_000_000_000) {
                            throw new \RuntimeException('stipend batch budget exceeded');
                        }
                    };
                    // The statement limit is local; the outer session timer
                    // is restored in finally, including after reconnect.
                    $budget = self::TRANSACTION_SECONDS * 1000;
                    DB::selectOne("SELECT set_config('statement_timeout', LEAST(COALESCE(NULLIF(
                        (SELECT setting::bigint FROM pg_settings WHERE name='statement_timeout'),0), {$budget}), {$budget})::text, true)");
                    // Fence every item until payment AND settlement commit. A
                    // reclaimer must wait, then recheck status/token, not repay.
                    $owned = DB::table('sim_items')->where('run_id', $run->id)->where('kind', 'stipend_scope')
                        ->whereIn('id', $ids)->where('status', 'running')->where('claim_token', $token)
                        ->orderBy('id')->lockForUpdate()->get();
                    if ($owned->count() !== count($items)
                        || ! DB::table('sim_worker_leases')->where('id', $token)->where('run_id', $run->id)->exists()) {
                        throw new \RuntimeException('stipend batch ownership lost');
                    }
                    foreach ($items as $item) {
                        if ($owned->firstWhere('id', $item->id)->jurisdiction_id !== $item->jurisdiction_id) {
                            throw new \RuntimeException('stipend batch ownership lost');
                        }
                    }
                    // One contended acquisition; existing ledger posts reenter
                    // this same transaction lock and still read a fresh head.
                    SimTimer::open('stipend_batch.append_wait');
                    try { DB::statement('SELECT pg_advisory_xact_lock(?)', [LedgerService::APPEND_LOCK_KEY]); }
                    finally { SimTimer::close('stipend_batch.append_wait'); }
                    $results = [];
                    foreach ($items as $item) {
                        $active = $item->id;
                        $checkDeadline();
                        SimTimer::open('stipend_batch.payment');
                        try { $result = $prepared[$item->id] === null ? null : $prepared[$item->id](); }
                        finally { SimTimer::close('stipend_batch.payment'); }
                        $results[$item->id] = $result === null
                            ? ['ran' => false, 'recipients' => 0, 'paid_wallets' => 0, 'total' => '0', 'short_paid' => false, 'skipped' => 'no residents with wallets']
                            : ['ran' => true, 'recipients' => $result['recipients'], 'total' => $result['total'],
                                'short_paid' => $result['short_paid'], 'skipped' => null, 'paid_wallets' => $result['paid_wallets'],
                                'disbursement_id' => $result['disbursement_id']];
                    }
                    $active = null;
                    $checkDeadline();
                    $rows = [];
                    foreach ($results as $id => $metrics) { $rows[] = ['id' => $id, 'metrics' => $metrics]; }
                    SimTimer::open('stipend_batch.settle');
                    try {
                        $n = DB::update("UPDATE sim_items s SET status='done', claim_token=NULL,
                            metrics=v.metrics, reason=NULL, finished_at=now(), updated_at=now()
                            FROM jsonb_to_recordset(?::jsonb) AS v(id uuid, metrics jsonb)
                            WHERE s.id=v.id AND s.run_id=? AND s.kind='stipend_scope'
                              AND s.status='running' AND s.claim_token=?", [json_encode($rows, JSON_THROW_ON_ERROR), $run->id, $token]);
                        if ($n !== count($items)) { throw new \RuntimeException('stipend batch ownership lost'); }
                    } finally { SimTimer::close('stipend_batch.settle'); }
                    $checkDeadline();
                    SimTimer::open('stipend_batch.commit');
                    return $results;
                });
            } finally {
                SimTimer::close('stipend_batch.commit');
                SimTimer::close('stipend_batch.transaction');
            }
            foreach ($committed as $metrics) {
                SimTimer::record('stipend_batch.scopes', 0);
                if ($metrics['paid_wallets'] > 0) {
                    SimTimer::record('stipend_batch.paid_scopes', 0);
                    for ($i = 0; $i < $metrics['paid_wallets']; $i++) { SimTimer::record('stipend_batch.wallets', 0); }
                }
            }
            return ['done' => count($committed), 'review' => 0, 'released' => 0];
        } catch (\Throwable $error) {
            // Laravel can decrement its nesting counter after a committing
            // event fails, although PDO still owns the uncommitted transaction.
            // Never perform recovery writes inside that abandoned transaction.
            try {
                while ($connection->transactionLevel() > 0) { $connection->rollBack(); }
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
            } catch (\PDOException) {
                DB::purge($connection->getName());
            }
            // Token/status predicates also protect a commit whose client lost
            // its acknowledgement: durable DONE rows can never become review
            // or pending here. Retrying the worker therefore cannot repay them.
            $retry = str_starts_with($error->getMessage(), 'stipend batch ')
                || $this->causedByLostConnection($error)
                || in_array((string) $error->getCode(), ['55P03', '57014', '25P04', '40P01', '40001'], true);
            $review = 0;
            if (! $retry && $active !== null) {
                $review = DB::table('sim_items')->where('id', $active)->where('run_id', $run->id)
                    ->where('status', 'running')->where('claim_token', $token)
                    ->update(['status' => 'review', 'claim_token' => null, 'metrics' => json_encode(['error' => class_basename($error)]),
                        'reason' => Str::limit($error->getMessage(), 500), 'finished_at' => now(), 'updated_at' => now()]);
            }
            Log::warning('sim stipend batch rolled back or acknowledgement failed', ['run' => $run->id, 'item' => $active, 'error' => $error->getMessage()]);
            $result = $this->release($run->id, $ids, $token, 'batch retry: '.Str::limit($error->getMessage(), 450));
            $result['review'] = $review;
            return $result;
        } finally {
            SimTimer::close('stage.stipend_batch');
            if ($previousTimeout !== null) {
                try { DB::selectOne("SELECT set_config('transaction_timeout', ?, false)", [$previousTimeout]); }
                catch (\Throwable) { DB::purge($connection->getName()); }
            }
        }
    }

    private function release(string $run, array $ids, string $token, string $reason): array
    {
        $n = DB::table('sim_items')->where('run_id', $run)->where('kind', 'stipend_scope')
            ->whereIn('id', $ids)->where('status', 'running')->where('claim_token', $token)
            ->update(['status' => 'pending', 'claim_token' => null, 'reason' => $reason, 'updated_at' => now()]);
        return ['done' => 0, 'review' => 0, 'released' => $n];
    }
}
