<?php

namespace App\Services\Economy;

use App\Support\HostCapacity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Resumable account-only observations. Published values never include partial work. */
class CurrencyReportService
{
    private const TABLES = [
        'issuance' => 'issuance_events', 'wallets' => 'economic_accounts',
        'treasuries' => 'treasury_accounts', 'transactions' => 'market_transactions',
    ];

    public function read(string $currencyId): array
    {
        $row = DB::table('currency_reports')->where('currency_id', $currencyId)->first();
        if ($row === null) return ['status' => 'not_started', 'data' => null, 'phase' => null, 'rows' => 0, 'started_at' => null, 'completed_at' => null];
        $totals = json_decode($row->totals, true);

        return [
            'status' => $row->status, 'phase' => $row->phase, 'rows' => $totals['rows'],
            'data' => $row->result === null ? null : json_decode($row->result, true),
            'started_at' => $row->result_started_at, 'completed_at' => $row->result_completed_at,
            'collection_started_at' => $row->started_at, 'updated_at' => $row->updated_at,
        ];
    }

    /** Lock the currency for first creation, then the same report row used by advancing jobs. */
    public function start(string $currencyId): string
    {
        return DB::transaction(function () use ($currencyId) {
            abort_unless(DB::table('currencies')->where('id', $currencyId)->lockForUpdate()->first(['id']), 404);
            $previous = DB::table('currency_reports')->where('currency_id', $currencyId)->lockForUpdate()->first();
            if ($previous && in_array($previous->status, ['running', 'failed'], true)) {
                if ($previous->status === 'failed') {
                    $point = json_decode($previous->checkpoint, true);
                    // A resumed checkpoint is a new attempt even before its
                    // next chunk commits. Old failure callbacks cannot own it.
                    $point['revision'] = (int) ($point['revision'] ?? 0) + 1;
                    DB::table('currency_reports')->where('currency_id', $currencyId)->update([
                        'status' => 'running', 'checkpoint' => json_encode($point), 'updated_at' => now(),
                    ]);
                }
                return $previous->run_id;
            }
            $ceilings = [];
            foreach (self::TABLES as $phase => $table) {
                $ceilings[$phase] = DB::table($table)->where('currency_id', $currencyId)->orderByDesc('id')->value('id');
            }
            $runId = (string) Str::uuid();
            DB::table('currency_reports')->updateOrInsert(['currency_id' => $currencyId], [
                'run_id' => $runId, 'status' => 'running', 'phase' => 'issuance',
                'checkpoint' => json_encode(['ceilings' => $ceilings, 'cursor' => null,
                    'revision' => $previous ? (int) (json_decode($previous->checkpoint, true)['revision'] ?? 0) + 1 : 0]),
                'totals' => json_encode(['supply' => '0.000000', 'in_circulation' => '0.000000', 'treasury_held' => '0.000000', 'volume' => '0.000000', 'top_sum' => '0.000000', 'top_taken' => 0, 'wallets' => 0, 'funded_wallets' => 0, 'rows' => 0]),
                'started_at' => now(), 'updated_at' => now(),
            ]);

            return $runId;
        });
    }

