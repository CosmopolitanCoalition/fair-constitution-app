<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Durable per-worker deltas; the shared run row is updated only when merging. */
final class SimWorldCounters
{
    public const COLUMNS = ['people_founded', 'residencies_founded', 'cohorts',
        'chambers_governed', 'places_zero_population', 'places_too_few_residents'];

    public static function available(): bool
    {
        return Schema::hasTable('sim_world_counter_deltas');
    }

    /** Caller commits this with the item's DONE status. One worker owns this row. */
    public static function record(string $runId, string $token, array $increments): void
    {
        $columns = array_keys(array_intersect_key($increments, array_flip(self::COLUMNS)));
        if ($columns === []) {
            return;
        }
        $names = implode(', ', $columns);
        $values = implode(', ', array_fill(0, count($columns), '?'));
        $updates = implode(', ', array_map(fn ($column) =>
            "{$column} = sim_world_counter_deltas.{$column} + EXCLUDED.{$column}", $columns));
        DB::statement("INSERT INTO sim_world_counter_deltas (run_id, worker_token, {$names})
            VALUES (?, ?, {$values}) ON CONFLICT (run_id, worker_token)
            DO UPDATE SET {$updates}", [$runId, $token, ...array_values(array_intersect_key($increments, array_flip($columns)))]);
    }

    /** Bounded merge, atomic with deletion. A failed merge leaves all deltas intact. */
    public static function flush(string $runId, ?string $token = null): int
    {
        SimTimer::open('lane.counter_flush');
        try {
            return DB::transaction(function () use ($runId, $token): int {
                $rows = DB::table('sim_world_counter_deltas')->where('run_id', $runId)
                    ->when($token !== null, fn ($q) => $q->where('worker_token', $token))
                    ->orderBy('worker_token')->limit($token === null ? HostCapacity::enumerationChunk() : 1)
                    ->lock('FOR UPDATE SKIP LOCKED')->get();
                if ($rows->isEmpty()) {
                    return 0;
                }
                $increments = [];
                foreach (self::COLUMNS as $column) {
                    $sum = (int) $rows->sum($column);
                    if ($sum !== 0) { $increments[$column] = $sum; }
                }
                DB::table('sim_runs')->where('id', $runId)->incrementEach($increments, ['updated_at' => now()]);
                DB::table('sim_world_counter_deltas')->where('run_id', $runId)
                    ->whereIn('worker_token', $rows->pluck('worker_token'))->delete();
                return $rows->count();
            });
        } finally {
            SimTimer::close('lane.counter_flush');
        }
    }
}
