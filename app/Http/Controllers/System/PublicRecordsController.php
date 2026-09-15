<?php

namespace App\Http\Controllers\System;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\ChamberVote;
use App\Models\Jurisdiction;
use App\Models\LegislatureMember;
use App\Models\LegislatureSession;
use App\Models\PublicRecord;
use App\Models\User;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-C11 — Public records (PHASE_C_DESIGN_frontend.md §B.15/§D).
 *
 *   GET  /system/public-records             index (cursor-paginated; filter query params)
 *   POST /system/public-records/statements  statement  F-LEG-006
 *
 * The CURATED, citizen-readable register (public_records — append-only,
 * trigger-enforced), distinct from the raw audit chain: corrections
 * APPEND superseding entries; every record carries `audit_seq` ("sealed
 * into the audit chain at commit" → /system/audit-chain?seq=N). The page
 * is a READER — it owns only the F-LEG-006 statement composer (the same
 * handler the SessionConsole composer files).
 */
class PublicRecordsController extends Controller
{
    private const PAGE_SIZE = 30;

    public function __construct(private readonly ConstitutionalEngine $engine)
    {
    }

    public function index(Request $request): Response
    {
        $q             = trim((string) $request->query('q', ''));
        $kinds         = array_values(array_filter((array) $request->query('kinds', [])));
        $legislatureId = (string) $request->query('legislature', '');

        $page = PublicRecord::query()
            ->when($kinds !== [], fn ($query) => $query->whereIn('kind', $kinds))
            ->when($legislatureId !== '', fn ($query) => $query->where('legislature_id', $legislatureId))
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                $w->where('title', 'ilike', "%{$q}%")
                    ->orWhere('actor_display', 'ilike', "%{$q}%");
            }))
            ->orderByDesc('seq')
            ->cursorPaginate(self::PAGE_SIZE)
            ->withQueryString();

        $records = collect($page->items());

        // Batch-resolve display lookups for the page (no N+1).
        $jurisdictions = Jurisdiction::query()
            ->whereIn('id', $records->pluck('jurisdiction_id')->filter()->unique())
            ->pluck('name', 'id');

        $actors = User::query()
            ->whereIn('id', $records->pluck('actor_user_id')->filter()->unique())
            ->get(['id', 'name', 'display_name'])
            ->keyBy('id');

        $supersededSeqs = PublicRecord::query()
            ->whereIn('id', $records->pluck('supersedes_record_id')->filter()->unique())
            ->pluck('seq', 'id');

        $viewerChambers = $this->viewerChambers($request->user());

        return Inertia::render('System/PublicRecords', [
            'surface' => SurfaceMeta::for('system/public-records'),
            'records' => [
                'data' => $records
                    ->map(fn (PublicRecord $record) => $this->row($record, $jurisdictions, $actors, $supersededSeqs))
                    ->values()
                    ->all(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'prev_cursor' => $page->previousCursor()?->encode(),
            ],
            'filters' => [
                'modules'      => [], // record rows carry via-ids, not modules — module facet deferred with the Phase F pipeline
                'legislatures' => $this->legislatureFacet($legislatureId),
                'kinds'  => PublicRecord::KINDS,
                'active' => ['q' => $q, 'kinds' => $kinds, 'legislature' => $legislatureId],
            ],
            'stats' => $this->stats(),
            'composer' => [
                // R-09 only — the engine enforces; the page hides the
                // composer entirely for non-members (§B.15).
                'legislatures' => $viewerChambers,
                'subjects'     => $viewerChambers !== [] ? $this->subjectOptions($viewerChambers[0]['id']) : [],
            ],
            'can' => [
                'statement' => $viewerChambers !== [],
            ],
            'urls' => [
                'statement' => '/system/public-records/statements',
            ],
        ]);
    }

    /**
     * The legislature filter option list: the active legislature only.
     *
     * W-0437: the facet used to join every legislature on the box (940,328 rows
     * on a planet) to jurisdictions and sort them by name on every page load,
     * one planet-wide statement inside a web request (the ETL paradigm's first
     * corollary). PHP-FPM died at about 70 s and nginx answered 502. Listing
     * the chambers that hold a record is no better: 923,075 of them do. The
     * filter is reached by id from a chamber page (?legislature=<id>), so the
     * page needs one name, for the active id, or nothing. A typed name search
     * needs an index on jurisdictions.name and is a separate item.
     *
     * @return list<array{id: string, name: string}>
     */
    private function legislatureFacet(string $legislatureId): array
    {
        if ($legislatureId === '' || ! \Illuminate\Support\Str::isUuid($legislatureId)) {
            return [];
        }

        $row = DB::table('legislatures as l')
            ->join('jurisdictions as j', 'j.id', '=', 'l.jurisdiction_id')
            ->where('l.id', $legislatureId)
            ->whereNull('l.deleted_at')
            ->first(['l.id', 'j.name']);

        return $row === null ? [] : [['id' => (string) $row->id, 'name' => __(':name legislature', ['name' => $row->name])]];
    }

    /**
     * GET /api/public-records/legislatures?q=<prefix>
     *
     * W-0440: the typed legislature search behind the filter. Bounded twice:
     * a name prefix of at least two characters, matched through the
     * lower(name) text_pattern_ops index (migration 2026_09_14_213000), and
     * at most 20 rows. Public read, like the page.
     */
    public function legislatureSearch(Request $request): \Illuminate\Http\JsonResponse
    {
        $q = mb_strtolower(trim((string) $request->query('q', '')));

        if (mb_strlen($q) < 2) {
            return response()->json(['options' => []]);
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);

        $options = DB::table('legislatures as l')
            ->join('jurisdictions as j', 'j.id', '=', 'l.jurisdiction_id')
            ->whereNull('l.deleted_at')
            ->whereRaw('lower(j.name) LIKE ?', [$escaped . '%'])
            ->orderBy('j.adm_level')
            ->orderBy('j.name')
            ->limit(20)
            ->get(['l.id', 'j.name', 'j.adm_level'])
            ->map(fn ($row) => ['id' => (string) $row->id, 'name' => __(':name legislature', ['name' => $row->name]), 'adm_level' => (int) $row->adm_level])
            ->all();

        return response()->json(['options' => $options]);
    }

    /**
     * Register statistics, bounded.
     *
     * The total is the sealed sequence high-water mark (public_records is
     * append-only and seq is its primary key), one index read. The per-kind
     * counts each walk an index over millions of rows (2 to 6 s on 16.7 million
     * records), so they are computed at most once per window and served from
     * the cache in between; the window is the only thing the page pays for.
     *
     * @return array{total: int, acts: int, votes: int, statements: int}
     */
    private function stats(): array
    {
        $total = (int) (PublicRecord::query()->max('seq') ?? 0);

        $byKind = \Illuminate\Support\Facades\Cache::remember(
            'public_records.stats.by_kind',
            now()->addMinutes(15),
            fn () => [
                'acts'       => PublicRecord::query()->where('kind', 'act')->count(),
                'votes'      => PublicRecord::query()->where('kind', 'vote')->count(),
                'statements' => PublicRecord::query()->where('kind', 'statement')->count(),
            ],
        );

        return ['total' => $total] + $byKind;
    }

    /** F-LEG-006 — Public Record Statement (the SessionConsole handler, reused). */
    public function statement(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'legislature_id' => ['required', 'uuid'],
            'body'           => ['required', 'string', 'max:20000'],
            'title'          => ['nullable', 'string', 'max:255'],
            'subject_type'   => ['nullable', 'string', 'in:bill,session,vote'],
            'subject_id'     => ['nullable', 'uuid', 'required_with:subject_type'],
        ]);

        $jurisdictionId = DB::table('legislatures')
            ->where('id', $validated['legislature_id'])
            ->value('jurisdiction_id');

        $result = $this->engine->file('F-LEG-006', $request->user(), [
            'legislature_id'  => $validated['legislature_id'],
            'jurisdiction_id' => $jurisdictionId !== null ? (string) $jurisdictionId : null,
            'body'            => $validated['body'],
            'title'           => $validated['title'] ?? null,
            'subject_type'    => $validated['subject_type'] ?? null,
            'subject_id'      => $validated['subject_id'] ?? null,
        ]);

        $seq = $result->recorded['record_seq'] ?? null;

        return back()->with(
            'status',
            $seq !== null
                ? __('Statement entered verbatim into the immutable public record (F-LEG-006 · WF-SYS-03) — record #:seq, sealed into the audit chain at commit.', ['seq' => $seq])
                : __('Statement entered verbatim into the immutable public record (F-LEG-006 · WF-SYS-03).')
        );
    }

    // =========================================================================
    // Presentation internals
    // =========================================================================

    private function row(PublicRecord $record, $jurisdictions, $actors, $supersededSeqs): array
    {
        $actor = $record->actor_display;

        if ($actor === null && $record->actor_user_id !== null) {
            $user  = $actors->get((string) $record->actor_user_id);
            $actor = $user?->display_name ?: $user?->name;
        }

        $translations = (array) ($record->translations ?? []);
        $locales      = [];

        foreach ($translations as $code => $state) {
            if (is_string($code)) {
                $locales[] = ['code' => $code, 'quality' => is_array($state) ? ($state['quality'] ?? 'machine') : (string) $state];
            }
        }

        $supersedesSeq = $record->supersedes_record_id !== null
            ? $supersededSeqs->get((string) $record->supersedes_record_id)
            : null;

        return [
            'seq'          => (int) $record->seq,
            'kind'         => $record->kind,
            'title'        => $record->title,
            'body_excerpt' => $record->body !== null ? mb_strimwidth($record->body, 0, 220, '…') : null,
            'actor_display' => $actor ?? __('Constitutional Engine'),
            'jurisdiction'  => $record->jurisdiction_id !== null
                ? ['name' => $jurisdictions->get((string) $record->jurisdiction_id)]
                : null,
            'via' => [
                'form'     => $record->via_form,
                'workflow' => $record->via_workflow,
                'clock'    => $record->via_clock,
            ],
            'published_at' => $record->published_at?->toIso8601String(),
            'audit_seq'    => $record->audit_seq !== null ? (int) $record->audit_seq : null,
            'translations' => [
                'done'    => count(array_filter($locales, fn ($l) => $l['quality'] !== 'pending')),
                'total'   => count($locales),
                'locales' => $locales,
            ],
            'supersedes' => $supersedesSeq !== null
                ? ['seq' => (int) $supersedesSeq, 'href' => '/system/public-records?q=' . urlencode($record->title)]
                : null,
            'subject' => $this->subjectLink($record),
        ];
    }

    /** Known subject types → in-app links; everything else labels only. */
    private function subjectLink(PublicRecord $record): ?array
    {
        if ($record->subject_type === null || $record->subject_id === null) {
            return null;
        }

        $href = match ($record->subject_type) {
            'bill', 'bills'              => "/bills/{$record->subject_id}",
            'petition', 'petitions'      => "/civic/petitions/{$record->subject_id}",
            'election', 'elections'      => "/elections/{$record->subject_id}",
            'emergency_power'            => $record->legislature_id !== null
                ? "/legislatures/{$record->legislature_id}/emergency-powers"
                : null,
            'referendum_question'        => $record->legislature_id !== null
                ? "/legislatures/{$record->legislature_id}/referendums"
                : null,
            default                      => null,
        };

        return [
            'type'  => $record->subject_type,
            'label' => str_replace('_', ' ', (string) $record->subject_type),
            'href'  => $href,
        ];
    }

    /** @return list<array{id: string, name: string}> the viewer's chambers (R-09). */
    private function viewerChambers(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return LegislatureMember::query()
            ->where('user_id', (string) $user->getKey())
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->with('legislature.jurisdiction:id,name')
            ->get()
            ->map(fn (LegislatureMember $member) => [
                'id'   => (string) $member->legislature_id,
                'name' => __(':name legislature', ['name' => $member->legislature?->jurisdiction?->name ?? __('Unknown')]),
            ])
            ->unique('id')
            ->values()
            ->all();
    }

    /**
     * Attach-to options for the composer (bill / session / vote / general)
     * — bounded recents of the viewer's chamber.
     */
    private function subjectOptions(string $legislatureId): array
    {
        $bills = Bill::query()
            ->where('legislature_id', $legislatureId)
            ->orderByDesc('introduced_at')
            ->limit(10)
            ->get(['id', 'title'])
            ->map(fn (Bill $bill) => [
                'type'  => 'bill',
                'id'    => (string) $bill->id,
                'label' => __('Bill — :title', ['title' => $bill->title]),
            ]);

        $sessions = LegislatureSession::query()
            ->where('legislature_id', $legislatureId)
            ->orderByDesc('session_no')
            ->limit(5)
            ->get(['id', 'session_no', 'scheduled_for'])
            ->map(fn (LegislatureSession $session) => [
                'type'  => 'session',
                'id'    => (string) $session->id,
                'label' => __('Session #:no', ['no' => $session->session_no]) . ($session->scheduled_for !== null ? ' — ' . $session->scheduled_for->toDateString() : ''),
            ]);

        $votes = ChamberVote::query()
            ->where('body_type', ChamberVote::BODY_LEGISLATURE)
            ->where('body_id', $legislatureId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'vote_type', 'status'])
            ->map(fn (ChamberVote $vote) => [
                'type'  => 'vote',
                'id'    => (string) $vote->id,
                'label' => __('Vote — :type (:status)', ['type' => $vote->vote_type, 'status' => $vote->status]),
            ]);

        return $bills->concat($sessions)->concat($votes)->values()->all();
    }
}
