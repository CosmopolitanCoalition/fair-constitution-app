<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Two-tier cache for the map GeoJSON results. Tier one is the cache store (Redis,
 * emptied on every restart). Tier two is the `geojson_cache` table, which survives
 * a reboot, so a restarted box reads each result back and never recomputes it.
 *
 * Boundary geometry changes only on a map import or a restore. Those paths call
 * flush() beside Cache::flush(). A failure of tier two never blocks a request:
 * the result is then computed and returned as before.
 */
class GeojsonDurableCache
{
    /** @return array<string,mixed> */
    public static function remember(string $key, Closure $build): array
    {
        return Cache::rememberForever($key, function () use ($key, $build): array {
            $stored = self::read($key);
            if ($stored !== null) {
                return $stored;
            }

            $data = $build();
            self::write($key, $data);

            return $data;
        });
    }

    /** Clear the durable tier. Call beside Cache::flush() on import and restore. */
    public static function flush(): void
    {
        try {
            DB::table('geojson_cache')->delete();
        } catch (Throwable) {
            // The table does not exist yet (migration pending): nothing to clear.
        }
    }

    /** @return array<string,mixed>|null */
    private static function read(string $key): ?array
    {
        try {
            $payload = DB::table('geojson_cache')->where('cache_key', $key)->value('payload');
        } catch (Throwable) {
            return null;
        }

        if (! is_string($payload)) {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param  array<string,mixed>  $data */
    private static function write(string $key, array $data): void
    {
        try {
            DB::table('geojson_cache')->upsert(
                [['cache_key' => $key, 'payload' => json_encode($data), 'created_at' => now()]],
                ['cache_key'],
                ['payload', 'created_at'],
            );
        } catch (Throwable) {
            // A failed write only costs a recompute after the next reboot.
        }
    }
}
