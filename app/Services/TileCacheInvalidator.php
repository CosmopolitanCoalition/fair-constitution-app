<?php

namespace App\Services;

use App\Jobs\PrewarmRasterTilesJob;
use App\Support\TileCoordMath;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Phase T.4 — tile-cache invalidation surface for the future map editor.
 *
 * The viewer's raster tiles live at
 * `storage/app/tile-cache/{z}/{x}/{y}.png`. They're a server-side disk
 * cache: a single warmed file serves every visitor. When the map editor
 * (a separate phase) saves a geometry change to a jurisdiction, the
 * cached tiles whose bbox intersects (old ∪ new) become stale. Two
 * options:
 *
 *   - Wipe the whole cache and re-warm everything (slow, days).
 *   - Targeted invalidation: delete only the affected tile files, then
 *     dispatch a queue job that regenerates JUST those tiles
 *     (minutes).
 *
 * This service ships the second path. It exposes two methods the editor
 * will call after each save:
 *
 *   $inv->invalidateBbox(...)      → delete cached PNGs whose tile bbox
 *                                      intersects the edit's bbox
 *   $inv->dispatchPartialRewarm()  → write the affected tile keys to a
 *                                      JSON file under storage/app/tile-keys/
 *                                      and dispatch PrewarmRasterTilesJob
 *                                      with --tile-keys=<that-path>; the
 *                                      Horizon long-running worker
 *                                      regenerates exactly that set
 *
 * Typical usage from the editor (post-save):
 *
 *     $keys = $inv->invalidateBbox(34.0, -119.0, 35.0, -118.0);
 *     $inv->dispatchPartialRewarm($keys);
 *
 * The two are split rather than a single "invalidate-and-rewarm" so the
 * editor can choose:
 *   - Bulk save: invalidate many bboxes, accumulate keys, dispatch once.
 *   - Test/verify: invalidate without dispatch to manually confirm
 *     cache state before re-warm.
 *
 * Slight over-invalidation is preferable to under: every tile whose
 * bbox even brushes the input bbox gets deleted (TileCoordMath::bboxToTileKeys
 * is inclusive). A few extra tiles regenerate vs. stale tiles served
 * after a save is the right trade.
 */
final class TileCacheInvalidator
{
    /** Tile cache root, relative to storage/app/. */
    private const TILE_CACHE_DIR = 'tile-cache';

    /** Where partial-rewarm key-lists land (one JSON file per dispatch). */
    private const TILE_KEYS_DIR  = 'tile-keys';

    /**
     * Delete cached tile PNGs whose tile bbox intersects the given
     * EPSG:4326 bbox, across [minZ, maxZ]. Returns the list of tile keys
     * (in "z/x/y" form) that the operation considered — whether or not
     * the file actually existed on disk. The caller passes this list to
     * dispatchPartialRewarm() when ready.
     *
     * Defaults to z=0..12 (the full pre-warm zoom range). Editors that
     * know the edit only affects high-zoom detail (e.g. a tiny
     * municipal-boundary tweak) can narrow the zoom range to save work.
     *
     * @param  float  $south   Minimum latitude (degrees), inclusive
     * @param  float  $west    Minimum longitude (degrees), inclusive
     * @param  float  $north   Maximum latitude (degrees), inclusive
     * @param  float  $east    Maximum longitude (degrees), inclusive
     * @return list<string>    The set of "z/x/y" keys touched by the edit.
     */
    public function invalidateBbox(
        float $south, float $west,
        float $north, float $east,
        int   $minZ = 0, int $maxZ = 12,
    ): array {
        $keyMap = TileCoordMath::bboxToTileKeys($south, $west, $north, $east, $minZ, $maxZ);
        $keys   = array_keys($keyMap);

        $deleted = 0;
        $missing = 0;
        foreach ($keys as $key) {
            $path = storage_path('app/' . self::TILE_CACHE_DIR . "/{$key}.png");
            if (is_file($path)) {
                if (@unlink($path)) {
                    $deleted++;
                } else {
                    Log::warning('TileCacheInvalidator: failed to unlink cached tile', [
                        'path' => $path,
                    ]);
                }
            } else {
                $missing++;
            }
        }

        Log::info('TileCacheInvalidator::invalidateBbox', [
            'bbox'     => compact('south', 'west', 'north', 'east'),
            'zoom'     => "{$minZ}-{$maxZ}",
            'keys'     => count($keys),
            'deleted'  => $deleted,
            'missing'  => $missing,
        ]);

        return $keys;
    }

    /**
     * Write the given tile-key list to a JSON file under storage/app/tile-keys/
     * and dispatch PrewarmRasterTilesJob with --tile-keys=<that-path>. The
     * Horizon long-running worker picks it up and regenerates exactly that
     * set, using the existing rasters:prewarm command's --tile-keys mode.
     *
     * The JSON file persists past the job's lifetime so a failed-job retry
     * can re-read it. Cleanup is operator-driven — the directory can be
     * pruned periodically once dispatched jobs are confirmed complete.
     *
     * @param  list<string>  $tileKeys  e.g. ['8/127/85', '8/128/85', ...]
     * @return string                   Absolute path of the tile-keys JSON
     *                                  file (so the caller can confirm or
     *                                  inspect it).
     */
    public function dispatchPartialRewarm(array $tileKeys): string
    {
        if ($tileKeys === []) {
            // No-op dispatch — log and return without queueing.
            Log::info('TileCacheInvalidator::dispatchPartialRewarm noop (empty key list)');
            return '';
        }

        $dir = storage_path('app/' . self::TILE_KEYS_DIR);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $filename = sprintf('%s-%s.json',
            now()->format('Ymd-His'),
            (string) Str::uuid()
        );
        $absPath = $dir . '/' . $filename;

        $payload = array_values($tileKeys);  // re-index for clean JSON array
        $written = @file_put_contents(
            $absPath,
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );
        if ($written === false) {
            // Defensive — without the file, the job can't do anything
            // useful, so surface this loudly to the caller.
            throw new \RuntimeException(
                "TileCacheInvalidator: failed to write tile-keys file at {$absPath}"
            );
        }

        PrewarmRasterTilesJob::dispatch(
            // min/max zoom irrelevant in tile-keys mode (the keys ARE the
            // target); pass through harmless defaults so the constructor
            // doesn't complain.
            minZoom:      0,
            maxZoom:      12,
            landOnly:     false,
            tileKeysFile: $absPath,
        );

        Log::info('TileCacheInvalidator::dispatchPartialRewarm', [
            'tile_keys_file' => $absPath,
            'key_count'      => count($payload),
        ]);

        return $absPath;
    }
}
