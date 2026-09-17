<?php

namespace App\Services\Maps;

use App\Http\Controllers\JurisdictionController;
use App\Http\Controllers\LegislatureController;
use App\Models\Jurisdiction;
use App\Services\ConstitutionalDefaults;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * GeojsonPrewarmPlanner — the bounded GeoJSON prewarm (WoS beta, 2026-09-17).
 *
 * The boot prewarm used to be ONE job that built every scope's boundary and
 * revealed payloads in one worker; the Earth revealed payload alone is a
 * 1.2 GB process (3.5 k member rows with geometry text, buffered twice: libpq
 * and PHP), and that worker was OOM-killed at every boot, silently, taking the
 * raster prewarm down with it. The ETL paradigm applies to a prewarm too:
 *
 *   chunkable   one UNIT = one legislature x scope x zoom, one job each
 *   visible     a cache ledger records every unit dispatched / started / done;
 *               `geojson:prewarm --status` lists units that started and never
 *               finished as LOST (a killed worker cannot report)
 *   ordered     the root scope first (the mapper's first screen), then the
 *               giants in registry order; zooms ascending within a scope
 *   bounded     the plan covers the ROOT legislatures only (a jurisdiction
 *               with no parent: Earth on a planet box, the country on a
 *               country box) and the giants drillable from each root, which
 *               is what the mapper opens. The old command looped EVERY
 *               legislature: on a planet that is 940,327 rows fetched at once
 *               (the 1.2 GB process the WoS beta killed 3 s after each boot,
 *               before any scope was touched) followed by a query per row.
 *
 * The unit builder is exactly what the command did inline: the three
 * JurisdictionController boundary calls plus LegislatureController::revealedGeoJson,
 * whose rememberForever caches fill as a side effect. NOTHING here reads,
 * counts or changes geometry: the map queries are the controllers' own.
 */
class GeojsonPrewarmPlanner
{
    public const LEDGER = 'cga:prewarm:geojson:run';

    public const UNIT_PREFIX = 'cga:prewarm:geojson:unit:';

    /** Ledger entries live a week; the next boot's plan replaces them. */
    private const TTL = 7 * 86400;

    /** A unit that started this long ago and never finished is reported lost. */
    public const LOST_AFTER_SECONDS = 900;

    /**
     * Every unit to warm: legislature x (root + drillable giants) x zoom, the
     * root scope first, then the giants in the order the registry returns them.
     *
     * @param  list<int>  $zooms
     * @return list<array{leg: string, scope: string, zoom: int}>
     */
    public function plan(array $zooms, ?array $legislatureIds = null): array
    {
        $query = DB::table('legislatures as l')
            ->join('jurisdictions as r', 'r.id', '=', 'l.jurisdiction_id')
            ->whereNull('l.deleted_at')
            ->whereNull('r.deleted_at');
        if (is_array($legislatureIds) && $legislatureIds !== []) {
            $query->whereIn('l.id', $legislatureIds);
        } else {
            $query->whereNull('r.parent_id'); // the root legislatures: bounded by construction
        }
        $legislatures = $query->limit(64)->get(['l.id', 'l.jurisdiction_id', 'l.type_a_seats']);
        $units = [];

        foreach ($legislatures as $leg) {
            $rootId = (string) $leg->jurisdiction_id;
            $rootPop = \App\Services\Districting\LeafGiantResolver::shareBase($rootId);
            $seats = (int) $leg->type_a_seats;
            $thr = ConstitutionalDefaults::giantThreshold($rootId);

            $giantRows = DB::select(
                "WITH RECURSIVE giant_tree AS (
                     SELECT j.id
                     FROM jurisdictions j
                     WHERE j.parent_id = :root
                       AND j.deleted_at IS NULL
                       AND (CAST(j.population AS numeric) * :seats1 / :rootpop1) >= :thr1
                     UNION ALL
                     SELECT j.id
                     FROM jurisdictions j
                     JOIN giant_tree gt ON j.parent_id = gt.id
                     WHERE j.deleted_at IS NULL
                       AND (CAST(j.population AS numeric) * :seats2 / :rootpop2) >= :thr2
                 )
                 SELECT gt.id
                 FROM giant_tree gt
                 WHERE EXISTS (
                     SELECT 1 FROM jurisdictions c
                     WHERE c.parent_id = gt.id AND c.deleted_at IS NULL
                 )",
                [
                    'root'   => $rootId,
                    'seats1' => $seats, 'rootpop1' => $rootPop, 'thr1' => $thr,
                    'seats2' => $seats, 'rootpop2' => $rootPop, 'thr2' => $thr,
                ]
            );

            $scopeIds = array_values(array_unique(array_merge(
                [$rootId],
                array_map(static fn ($r) => (string) $r->id, $giantRows)
            )));

            foreach ($scopeIds as $sid) {
                foreach ($zooms as $z) {
                    $units[] = ['leg' => (string) $leg->id, 'scope' => $sid, 'zoom' => (int) $z];
                }
            }
        }

        return $units;
    }

    /**
     * Build one unit: the three boundary payloads plus the revealed payload
     * for one scope at one zoom. Returns [built, failed] and the messages.
     *
     * @return array{built: int, failed: int, messages: list<string>}
     */
    public function build(string $legislatureId, string $scopeId, int $zoom): array
    {
        $jur = Jurisdiction::find($scopeId);
        if (! $jur) {
            return ['built' => 0, 'failed' => 1, 'messages' => ["scope {$scopeId} not found"]];
        }

        $jurisdictionCtl = app(JurisdictionController::class);
        $legislatureCtl = app(LegislatureController::class);
        $built = 0;
        $failed = 0;
        $messages = [];

        foreach (['childrenGeoJson', 'selfGeoJson', 'siblingsGeoJson'] as $method) {
            try {
                $jurisdictionCtl->{$method}(Request::create("/warm?zoom={$zoom}", 'GET'), $jur);
                $built++;
            } catch (\Throwable $e) {
                $failed++;
                $messages[] = "{$method} {$scopeId} z{$zoom}: {$e->getMessage()}";
            }
        }

        try {
            $legislatureCtl->revealedGeoJson(Request::create("/warm?scope={$scopeId}&zoom={$zoom}", 'GET'), $legislatureId);
            $built++;
        } catch (\Throwable $e) {
            $failed++;
            $messages[] = "revealedGeoJson {$scopeId} z{$zoom}: {$e->getMessage()}";
        }

        return ['built' => $built, 'failed' => $failed, 'messages' => $messages];
    }

    // ── ledger ─────────────────────────────────────────────────────────────

    public static function unitKey(string $legislatureId, string $scopeId, int $zoom): string
    {
        return "{$legislatureId}:{$scopeId}:z{$zoom}";
    }

    /** Record a new plan (replaces the previous ledger). */
    public function recordPlan(array $units): void
    {
        $keys = array_map(static fn (array $u) => self::unitKey($u['leg'], $u['scope'], $u['zoom']), $units);
        foreach ($keys as $k) {
            Cache::forget(self::UNIT_PREFIX.$k);
        }
        Cache::put(self::LEDGER, [
            'planned_at' => now()->toIso8601String(),
            'units'      => $keys,
        ], self::TTL);
    }

    public function mark(string $unitKey, string $state, array $extra = []): void
    {
        $row = Cache::get(self::UNIT_PREFIX.$unitKey, []);
        $row[$state] = now()->toIso8601String();
        $row = array_merge($row, $extra);
        Cache::put(self::UNIT_PREFIX.$unitKey, $row, self::TTL);
    }

    /**
     * The ledger read back and classified. A unit that `started` more than
     * LOST_AFTER_SECONDS ago with no `done` or `failed` is LOST: its worker
     * was killed and could not report.
     *
     * @return array{planned_at: string|null, counts: array<string, int>, lost: list<string>, failed: list<string>}
     */
    public function status(?int $now = null): array
    {
        $ledger = Cache::get(self::LEDGER);
        $now ??= time();
        $out = ['planned_at' => null, 'counts' => ['planned' => 0, 'pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'lost' => 0], 'lost' => [], 'failed' => []];
        if (! is_array($ledger)) {
            return $out;
        }
        $out['planned_at'] = $ledger['planned_at'] ?? null;
        foreach ((array) ($ledger['units'] ?? []) as $k) {
            $out['counts']['planned']++;
            $row = Cache::get(self::UNIT_PREFIX.$k, []);
            if (isset($row['done'])) {
                $out['counts']['done']++;
            } elseif (isset($row['failed'])) {
                $out['counts']['failed']++;
                $out['failed'][] = $k;
            } elseif (isset($row['started'])) {
                $age = $now - strtotime($row['started']);
                if ($age > self::LOST_AFTER_SECONDS) {
                    $out['counts']['lost']++;
                    $out['lost'][] = $k;
                } else {
                    $out['counts']['running']++;
                }
            } else {
                $out['counts']['pending']++;
            }
        }

        return $out;
    }
}
