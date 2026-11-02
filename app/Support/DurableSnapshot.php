<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

use function Illuminate\Support\defer;

/**
 * A figure that is expensive to compute and cheap to show: a count or aggregate over a table of
 * millions of rows that a page only DISPLAYS. The value lives in the database (the same
 * geojson_cache table the map layers use), so it survives a restart, which the cache store does
 * not. Reads are one primary-key lookup.
 *
 *   fresh   younger than $freshSeconds: returned as is.
 *   stale   older: returned as is AND one refresh runs after the response (a lock keeps a burst
 *           of visitors to a single refresh), so a visitor never waits for it.
 *   absent  computed inline once, then kept. The first ever view pays it; every later view,
 *           including after a reboot, does not.
 *
 * Use it for display only. A vote threshold, a snapshot of record or an activation must still
 * compute exactly.
 */
final class DurableSnapshot
{
    private function __construct() {}

    public static function remember(string $key, int $freshSeconds, Closure $build): mixed
    {
        $row = self::read($key);

        if ($row !== null) {
            $age = now()->diffInSeconds($row['created_at'], true);
            if ($age > $freshSeconds && Cache::add('snapshot.lock.'.$key, 1, 3600)) {
                defer(fn () => self::refresh($key, $build));
            }

            return $row['value'];
        }

        return self::refresh($key, $build);
    }

    /**
     * Like remember(), but a visitor never waits for the build. A stored figure is returned as
     * is, and a stale one is refreshed by a queued job. When nothing is stored yet the visitor
     * gets $placeholder at once and a queued job builds the figure for the next view.
     *
     * @param  class-string  $builderClass  the class that owns the builder method (resolved from the container)
     */
    public static function rememberQueued(string $key, int $freshSeconds, string $builderClass, string $builderMethod, mixed $placeholder): mixed
    {
        $row = self::read($key);

        if ($row !== null) {
            $age = now()->diffInSeconds($row['created_at'], true);
            if ($age > $freshSeconds) {
                self::queueRefresh($key, $builderClass, $builderMethod);
            }

            return $row['value'];
        }

        self::queueRefresh($key, $builderClass, $builderMethod);

        return $placeholder;
    }

    /** Store a value built elsewhere (the queued refresh job). */
    public static function store(string $key, mixed $value): void
    {
        self::write($key, $value);
    }

    /** Whether a figure is stored for this key. */
    public static function has(string $key): bool
    {
        return self::read($key) !== null;
    }

    private static function queueRefresh(string $key, string $builderClass, string $builderMethod): void
    {
        try {
            \App\Jobs\RefreshDurableSnapshotJob::dispatch($key, $builderClass, $builderMethod);
        } catch (Throwable) {
            // No queue reachable: the next view tries again.
        }
    }

    /** Drop a snapshot, so the next view computes it again. */
    public static function forget(string $key): void
    {
        try {
            DB::table('geojson_cache')->where('cache_key', 'snap.'.$key)->delete();
        } catch (Throwable) {
            // Table not there yet: nothing kept.
        }
    }

    private static function refresh(string $key, Closure $build): mixed
    {
        try {
            $value = $build();
            self::write($key, $value);

            return $value;
        } finally {
            Cache::forget('snapshot.lock.'.$key);
        }
    }

    /** @return array{value: mixed, created_at: \Illuminate\Support\Carbon}|null */
    private static function read(string $key): ?array
    {
        try {
            $row = DB::table('geojson_cache')->where('cache_key', 'snap.'.$key)->first(['payload', 'created_at']);
        } catch (Throwable) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        $value = @unserialize((string) base64_decode((string) $row->payload, true));
        if ($value === false && $row->payload !== base64_encode(serialize(false))) {
            return null;
        }

        return ['value' => $value, 'created_at' => \Illuminate\Support\Carbon::parse($row->created_at)];
    }

    private static function write(string $key, mixed $value): void
    {
        try {
            DB::table('geojson_cache')->upsert(
                [['cache_key' => 'snap.'.$key, 'payload' => base64_encode(serialize($value)), 'legislature_id' => null, 'scope_tag' => null, 'created_at' => now()]],
                ['cache_key'],
                ['payload', 'legislature_id', 'scope_tag', 'created_at'],
            );
        } catch (Throwable) {
            // A failed write only costs a recompute on the next view.
        }
    }
}
