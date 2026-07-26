<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Middleware\DevTimeControlsEnabled;
use App\Models\Clock;
use App\Models\ClockTimer;
use App\Services\Dev\DevClockService;
use App\Support\SurfaceMeta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * mockups-v3-wiring Phase 2 — /system/clocks (design contract:
 * mockups/v3/shared/clocks.html).
 *
 *   GET /system/clocks   show
 *
 * READ-ONLY BY DESIGN — zero actions. The clocks registry is reference
 * data (definitions change via ClockRegistrySeeder, never at runtime);
 * this page doubles as the scheduler spec — the 21 canonical records the
 * production scheduler implements, one row, one trigger source — plus a
 * LIVE column showing what the scheduler is actually holding right now
 * (armed clock_timers per clock, real fires_at, never recomputed dates).
 */
class ClocksController extends Controller
{
    public function show(): Response
    {
        $clocks = Clock::query()->orderBy('id')->get();

        return Inertia::render('System/Clocks', [
            'surface' => SurfaceMeta::for('system/clocks'),
            'clocks'  => $clocks->map(fn (Clock $clock) => [
                'id'             => $clock->id,
                'name'           => $clock->name,
                'type'           => $clock->type,
                'default_value'  => $clock->default_value,
                'amendable'      => (bool) $clock->amendable,
                'fires_workflow' => $clock->fires_workflow,
                'basis'          => $clock->basis,
            ])->values()->all(),
            'armed'   => $this->armedByClock(),
            'stats'   => [
                'total'     => $clocks->count(),
                'amendable' => $clocks->where('amendable', true)->count(),
                'hardened'  => $clocks->where('amendable', false)->count(),
            ],
            'dueNow'  => $this->dueNow(),
        ] + $this->playtestPreview());
    }

    /**
     * How many armed timers are ALREADY past their deadline, per clock.
     *
     * Normally zero: the minute sweep fires them. A number that sits here and
     * does not clear means the sweep is not running or a handler keeps
     * refusing, which is worth seeing on the page rather than discovering
     * later.
     *
     * @return array<string, int>
     */
    private function dueNow(): array
    {
        return DB::table('clock_timers')
            ->where('state', ClockTimer::STATE_ARMED)
            ->whereNull('deleted_at')
            ->whereNotNull('fires_at')
            ->where('fires_at', '<=', now())
            ->selectRaw('clock_id, count(*) as due')
            ->groupBy('clock_id')
            ->pluck('due', 'clock_id')
            ->map(static fn ($n): int => (int) $n)
            ->all();
    }

    /**
     * P3 — "what would fire if I advanced N days", on screen.
     *
     * The same dry run the console command prints, so the operator can look
     * before he touches anything. Present ONLY when the playtest controls are
     * actually enabled; on any normal instance this page stays exactly the
     * read-only view it already was, with no hint that time travel exists.
     *
     * @return array<string,mixed>
     */
    private function playtestPreview(): array
    {
        if (DevTimeControlsEnabled::refusalReason() !== null) {
            return ['playtest' => null];
        }

        $days = (int) request()->integer('advance', 0);

        return [
            'playtest' => [
                'enabled' => true,
                'days'    => $days,
                'preview' => $days > 0
                    ? app(DevClockService::class)->dryRun($days)
                    : null,
            ],
        ];
    }

    /**
     * One grouped sweep over the live scheduler state: per clock, how many
     * timers are armed right now and the soonest real deadline. NULL
     * fires_at = threshold-watches (no deadline; the sweep evaluates the
     * watched quantity directly) — SQL min() skips them by definition.
     *
     * @return array<string, array{count: int, next_fires_at: string|null}>
     */
    private function armedByClock(): array
    {
        return ClockTimer::query()
            ->armed()
            ->groupBy('clock_id')
            ->get([
                'clock_id',
                DB::raw('count(*) as n'),
                DB::raw('min(fires_at) as next_fires_at'),
            ])
            ->mapWithKeys(fn ($row) => [
                (string) $row->clock_id => [
                    'count'         => (int) $row->n,
                    'next_fires_at' => $row->next_fires_at !== null
                        ? Carbon::parse($row->next_fires_at)->toIso8601String()
                        : null,
                ],
            ])
            ->all();
    }
}
