<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\Clock;
use App\Models\ClockTimer;
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
        ]);
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
