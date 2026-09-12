<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\ClockTimer;
use App\Models\Election;
use App\Models\Jurisdiction;
use App\Models\Legislature;
use App\Models\Term;
use App\Services\SettingsResolver;
use App\Support\JurisdictionContext;
use App\Support\SurfaceMeta;
use App\Support\TermSyncCursor;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Public read-only term records. No clock or term is written by this page. */
class TermSyncController extends Controller
{
    private const PAGE_SIZE = 25;
    private const PLACE_COLUMNS = ['id', 'name', 'slug', 'parent_id', 'adm_level'];
    private const LEGISLATURE_COLUMNS = ['id', 'jurisdiction_id', 'term_number', 'status', 'type_b_seats', 'term_starts_on', 'term_ends_on'];

    public function __construct(private readonly SettingsResolver $settings)
    {
    }

    public function show(Request $request): Response
    {
        // Validate every cursor before any query, including place lookup.
        $request->validate(['legislature' => ['nullable', 'uuid']]);
        $cursors = TermSyncCursor::fromRequest($request);
        $place = JurisdictionContext::requested($request);
        $legislature = $request->filled('legislature')
            ? Legislature::query()->findOrFail($request->string('legislature')->toString(), self::LEGISLATURE_COLUMNS)
            : null;
        if ($legislature !== null) {
            abort_if($place !== null && (string) $place->id !== (string) $legislature->jurisdiction_id, 404);
            $place ??= Jurisdiction::query()->findOrFail($legislature->jurisdiction_id, self::PLACE_COLUMNS);
        }

        $props = [
            'surface' => SurfaceMeta::for('system/term-sync'),
            'selectedPlace' => $place ? JurisdictionContext::chip($place) : null,
            'legislature' => null,
            'legislatureChoices' => $this->emptyPage(),
            'lockstepTerms' => $this->emptyPage(),
            'civilTerms' => $this->emptyPage(),
            'refusals' => $this->emptyPage(),
            'appointmentYears' => null,
        ];
        if ($place === null) {
            return Inertia::render('System/TermSync', $props);
        }

        $jid = (string) $place->id;
        $filters = ['jurisdiction' => $place->slug];
        if ($legislature !== null) $filters['legislature'] = (string) $legislature->id;
        $filters += array_filter($request->only(array_keys($cursors)), fn ($value) => $value !== null && $value !== '');
        // Ancestor chips need only labels and IDs, never boundary geometry.
        $parent = $place;
        $seen = [$jid => true];
        for ($depth = 0; $parent->parent_id && $depth < 32; $depth++) {
            if (isset($seen[(string) $parent->parent_id])) break;
            $ancestor = Jurisdiction::query()->find($parent->parent_id, self::PLACE_COLUMNS);
            $parent->setRelation('parent', $ancestor);
            if ($ancestor === null) break;
            $seen[(string) $ancestor->id] = true;
            $parent = $ancestor;
        }
        $parent->setRelation('parent', null);
        $props['jurisdictionContext'] = JurisdictionContext::for($place);

        $choices = Legislature::query()->where('jurisdiction_id', $jid)->select(self::LEGISLATURE_COLUMNS)
            ->orderByDesc('id')->cursorPaginate(self::PAGE_SIZE, cursorName: 'legislatures_cursor', cursor: $cursors['legislatures_cursor']);
        $props['legislatureChoices'] = $this->page($choices, $filters, fn ($row) => [
            'id' => (string) $row->id, 'term_number' => (int) $row->term_number, 'status' => $row->status,
            'href' => '/system/term-sync?'.http_build_query(['jurisdiction' => $place->slug, 'legislature' => (string) $row->id]),
        ]);
        $props['legislature'] = $legislature ? $this->legislatureRow($legislature, $place) : null;
        $props['appointmentYears'] = [
            'civil' => $this->settings->resolveInt($jid, 'civil_appointment_years', 10),
            'judicial' => $this->settings->resolveInt($jid, 'judicial_appointment_years', 10),
        ];

        foreach (['lockstepTerms' => Term::CLASS_LOCKSTEP, 'civilTerms' => Term::CLASS_CIVIL_APPOINTMENT] as $key => $class) {
            $cursorName = $key === 'lockstepTerms' ? 'terms_cursor' : 'appointments_cursor';
            $query = Term::query()->where('jurisdiction_id', $jid)->where('term_class', $class)->active();
            if ($class === Term::CLASS_LOCKSTEP && $legislature !== null) {
                $query->where('legislature_id', (string) $legislature->id);
            }
            $records = $query->select(['id', 'office_kind', 'legislature_id', 'starts_on', 'ends_on', 'source_election_id'])
                ->orderByDesc('id')->cursorPaginate(self::PAGE_SIZE, cursorName: $cursorName, cursor: $cursors[$cursorName]);
            $props[$key] = $this->page($records, $filters, fn ($row) => [
                'id' => (string) $row->id, 'kind' => $row->office_kind,
                'starts_on' => $row->starts_on?->toDateString(), 'ends_on' => $row->ends_on?->toDateString(),
                'election_href' => $row->source_election_id ? '/elections/'.$row->source_election_id : null,
                'legislature_href' => $row->legislature_id ? '/system/term-sync?'.http_build_query(['jurisdiction' => $place->slug, 'legislature' => $row->legislature_id]) : null,
            ]);
        }

        // jurisdiction_id is the recorded association. Do not scan unscoped
        // payloads or claim that missing local rows mean no world violation.
        $refusals = DB::table('audit_log')->where('jurisdiction_id', $jid)->where('rejected', true)
            ->whereIn('ref', ['CLK-01', 'CLK-09', 'CLK-10'])->orderByDesc('seq')
            ->cursorPaginate(self::PAGE_SIZE, ['seq', 'event', 'ref', 'blocked_reason', 'occurred_at'],
                cursorName: 'refusals_cursor', cursor: $cursors['refusals_cursor']);
        $props['refusals'] = $this->page($refusals, $filters, fn ($row) => [
            'attempt' => $row->blocked_reason ?? $row->event, 'citation' => (string) $row->ref,
            'audit_seq' => (int) $row->seq, 'at' => (string) $row->occurred_at,
        ]);

        return Inertia::render('System/TermSync', $props);
    }