    /** One atomic chunk. Return true when another chunk remains. */
    public function advance(string $currencyId, string $runId, ?int $limit = null, ?int $revision = null): bool
    {
        $limit ??= $this->batchSize();
        if ($limit < 1) throw new \InvalidArgumentException('A report chunk must contain at least one row.');

        return DB::transaction(function () use ($currencyId, $runId, $limit, $revision) {
            $row = DB::table('currency_reports')->where('currency_id', $currencyId)->lockForUpdate()->first();
            if (! $row || $row->run_id !== $runId || $row->status !== 'running') return false;
            $point = json_decode($row->checkpoint, true);
            $currentRevision = (int) ($point['revision'] ?? 0);
            if ($revision !== null && $revision !== $currentRevision) return false;
            $point['revision'] = $currentRevision + 1;
            $totals = json_decode($row->totals, true);
            $phase = $row->phase;
            if ($phase === 'cleanup') {
                $balances = DB::table('currency_report_balances')->where('run_id', $runId)->orderBy('balance')->limit($limit)->pluck('balance');
                if ($balances->isNotEmpty()) {
                    DB::table('currency_report_balances')->where('run_id', $runId)->whereIn('balance', $balances)->delete();
                } else {
                    DB::table('currency_reports')->where('currency_id', $currencyId)->update([
                        'status' => 'complete', 'updated_at' => now(), 'result' => json_encode($this->result($totals)),
                        'checkpoint' => json_encode($point),
                        'result_started_at' => $row->started_at, 'result_completed_at' => now(),
                    ]);
                    return false;
                }
            } elseif ($phase === 'concentration') {
                $needed = intdiv($totals['funded_wallets'] + 9, 10) - $totals['top_taken'];
                $query = DB::table('currency_report_balances')->where('run_id', $runId);
                if ($point['cursor'] !== null) $query->where('balance', '<', $point['cursor']);
                $buckets = $needed > 0 ? $query->orderByDesc('balance')->limit($limit)->get(['balance', 'wallets']) : collect();
                foreach ($buckets as $bucket) {
                    $take = min($needed, (int) $bucket->wallets);
                    $totals['top_sum'] = bcadd($totals['top_sum'], bcmul((string) $bucket->balance, (string) $take, 6), 6);
                    $totals['top_taken'] += $take;
                    $needed -= $take;
                    $point['cursor'] = (string) $bucket->balance;
                    if ($needed === 0) break;
                }
                if ($needed > 0 && $buckets->isEmpty()) throw new \RuntimeException('Report balance counts do not match the collected wallet count.');
                if ($needed === 0) { $phase = 'cleanup'; $point['cursor'] = null; }
            } else {
                $table = self::TABLES[$phase];
                $query = DB::table($table)->where('currency_id', $currencyId);
                if ($point['ceilings'][$phase] !== null) $query->where('id', '<=', $point['ceilings'][$phase]);
                if ($point['cursor'] !== null) $query->where('id', '>', $point['cursor']);
                $columns = match ($phase) {
                    'issuance' => ['id', 'direction', 'amount', 'created_at'],
                    'transactions' => ['id', 'amount', 'created_at'],
                    default => ['id', 'balance', 'deleted_at'],
                };
                // Page the indexed source roster first. Eligibility is evaluated
                // within this chunk, so sparse dates/deletions cannot force a global scan.
                $records = $point['ceilings'][$phase] === null ? collect() : $query->orderBy('id')->limit($limit)->get($columns);
                $histogram = [];
                $start = Carbon::parse($row->started_at);
                foreach ($records as $record) {
                    $point['cursor'] = (string) $record->id;
                    $totals['rows']++;
                    if ($phase === 'issuance' || $phase === 'transactions') {
                        $at = Carbon::parse($record->created_at);
                        if ($at->gt($start)) continue;
                        if ($phase === 'transactions') {
                            if ($at->lt($start->copy()->subDays(30))) continue;
                            $totals['volume'] = bcadd($totals['volume'], (string) $record->amount, 6);
                        } else {
                            $totals['supply'] = $record->direction === 'mint'
                                ? bcadd($totals['supply'], (string) $record->amount, 6)
                                : bcsub($totals['supply'], (string) $record->amount, 6);
                        }
                        continue;
                    }
                    if ($record->deleted_at !== null) continue;
                    $balance = bcadd((string) $record->balance, '0', 6);
                    $key = $phase === 'wallets' ? 'in_circulation' : 'treasury_held';
                    $totals[$key] = bcadd($totals[$key], $balance, 6);
                    if ($phase === 'wallets') {
                        $totals['wallets']++;
                        if (bccomp($balance, '0', 6) > 0) {
                            $totals['funded_wallets']++;
                            $histogram[$balance] = ($histogram[$balance] ?? 0) + 1;
                        }
                    }
                }
                if ($histogram !== []) {
                    $values = [];
                    foreach ($histogram as $balance => $wallets) $values[] = ['run_id' => $runId, 'balance' => $balance, 'wallets' => $wallets];
                    DB::table('currency_report_balances')->upsert($values, ['run_id', 'balance'], [
                        'wallets' => DB::raw('currency_report_balances.wallets + excluded.wallets'),
                    ]);
                }
                if ($records->isEmpty()) {
                    $phases = array_keys(self::TABLES);
                    $phase = $phases[array_search($phase, $phases, true) + 1] ?? 'concentration';
                    $point['cursor'] = null;
                }
            }
            DB::table('currency_reports')->where('currency_id', $currencyId)->update([
                'phase' => $phase, 'checkpoint' => json_encode($point), 'totals' => json_encode($totals), 'updated_at' => now(),
            ]);
            return true;
        });
    }

