<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\ClockTimer;
use App\Models\Election;
use App\Models\Legislature;
use App\Models\Term;
use App\Services\SettingsResolver;
use App\Support\SurfaceMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-C10 — Term lockstep (PHASE_C_DESIGN_frontend.md §B.16, WF-SYS-01).
 *
 *   GET /system/term-sync   show
 *
 * READ-ONLY BY DESIGN — zero actions; the page's whole point is that
 * there is NO skip/delay/reschedule API. Every number renders from the
 * terms registry and the ARMED CLK-01 timers (clock_timers — the real
 * `due_at`, never a recomputed date).
 */
class TermSyncController extends Controller
{
    public function __construct(private readonly SettingsResolver $settings)
    {
    }

    public function show(Request $request): Response
    {
        return Inertia::render('System/TermSync', [
            'surface'       => SurfaceMeta::for('system/term-sync'),
            'legislatures'  => $this->legislatureRows(),
            'lockstepRoles' => $this->termGroups(Term::CLASS_LOCKSTEP),
            'civilTerms'    => $this->civilTermGroups(),
            'refusals'      => $this->refusalRows(),
        ]);
    }

    // =========================================================================
    // Presentation internals
    // =========================================================================

    private function legislatureRows(): array
    {
        return Legislature::query()
            ->where('status', Legislature::STATUS_ACTIVE)
            ->with('jurisdiction:id,name,slug')
            ->get()
            ->map(function (Legislature $legislature) {
                $jid = (string) $legislature->jurisdiction_id;

                // The ARMED CLK-01 schedule_general timer — the structural
                // guarantee: the next election exists from the moment the
                // prior certifies. Real due_at off clock_timers.
                $timer = ClockTimer::query()
                    ->armed()
                    ->where('clock_id', 'CLK-01')
                    ->where('subject_type', 'legislature')
                    ->where('subject_id', (string) $legislature->id)
                    ->orderBy('fires_at')
                    ->first();

                $successor = Election::query()
                    ->where('legislature_id', $legislature->id)
                    ->whereNotIn('status', [Election::STATUS_FINAL, Election::STATUS_CANCELLED])
                    ->orderByDesc('created_at')
                    ->first(['id', 'status', 'kind']);

                return [
                    'id'           => (string) $legislature->id,
                    'name'         => ($legislature->jurisdiction?->name ?? 'Unknown') . ' legislature',
                    'jurisdiction' => $legislature->jurisdiction?->name,
                    'mode'         => (int) $legislature->type_b_seats > 0 ? 'bicameral' : 'unicameral',
                    'term'         => [
                        'starts_on' => $legislature->term_starts_on?->toDateString(),
                        'ends_on'   => $legislature->term_ends_on?->toDateString(),
                    ],
                    'interval_months' => $this->settings->resolveInt($jid, 'election_interval_months', 60),
                    'next_election'   => [
                        'clock_due_at' => $timer?->fires_at?->toIso8601String(),
                        'election_id'  => $successor?->id !== null ? (string) $successor->id : null,
                        'election_status' => $successor?->status,
                    ],
                    'chamber_href' => "/legislatures/{$legislature->id}/chamber",
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Every term-bearing elected role anchored to its legislature's clock
     * — grouped by office kind + common expiry.
     */
    private function termGroups(string $class): array
    {
        return Term::query()
            ->where('term_class', $class)
            ->where('status', Term::STATUS_ACTIVE)
            ->select('office_kind', 'ends_on', DB::raw('count(*) as n'))
            ->groupBy('office_kind', 'ends_on')
            ->orderBy('office_kind')
            ->orderBy('ends_on')
            ->get()
            ->map(fn ($row) => [
                'kind'    => $row->office_kind,
                'count'   => (int) $row->n,
                'ends_on' => substr((string) $row->ends_on, 0, 10), // date portion — terms are date-grained
            ])
            ->values()
            ->all();
    }

    /** Appointed officers — 10-year CLK-09 clocks, deliberately decoupled. */
    private function civilTermGroups(): array
    {
        return Term::query()
            ->where('term_class', Term::CLASS_CIVIL_APPOINTMENT)
            ->where('status', Term::STATUS_ACTIVE)
            ->select('office_kind', DB::raw('count(*) as n'), DB::raw('min(jurisdiction_id::text) as jid'))
            ->groupBy('office_kind')
            ->orderBy('office_kind')
            ->get()
            ->map(fn ($row) => [
                'kind'  => $row->office_kind,
                'count' => (int) $row->n,
                'years' => $this->settings->resolveInt((string) $row->jid, 'civil_appointment_years', 10),
                'clock' => 'CLK-09',
            ])
            ->values()
            ->all();
    }

    /**
     * Real recorded engine rejections against the lockstep — every
     * attempted skip/delay/extension lands on the chain as rejected=true
     * and renders here verbatim.
     */
    private function refusalRows(): array
    {
        return DB::table('audit_log')
            ->where('rejected', true)
            ->whereIn('ref', ['CLK-01', 'CLK-09', 'CLK-10'])
            ->orderByDesc('seq')
            ->limit(20)
            ->get(['seq', 'event', 'ref', 'blocked_reason', 'occurred_at'])
            ->map(fn ($row) => [
                'attempt'   => $row->blocked_reason ?? $row->event,
                'citation'  => (string) $row->ref,
                'audit_seq' => (int) $row->seq,
                'at'        => (string) $row->occurred_at,
            ])
            ->all();
    }
}