    private function legislatureRow(Legislature $legislature, Jurisdiction $place): array
    {
        $timer = ClockTimer::query()->armed()->where('clock_id', 'CLK-01')->where('subject_type', 'legislature')
            ->where('subject_id', (string) $legislature->id)->orderBy('fires_at')->first(['fires_at']);
        $successor = Election::query()->where('legislature_id', $legislature->id)
            ->whereNotIn('status', [Election::STATUS_FINAL, Election::STATUS_CANCELLED])
            ->orderByDesc('created_at')->orderByDesc('id')->first(['id', 'status']);

        return [
            'id' => (string) $legislature->id, 'status' => $legislature->status,
            'name' => $place->name, 'mode' => (int) $legislature->type_b_seats > 0 ? 'bicameral' : 'unicameral',
            'term' => ['starts_on' => $legislature->term_starts_on?->toDateString(), 'ends_on' => $legislature->term_ends_on?->toDateString()],
            'interval_months' => $this->settings->resolveInt((string) $place->id, 'election_interval_months', 60),
            'next_election' => ['clock_due_at' => $timer?->fires_at?->toIso8601String(),
                'election_id' => $successor?->id, 'election_status' => $successor?->status],
            'chamber_href' => '/legislatures/'.$legislature->id.'/chamber',
            'settings_href' => '/legislatures/'.$legislature->id.'/settings',
            'maps_href' => '/legislatures/'.$legislature->id.'/districts',
        ];
    }

    private function page(CursorPaginator $page, array $filters, callable $map): array
    {
        $page->withPath('/system/term-sync')->appends($filters);

        return ['rows' => $page->getCollection()->map($map)->values()->all(),
            'previous' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()];
    }

    private function emptyPage(): array
    {
        return ['rows' => [], 'previous' => null, 'next' => null];
    }
}
