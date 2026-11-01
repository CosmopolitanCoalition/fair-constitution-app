<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Two-tier cache for the map GeoJSON results and the district color map. Tier one
 * is the cache store (Redis, emptied on every restart). Tier two is the
 * `geojson_cache` table, which survives a reboot, so a restarted box reads each
 * result back and never recomputes it.
 *
 * The data changes only when the map changes (import, restore) or when a district
 * is drawn or edited. Those paths flush through this class, which clears both
 * tiers. A failure of tier two never blocks a request: the result is then
 * computed and returned as before.
 *
 * Revealed-district entries carry a legislature id and a scope tag, so the flush
 * is exactly as narrow as the Redis tags it mirrors.
 */
class GeojsonDurableCache
{
    /** Map results keyed by jurisdiction and zoom (no tags). */
    public static function remember(string $key, Closure $build): array
    {
        return Cache::rememberForever($key, fn (): array => self::fill($key, $build));
    }

    /**
     * District results. $tags are the Redis tags. $legislatureId and $scopeTag are the
     * durable tier's flush keys, the same two the Redis tags express.
     *
     * @param  array<int,string>  $tags
     */
    public static function rememberTagged(string $key, array $tags, ?string $legislatureId, ?string $scopeTag, Closure $build): array
    {
        return Cache::tags($tags)->rememberForever(
            $key,
            fn (): array => self::fill($key, $build, $legislatureId, $scopeTag),
        );
    }

    /**
     * Store an already-computed district result in both tiers (the color map patched
     * in place after a draw).
     *
     * @param  array<int,string>  $tags
     * @param  array<string,mixed>  $data
     */
    public static function putTagged(string $key, array $tags, ?string $legislatureId, ?string $scopeTag, array $data): void
    {
        Cache::tags($tags)->forever($key, $data);
        self::write($key, $data, $legislatureId, $scopeTag);
    }

    /**
     * Read a district result from either tier WITHOUT computing it. Null when neither tier has it.
     * A durable hit re-primes the cache store, so the next read is one Redis call.
     *
     * @param  array<int,string>  $tags
     * @return array<string,mixed>|null
     */
    public static function peekTagged(string $key, array $tags): ?array
    {
        $hot = Cache::tags($tags)->get($key);
        if (is_array($hot)) {
            return $hot;
        }

        $stored = self::read($key);
        if ($stored !== null) {
            Cache::tags($tags)->forever($key, $stored);
        }

        return $stored;
    }

    /** Clear one legislature's district results in both tiers (draw, edit, redraw). */
    public static function flushLegislature(string $legislatureId): void
    {
        Cache::tags(["revealed.{$legislatureId}"])->flush();
        self::deleteWhere('legislature_id', $legislatureId);
    }

    /** Clear one scope's district results in both tiers (the per-ancestor flush). */
    public static function flushScope(string $legislatureId, string $mapKey, string $scopeId): void
    {
        $tag = "revealed.{$legislatureId}.{$mapKey}.{$scopeId}";
        Cache::tags([$tag])->flush();
        self::deleteWhere('scope_tag', $tag);
    }

    /** Clear the whole durable tier. Call beside Cache::flush() on import and restore. */
    public static function flush(): void
    {
        try {
            DB::table('geojson_cache')->delete();
        } catch (Throwable) {
            // The table does not exist yet (migration pending): nothing to clear.
        }
    }

    private static function fill(string $key, Closure $build, ?string $legislatureId = null, ?string $scopeTag = null): array
    {
        $stored = self::read($key);
        if ($stored !== null) {
            return $stored;
        }

        $data = $build();
        self::write($key, $data, $legislatureId, $scopeTag);

        return $data;
    }

    private static function deleteWhere(string $column, string $value): void
    {
        try {
            DB::table('geojson_cache')->where($column, $value)->delete();
        } catch (Throwable) {
            // Table or column not present yet: nothing durable to clear.
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
    private static function write(string $key, array $data, ?string $legislatureId = null, ?string $scopeTag = null): void
    {
        try {
            DB::table('geojson_cache')->upsert(
                [[
                    'cache_key'      => $key,
                    'payload'        => json_encode($data),
                    'legislature_id' => $legislatureId,
                    'scope_tag'      => $scopeTag,
                    'created_at'     => now(),
                ]],
                ['cache_key'],
                ['payload', 'legislature_id', 'scope_tag', 'created_at'],
            );
        } catch (Throwable) {
            // A failed write only costs a recompute after the next reboot.
        }
    }
}
