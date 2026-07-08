<?php

namespace App\Http\Controllers;

use App\Jobs\GeodataScanJob;
use App\Models\GeodataFlag;
use App\Models\GeodataRepair;
use App\Services\Geodata\GeodataFlagService;
use App\Services\Geodata\GeodataRemediationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Geodata repair plane — the HTTP surface over GeodataFlagService (detect)
 * and GeodataRemediationService (repair). Serves the Jurisdiction Viewer's
 * flag queue during the setup-time repair window; the window itself
 * (setup incomplete + map data not yet accepted) is enforced in the
 * remediation service, so a closed window surfaces here as a 409.
 *
 * Every mutating endpoint returns the refreshed flag counts alongside its
 * payload so the UI can update its badges in place without a second fetch.
 */
class GeodataRepairController extends Controller
{
    public function __construct(
        private readonly GeodataRemediationService $remediation,
    ) {}

    // ─── Reads ───────────────────────────────────────────────────────────────

    /**
     * GET /api/geodata/flags?status=&category= — the flag queue + counts.
     */
    public function flags(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status'   => ['nullable', Rule::in(GeodataFlag::STATUSES)],
            'category' => ['nullable', Rule::in(GeodataFlag::CATEGORIES)],
        ]);

        $query = GeodataFlag::query()
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['category'] ?? null, fn ($q, $c) => $q->where('category', $c));

        // total precedes the LIMIT so the UI can say "showing 500 of N"
        // instead of silently truncating (a world-scale same_space_chain scan
        // alone produces thousands of flags).
        $total = (clone $query)->count();

        $flags = $query
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('detected_at')
            ->limit(500)
            ->get();

        return response()->json([
            'flags'     => $flags,
            'total'     => $total,
            'truncated' => $total > $flags->count(),
            'counts'    => $this->flagCounts(),
        ]);
    }

    /**
     * GET /api/geodata/scan/status — the scan job's live status cache.
     */
    public function scanStatus(GeodataFlagService $flagService): JsonResponse
    {
        return response()->json($flagService->status());
    }

    /**
     * GET /api/geodata/repairs — the applied-repair ledger, newest first.
     */
    public function repairs(): JsonResponse
    {
        return response()->json([
            'repairs' => GeodataRepair::query()
                ->orderByDesc('applied_at')
                ->limit(500)
                ->get(),
        ]);
    }

    // ─── Scan ────────────────────────────────────────────────────────────────

    /**
     * POST /api/geodata/scan {categories?} — queue a detector sweep.
     */
    public function scan(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);

        // Scans mutate the derived flag queue — allowed through the whole
        // setup window (including after acceptance, for pre-reopen awareness)
        // but not on a completed instance.
        $instance = \App\Models\InstanceSettings::current();
        abort_if($instance?->setup_completed_at !== null, 403, 'Setup is complete — the geodata scan is setup-window tooling.');

        $data = $request->validate([
            'categories'   => ['nullable', 'array'],
            'categories.*' => [Rule::in(GeodataFlag::CATEGORIES)],
        ]);

        // Stamp "queued" into the status cache BEFORE the job starts so the
        // UI's poll can't observe the previous scan's finished state and
        // declare this one done before a worker even picks it up.
        $prior = \Illuminate\Support\Facades\Cache::get('geodata.scan.status', []);
        \Illuminate\Support\Facades\Cache::forever('geodata.scan.status', [
            'running'      => true,
            'started_at'   => now()->toIso8601String(),
            'finished_at'  => null,
            'progress'     => [],
            'last_summary' => $prior['last_summary'] ?? null,
        ]);

        GeodataScanJob::dispatch($data['categories'] ?? null);

        return response()->json(['ok' => true]);
    }

    // ─── Repairs ─────────────────────────────────────────────────────────────

    /**
     * POST /api/geodata/flags/{flag}/accept {note?} — "this is fine".
     */
    public function acceptFlag(Request $request, GeodataFlag $flag): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return $this->attempt(fn () => [
            'flag' => $this->remediation->acceptFlag($flag, $data['note'] ?? null, $request->user()?->id),
        ]);
    }

    /**
     * POST /api/geodata/repairs/reparent {target_slug, new_parent_slug, note?, flag_id?}
     */
    public function reparent(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);

        $data = $request->validate([
            'target_slug'     => ['required', 'string'],
            'new_parent_slug' => ['required', 'string'],
            'note'            => ['nullable', 'string', 'max:2000'],
            'flag_id'         => ['nullable', 'uuid'],
        ]);
        $flag = $this->resolveFlag($data['flag_id'] ?? null);
        if ($flag instanceof JsonResponse) {
            return $flag;
        }

        return $this->attempt(fn () => [
            'repair' => $this->remediation->reparent(
                $flag, $data['target_slug'], $data['new_parent_slug'], $data['note'] ?? null, $request->user()?->id
            ),
        ]);
    }

    /**
     * POST /api/geodata/repairs/synthesize-anchor {parent_slug, name, child_slugs, note?, flag_id?}
     */
    public function synthesizeAnchor(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);

        $data = $request->validate([
            'parent_slug'   => ['required', 'string'],
            'name'          => ['required', 'string', 'max:255'],
            'child_slugs'   => ['required', 'array', 'min:1'],
            'child_slugs.*' => ['string'],
            'note'          => ['nullable', 'string', 'max:2000'],
            'flag_id'       => ['nullable', 'uuid'],
        ]);
        $flag = $this->resolveFlag($data['flag_id'] ?? null);
        if ($flag instanceof JsonResponse) {
            return $flag;
        }

        return $this->attempt(fn () => [
            'repair' => $this->remediation->synthesizeAnchor(
                $flag, $data['parent_slug'], $data['name'], $data['child_slugs'], $data['note'] ?? null, $request->user()?->id
            ),
        ]);
    }

    /**
     * POST /api/geodata/repairs/merge-chain {chain_slugs (topmost first), note?, flag_id?}
     */
    public function mergeChain(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);

        $data = $request->validate([
            'chain_slugs'   => ['required', 'array', 'min:2'],
            'chain_slugs.*' => ['string'],
            'note'          => ['nullable', 'string', 'max:2000'],
            'flag_id'       => ['nullable', 'uuid'],
        ]);
        $flag = $this->resolveFlag($data['flag_id'] ?? null);
        if ($flag instanceof JsonResponse) {
            return $flag;
        }

        return $this->attempt(fn () => [
            'repair' => $this->remediation->mergeChain(
                $flag, $data['chain_slugs'], $data['note'] ?? null, $request->user()?->id
            ),
        ]);
    }

    /**
     * POST /api/geodata/repairs/prune {target_slug, cascade, note?, flag_id?}
     */
    public function prune(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);

        $data = $request->validate([
            'target_slug' => ['required', 'string'],
            'cascade'     => ['required', 'boolean'],
            'note'        => ['nullable', 'string', 'max:2000'],
            'flag_id'     => ['nullable', 'uuid'],
        ]);
        $flag = $this->resolveFlag($data['flag_id'] ?? null);
        if ($flag instanceof JsonResponse) {
            return $flag;
        }

        return $this->attempt(fn () => [
            'repair' => $this->remediation->softPrune(
                $flag, $data['target_slug'], (bool) $data['cascade'], $data['note'] ?? null, $request->user()?->id
            ),
        ]);
    }

    /**
     * POST /api/geodata/repairs/recompute-population {target_slug, method, note?, flag_id?}
     */
    public function recomputePopulation(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);

        $data = $request->validate([
            'target_slug' => ['required', 'string'],
            'method'      => ['required', Rule::in(['children_sum', 'raster_total'])],
            'note'        => ['nullable', 'string', 'max:2000'],
            'flag_id'     => ['nullable', 'uuid'],
        ]);
        $flag = $this->resolveFlag($data['flag_id'] ?? null);
        if ($flag instanceof JsonResponse) {
            return $flag;
        }

        return $this->attempt(fn () => [
            'repair' => $this->remediation->recomputePopulation(
                $flag, $data['target_slug'], $data['method'], $data['note'] ?? null, $request->user()?->id
            ),
        ]);
    }

    /**
     * POST /api/geodata/repairs/{repair}/revert
     */
    public function revert(Request $request, GeodataRepair $repair): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);

        return $this->attempt(fn () => [
            'repair' => $this->remediation->revert($repair, $request->user()?->id),
        ]);
    }

    // ─── Internals ───────────────────────────────────────────────────────────

    /**
     * Run a remediation call, mapping its exception contract onto HTTP:
     * bad inputs (InvalidArgumentException) → 422, closed repair window
     * (RuntimeException) → 409. Success merges the refreshed flag counts.
     */
    private function attempt(\Closure $callback): JsonResponse
    {
        try {
            $payload = $callback();
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 409);
        }

        return response()->json(['ok' => true] + $payload + ['counts' => $this->flagCounts()]);
    }

    /**
     * flag_id is optional on every repair; when present it must exist.
     */
    private function resolveFlag(?string $flagId): GeodataFlag|JsonResponse|null
    {
        if ($flagId === null) {
            return null;
        }
        $flag = GeodataFlag::query()->find($flagId);
        if (! $flag) {
            return response()->json(['ok' => false, 'error' => "Unknown flag [{$flagId}]."], 422);
        }

        return $flag;
    }

    /**
     * The badge counts every response carries: totals per status, open
     * flags per category, and open flags per severity.
     */
    private function flagCounts(): array
    {
        $rows = GeodataFlag::query()
            ->selectRaw('status, category, severity, COUNT(*) AS cnt')
            ->groupBy('status', 'category', 'severity')
            ->get();

        $counts = [
            'open'             => 0,
            'accepted'         => 0,
            'resolved'         => 0,
            'by_category'      => [],
            'open_by_severity' => ['critical' => 0, 'warning' => 0, 'info' => 0],
        ];
        foreach ($rows as $row) {
            $counts[$row->status] = ($counts[$row->status] ?? 0) + (int) $row->cnt;
            if ($row->status === 'open') {
                $counts['by_category'][$row->category] = ($counts['by_category'][$row->category] ?? 0) + (int) $row->cnt;
                $counts['open_by_severity'][$row->severity] = ($counts['open_by_severity'][$row->severity] ?? 0) + (int) $row->cnt;
            }
        }

        return $counts;
    }
}
