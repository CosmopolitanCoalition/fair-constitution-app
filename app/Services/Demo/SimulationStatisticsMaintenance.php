<?php

namespace App\Services\Demo;

use App\Support\HostCapacity;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Supplemental Step 5 statistics, not a replacement for autovacuum. D007 showed
 * a new board category becoming expensive before the normal changed-row trigger.
 * Only the demonstrated column is allowed. No application records are modified.
 */
class SimulationStatisticsMaintenance
{
    public const TARGET = 'boards.boardable_type';

    public const EXECUTION_LOCK = 0x53494D53544154; // SIMSTAT, one owner per database

    private const CHECK_SECONDS = 60;

    private const COOLDOWN_SECONDS = 300;

    private const PERIODIC_SECONDS = 900;

    /** All statements/ownership use one fenced session; no reconnect under a lock. */
    public function check(string $target = self::TARGET): array
    {
        if ($target !== self::TARGET) {
            throw new \InvalidArgumentException('Unreviewed simulation statistics target.');
        }
        $db = DB::connection();
        if ($db->getDriverName() !== 'pgsql') {
            return ['status' => 'skipped', 'reason' => 'postgresql_only'];
        }
        if ($db->transactionLevel() !== 0) {
            throw new \LogicException('Statistics maintenance must own its transaction.');
        }
        $pdo = $db->getPdo();
        $locked = false;
        try {
            $lock = $pdo->prepare('SELECT pg_try_advisory_lock(?)');
            $lock->execute([self::EXECUTION_LOCK]);
            $locked = (bool) $lock->fetchColumn();
            if (! $locked) {
                return ['status' => 'skipped', 'reason' => 'another_owner'];
            }
            $db->setReconnector(static function () {
                throw new \RuntimeException('Statistics maintenance lost its owning session.');
            });
            // Metadata checks must remain cheap even on a busy host. The ANALYZE
            // transaction below has its own separate, host-derived time budget.
            $db->statement("SET statement_timeout = '3s'");
            $db->statement("SET lock_timeout = '250ms'");
            $ready = $db->selectOne("SELECT to_regclass('sim_statistics_maintenance') IS NOT NULL
                AND to_regclass('sim_statistics_attempts') IS NOT NULL AS ready");
            if (! $ready->ready) {
                return ['status' => 'skipped', 'reason' => 'migration_required'];
            }

            return $this->ownedCheck($db);
        } catch (\Throwable $error) {
            // Losing the session can also prevent persisting failure. The durable
            // running attempt is recovered by the next owner; never fail a scope.
            Log::warning('Step 5 statistics check failed', ['target' => self::TARGET,
                'error' => mb_substr($error->getMessage(), 0, 1000)]);

            return ['status' => 'failed', 'reason' => 'check_failed'];
        } finally {
            if ($locked) {
                try {
                    $unlock = $pdo->prepare('SELECT pg_advisory_unlock(?)');
                    $unlock->execute([self::EXECUTION_LOCK]);
                } catch (\PDOException) {
                    // Connection death also releases the lock.
                } finally {
                    DB::purge($db->getName()); // discard fence and local timeouts
                }
            }
        }
    }

    private function ownedCheck(Connection $db): array
    {
        $state = $db->table('sim_statistics_maintenance')->where('target', self::TARGET)->first();
        if ($state && $state->next_check_at && Carbon::parse($state->next_check_at)->isFuture()) {
            return ['status' => 'skipped', 'reason' => 'check_cooldown'];
        }
        $db->table('sim_statistics_maintenance')->insertOrIgnore(['target' => self::TARGET]);
        if ($state?->last_attempt_id) {
            // Obtaining the session lock proves a prior 'running' owner is gone.
            $db->table('sim_statistics_attempts')->where('id', $state->last_attempt_id)
                ->where('status', 'running')->update(['status' => 'abandoned', 'finished_at' => now(),
                    'error' => 'Previous owning session ended before recording completion; eligible checks may retry.']);
        }
        $run = $db->table('sim_runs')->where('status', 'running')->where('phase', 'civics')
            ->whereNull('halt_requested_at')->orderBy('id')->first(['id', 'phase']);
        if (! $run) {
            return $this->skip($db, 'no_active_civics_run');
        }

        $minimum = max(50, min(1000, HostCapacity::sweepChunk()));
        $progress = $db->table('sim_timings')->where('run_id', $run->id)
            ->where('part', 'stage.civics_scope')->value('count');
        if ($progress === null || ! config('cga.sim.timings', true)) {
            // Timings can be disabled. Read at most one host-bounded prefix from
            // the existing claim index, never COUNT the planet's worklist.
            $progress = $db->table('sim_items')->where('run_id', $run->id)
                ->where('kind', 'civics_scope')->where('status', 'done')->limit($minimum)->pluck('id')->count();
        }
        if ($progress < $minimum) {
            return $this->skip($db, 'awaiting_population', ['progress' => (int) $progress, 'minimum' => $minimum]);
        }

        $stats = $this->statistics($db);
        if (! $stats || $stats->relkind !== 'r' || ! $stats->can_analyze || $stats->statistics_target === 0) {
            return $this->skip($db, 'target_unavailable_or_disabled');
        }
        $evidence = (array) $stats + ['progress' => (int) $progress, 'minimum' => $minimum];
        if ($state?->next_attempt_at && Carbon::parse($state->next_attempt_at)->isFuture()) {
            return $this->skip($db, 'attempt_cooldown', $evidence);
        }
        if ($stats->analyzed_at && Carbon::parse($stats->analyzed_at)->gt(now()->subSeconds(self::COOLDOWN_SECONDS))) {
            // Adopt an observed fresh external refresh without claiming we ran
            // it. A column-only ANALYZE does NOT reset n_mod_since_analyze.
            if ($stats->has_organizations) {
                $db->table('sim_statistics_maintenance')->where('target', self::TARGET)->update([
                    'change_baseline' => json_encode($this->baseline($evidence), JSON_THROW_ON_ERROR)]);
            }

            return $this->skip($db, 'recently_analyzed', $evidence);
        }
        $changes = (int) $stats->modifications;
        $baseline = json_decode($state?->change_baseline ?? 'null', true);
        if ($baseline && (int) $baseline['relation_oid'] === (int) $stats->relation_oid
            && $baseline['stats_reset'] === $stats->stats_reset
            && (int) $stats->total_changes >= (int) $baseline['total_changes']) {
            $changes = min($changes, (int) $stats->total_changes - (int) $baseline['total_changes']);
        }
        $evidence['effective_modifications'] = $changes;
        // These are catalog estimates, not table counts. Require actual inserted
        // category rows as well: progress alone might describe scopes with no CGC.
        $initial = ! $stats->has_organizations && $changes >= $minimum;
        $changeThreshold = max($minimum * 5, (int) ceil(max(0, $stats->estimated_rows) * 0.02));
        $evidence['material_change_threshold'] = $changeThreshold;
        $material = $changes >= $changeThreshold;
        if (! $initial && ! $material) {
            return $this->skip($db, 'insufficient_change', $evidence);
        }
        if (! $initial && $stats->analyzed_at
            && Carbon::parse($stats->analyzed_at)->gt(now()->subSeconds(self::PERIODIC_SECONDS))) {
            return $this->skip($db, 'periodic_cooldown', $evidence);
        }
        if (! $db->table('boards')->where('boardable_type', 'organizations')->exists()) {
            return $this->skip($db, 'category_not_populated', $evidence);
        }

        $reason = $initial ? 'new_organization_category' : 'material_board_changes';
        $budgets = $this->budgets((float) HostCapacity::hostMemoryGb(), (int) $stats->shared_buffers_bytes);
        $evidence['budgets'] = $budgets;
        $attempt = (string) Str::uuid();
        $db->transaction(function () use ($db, $run, $reason, $attempt, $evidence) {
            $db->table('sim_statistics_attempts')->insert(['id' => $attempt, 'target' => self::TARGET,
                'run_id' => $run->id, 'phase' => $run->phase, 'reason' => $reason, 'status' => 'running',
                'started_at' => now(), 'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR)]);
            $db->table('sim_statistics_maintenance')->where('target', self::TARGET)->update([
                'last_attempt_id' => $attempt, 'checked_at' => now(), 'reason' => $reason,
                'next_check_at' => now()->addSeconds(self::CHECK_SECONDS),
                'next_attempt_at' => now()->addSeconds(self::COOLDOWN_SECONDS),
                'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR)]);
        });
        try {
            $db->transaction(function () use ($db, $budgets) {
                $db->selectOne("SELECT set_config('statement_timeout', ?, true)", [$budgets['statement_ms'].'ms']);
                // ShareUpdateExclusive conflicts with ANALYZE/VACUUM/index builds,
                // but permits ordinary writes. NOWAIT also closes the race between
                // checking progress catalogs and actually starting maintenance.
                $db->statement('LOCK TABLE boards IN SHARE UPDATE EXCLUSIVE MODE NOWAIT');
                $this->analyze($db, $budgets);
            });
            $this->finish($db, $attempt, 'succeeded', evidence: $evidence);

            return ['status' => 'succeeded', 'reason' => $reason, 'attempt' => $attempt];
        } catch (\Throwable $error) {
            $sqlState = (string) $error->getCode();
            $status = match ($sqlState) {
                '55P03' => 'deferred',
                '57014' => 'timeout',
                default => 'failed',
            };
            $this->finish($db, $attempt, $status, mb_substr($error->getMessage(), 0, 1000));

            return ['status' => $status, 'reason' => $reason, 'attempt' => $attempt];
        }
    }

    /** No arbitrary identifier interpolation: the reviewed target is explicit. */
    protected function analyze(Connection $db, array $budgets): void
    {
        $bufferMb = (int) $budgets['buffer_mb'];
        $db->statement("ANALYZE (BUFFER_USAGE_LIMIT '{$bufferMb}MB') boards (boardable_type)");
    }

    /** Slower/smaller hosts get more time, not more memory or simulation lanes. */
    protected function budgets(float $hostGb, int $sharedBuffersBytes): array
    {
        return [
            'statement_ms' => (int) (1000 * max(30, min(120, ceil(120 / sqrt(max(1, $hostGb / 4)))))),
            'buffer_mb' => (int) max(1, min(8, floor($hostGb / 8), floor($sharedBuffersBytes / 1048576 / 64))),
            'lock_ms' => 250,
        ];
    }

    private function statistics(Connection $db): ?object
    {
        $db->selectOne('SELECT pg_stat_clear_snapshot()');

        return $db->selectOne(<<<'SQL'
            SELECT c.oid::bigint AS relation_oid, c.relkind, c.reltuples AS estimated_rows, c.reloptions,
                   s.n_mod_since_analyze AS modifications,
                   s.n_tup_ins + s.n_tup_upd + s.n_tup_del AS total_changes,
                   ds.stats_reset,
                   GREATEST(s.last_analyze, s.last_autoanalyze) AS analyzed_at,
                   COALESCE(p.most_common_vals::text::text[] @> ARRAY['organizations'], false) AS has_organizations,
                   a.attstattarget AS statistics_target,
                   (has_table_privilege(c.oid, 'MAINTAIN') OR pg_has_role(d.datdba, 'USAGE')) AS can_analyze,
                   current_setting('autovacuum') AS autovacuum,
                   current_setting('autovacuum_analyze_threshold') AS default_analyze_threshold,
                   current_setting('autovacuum_analyze_scale_factor') AS default_analyze_scale_factor,
                   pg_size_bytes(current_setting('shared_buffers')) AS shared_buffers_bytes
              FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
              JOIN pg_database d ON d.datname = current_database()
              JOIN pg_stat_database ds ON ds.datid = d.oid
              JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'boardable_type'
              LEFT JOIN pg_stat_all_tables s ON s.relid = c.oid
              LEFT JOIN pg_stats p ON p.schemaname = n.nspname AND p.tablename = c.relname
                                 AND p.attname = a.attname AND NOT p.inherited
             WHERE c.oid = to_regclass('boards')
            SQL);
    }

    private function skip(Connection $db, string $reason, array $evidence = []): array
    {
        $db->table('sim_statistics_maintenance')->where('target', self::TARGET)->update([
            'checked_at' => now(), 'next_check_at' => now()->addSeconds(self::CHECK_SECONDS),
            'reason' => $reason, 'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR)]);

        return ['status' => 'skipped', 'reason' => $reason];
    }

    private function baseline(array $evidence): array
    {
        return array_intersect_key($evidence, array_flip(['relation_oid', 'total_changes', 'stats_reset']));
    }

    private function finish(Connection $db, string $attempt, string $status, ?string $error = null, array $evidence = []): void
    {
        $db->transaction(function () use ($db, $attempt, $status, $error, $evidence) {
            $db->table('sim_statistics_attempts')->where('id', $attempt)->update([
                'status' => $status, 'finished_at' => now(), 'error' => $error]);
            $patch = ['reason' => $status, 'next_attempt_at' => now()->addSeconds(self::COOLDOWN_SECONDS)];
            if ($status === 'succeeded') {
                $patch['last_success_at'] = now();
                $patch['change_baseline'] = json_encode($this->baseline($evidence), JSON_THROW_ON_ERROR);
            }
            $db->table('sim_statistics_maintenance')->where('target', self::TARGET)->update($patch);
        });
        Log::info('Step 5 statistics maintenance', ['target' => self::TARGET,
            'attempt' => $attempt, 'status' => $status, 'error' => $error]);
    }
}