    /** Queue only the current checkpoint; a stale delivery cannot work on or fail a later one. */
    public function dispatch(string $currencyId, string $runId): bool
    {
        $row = DB::table('currency_reports')->where('currency_id', $currencyId)->where('run_id', $runId)
            ->where('status', 'running')->first(['checkpoint']);
        if ($row === null) return false;
        $revision = (int) (json_decode($row->checkpoint, true)['revision'] ?? 0);
        \App\Jobs\RefreshCurrencyReportJob::dispatch($currencyId, $runId, $revision)->afterCommit();

        return true;
    }

    public function fail(string $currencyId, string $runId, int $revision): void
    {
        DB::transaction(function () use ($currencyId, $runId, $revision) {
            $row = DB::table('currency_reports')->where('currency_id', $currencyId)->lockForUpdate()->first();
            if (! $row || $row->run_id !== $runId || $row->status !== 'running') return;
            if ((int) (json_decode($row->checkpoint, true)['revision'] ?? 0) !== $revision) return;
            DB::table('currency_reports')->where('currency_id', $currencyId)->update(['status' => 'failed', 'updated_at' => now()]);
        });
    }

    public function batchSize(): int
    {
        $available = max(1, HostCapacity::workerRecycleLightMb() * 1048576 - memory_get_usage(true));
        $phpLimit = trim((string) ini_get('memory_limit'));
        if (preg_match('/^(\d+)\s*([KMG]?)$/i', $phpLimit, $m)) {
            $bytes = (int) $m[1] * (1024 ** array_search(strtoupper($m[2]), ['', 'K', 'M', 'G'], true));
            $available = min($available, max(1, $bytes - memory_get_usage(true)));
        }
        foreach ([['/sys/fs/cgroup/memory.max', '/sys/fs/cgroup/memory.current'], ['/sys/fs/cgroup/memory/memory.limit_in_bytes', '/sys/fs/cgroup/memory/memory.usage_in_bytes']] as [$capPath, $usedPath]) {
            if (! is_readable($capPath) || ! is_readable($usedPath)) continue;
            $cap = trim(file_get_contents($capPath));
            if (ctype_digit($cap)) $available = min($available, max(1, (int) $cap - (int) trim(file_get_contents($usedPath))));
            break;
        }
        // Memory share and per-row appetite are operator-overridable. The final
        // ceiling is the SQL parameter budget for histogram upserts, not host sizing.
        $fraction = max(0.0001, min(0.25, (float) env('CGA_REPORT_MEMORY_FRACTION', 1 / 64)));
        $rowBytes = max(512, (int) env('CGA_REPORT_ROW_BYTES', 2048));
        return max(1, min(intdiv(65535, 4), (int) floor($available * $fraction / $rowBytes)));
    }

    /** Recover a committed checkpoint whose continuation was lost, without starting new reports. */
    public function resumeStalled(): void
    {
        $timeout = max(1, (int) config('horizon.defaults.supervisor-1.timeout', 60));
        $rows = DB::table('currency_reports')->where('status', 'running')->where('updated_at', '<', now()->subSeconds(2 * $timeout))
            ->orderBy('updated_at')->orderBy('currency_id')->limit(HostCapacity::defaultQueueWorkers())->get(['currency_id', 'run_id']);
        foreach ($rows as $row) $this->dispatch($row->currency_id, $row->run_id);
    }

    private function result(array $t): array
    {
        return [
            'supply' => $t['supply'], 'in_circulation' => $t['in_circulation'], 'treasury_held' => $t['treasury_held'],
            'wallets' => $t['wallets'], 'funded_wallets' => $t['funded_wallets'],
            'top_decile_share_pct' => $t['funded_wallets'] === 0 || bccomp($t['in_circulation'], '0', 6) <= 0
                ? null : bcmul(bcdiv($t['top_sum'], $t['in_circulation'], 8), '100', 2),
            'velocity_30d' => bccomp($t['supply'], '0', 6) <= 0 ? null : bcdiv($t['volume'], $t['supply'], 4),
            'per_jurisdiction' => null, 'time_series' => null,
        ];
    }
}
