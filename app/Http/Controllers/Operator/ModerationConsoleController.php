<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\LegalComplianceRemoval;
use App\Models\MatrixCarveoutLog;
use App\Support\SurfaceMeta;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /operator/moderation — "Moderation & the legal floor" (design
 * contract: mockups/v3/operator/moderation.html). The mockup is a
 * READ/explainer — zero forms, zero buttons: the operator holds no power to
 * remove on viewpoint, the legitimacy flip is a pure function of facts, and
 * the M-5 legal floor is a closed list grown only by code release. This
 * page renders that teaching structure over the REAL sealed trail — live
 * counts and recent rows from matrix_carveout_log and
 * legal_compliance_removals — so the "everything is logged" claim is shown,
 * not asserted. Same citizen-shell pattern as the /operator suite.
 */
class ModerationConsoleController extends Controller
{
    public function moderation(): Response
    {
        $operator = Auth::guard('operator')->user();
        $authed = $operator !== null;

        return Inertia::render('Operator/Moderation', [
            'surface' => SurfaceMeta::for('operator/moderation'),
            'authed' => $authed,
            'operator' => $authed ? ($operator->username ?? null) : null,
            'moderation' => $authed ? $this->moderationData() : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function moderationData(): array
    {
        // The legitimacy flip is per-jurisdiction; the honest box-level view
        // is how many places sit above it (a seated legislature exists).
        $seated = (int) DB::table('legislatures')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->count();

        $carveoutCounts = MatrixCarveoutLog::query()
            ->select('carve_out', DB::raw('count(*) as n'))
            ->groupBy('carve_out')
            ->pluck('n', 'carve_out')
            ->map(fn ($n) => (int) $n)
            ->all();

        $legalCounts = LegalComplianceRemoval::query()
            ->select('legal_basis', DB::raw('count(*) as n'))
            ->groupBy('legal_basis')
            ->pluck('n', 'legal_basis')
            ->map(fn ($n) => (int) $n)
            ->all();

        return [
            'seated_legislatures' => $seated,
            'carveout_counts' => $carveoutCounts,
            'legal_counts' => $legalCounts,
            // Recent rows from the two append-only ledgers — transparency is
            // the point of these tables; ids are opaque Matrix references.
            'recent_carveouts' => MatrixCarveoutLog::query()
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn (MatrixCarveoutLog $r) => [
                    'carve_out' => (string) $r->carve_out,
                    'action' => (string) $r->action,
                    'judicial' => $r->attestation_id !== null,
                    'seated_at_time' => (bool) $r->is_seated_at_time,
                    'at' => $r->created_at?->toIso8601String(),
                ])->values()->all(),
            'recent_legal' => LegalComplianceRemoval::query()
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn (LegalComplianceRemoval $r) => [
                    'legal_basis' => (string) $r->legal_basis,
                    'action' => (string) $r->action,
                    'physical_removal_status' => (string) $r->physical_removal_status,
                    'matched_list_source' => $r->matched_list_source,
                    'at' => $r->created_at?->toIso8601String(),
                ])->values()->all(),
        ];
    }
}
