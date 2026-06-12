<?php

namespace App\Http\Controllers\Dev;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Http\Controllers\Controller;
use App\Models\ResidencyClaim;
use App\Services\ResidencyService;
use App\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dev residency bypass — POST /dev/residency/grant {jurisdiction_id?, lat?, lng?}.
 *
 * LOCAL-ONLY (same WI-4 gate as /dev/pings/simulate: routes registered only
 * in the local environment, DevToolsEnabled 404s when config('cga.
 * impersonation') is off). Runs the ENTIRE residency pipeline instantly for
 * the current user THROUGH THE REAL ENGINE:
 *
 *   1. resolve the target jurisdiction (explicit id, or the smallest
 *      boundary containing {lat, lng} — same resolver as /civic/residency/locate);
 *   2. DEV-ONLY RELOCATION: an ACTIVE claim for a DIFFERENT jurisdiction
 *      blocks F-IND-003 (real relocation with zero rights gap is WF-CIV-03,
 *      Phase C). For operator testing we deactivate the prior claim's
 *      residency_confirmations and mark it superseded, then run the real
 *      pipeline at the new point. This shortcut exists ONLY on this dev
 *      route — production relocation arrives with WF-CIV-03;
 *   3. F-IND-003 declare (the handler supersedes any open unverified claim);
 *   4. simulatePings(threshold days) — one real F-IND-005 per day;
 *   5. verify → F-IND-006: association sweep + R-01..R-04 unlock.
 *
 * Every step files real F-IND forms — all of it lands on the audit chain,
 * which is desirable: the operator's walkthrough exercises the genuine path.
 */
class ResidencyGrantController extends Controller
{
    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly ResidencyService $residency,
        private readonly RoleService $roles,
    ) {
    }

    public function grant(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'jurisdiction_id' => ['nullable', 'uuid', 'required_without_all:lat,lng'],
            'lat'             => ['nullable', 'numeric', 'between:-90,90', 'required_without:jurisdiction_id', 'required_with:lng'],
            'lng'             => ['nullable', 'numeric', 'between:-180,180', 'required_without:jurisdiction_id', 'required_with:lat'],
        ]);

        $user = $request->user();

        // ── Resolve the target jurisdiction ────────────────────────────────
        if (! empty($validated['jurisdiction_id'])) {
            $target = DB::selectOne(
                'SELECT id, name, slug, adm_level FROM jurisdictions
                 WHERE id = ? AND deleted_at IS NULL AND geom IS NOT NULL',
                [$validated['jurisdiction_id']]
            );

            if ($target === null) {
                return response()->json([
                    'found'   => false,
                    'message' => 'Jurisdiction not found (or has no boundary geometry).',
                ], 404);
            }
        } else {
            $target = $this->residency->locateJurisdiction(
                (float) $validated['lat'],
                (float) $validated['lng'],
            );

            if ($target === null) {
                return response()->json([
                    'found'   => false,
                    'message' => 'No jurisdiction contains this point — it appears to be in open water or outside every loaded boundary.',
                ], 404);
            }
        }

        $targetId = (string) $target->id;

        // ── Idempotent short-circuit: already an active resident HERE ──────
        $active = ResidencyClaim::query()
            ->where('user_id', $user->id)
            ->where('status', ResidencyClaim::STATUS_ACTIVE)
            ->first();

        if ($active !== null && (string) $active->jurisdiction_id === $targetId) {
            return response()->json([
                'granted'      => true,
                'already'      => true,
                'jurisdiction' => $this->jurisdictionPayload($target),
                'chain'        => $this->roles->associationsFor($user),
                'roles'        => $this->roles->rolesFor($user),
            ]);
        }

        // ── DEV-ONLY RELOCATION (see class docblock §2) ─────────────────────
        if ($active !== null) {
            DB::transaction(function () use ($active, $user) {
                DB::table('residency_confirmations')
                    ->where('user_id', (string) $user->id)
                    ->where('is_active', true)
                    ->update([
                        'is_active'  => false,
                        'updated_at' => now(),
                    ]);

                $active->forceFill([
                    'status'        => ResidencyClaim::STATUS_SUPERSEDED,
                    'superseded_at' => now(),
                ])->save();
            });

            $this->roles->flushUser((string) $user->id);
        }

        // ── The real pipeline: declare → pings → verify ─────────────────────
        try {
            $this->engine->file('F-IND-003', $user, [
                'jurisdiction_id' => $targetId,
                'ping_consent'    => true,
            ]);

            $claim = ResidencyClaim::query()
                ->where('user_id', $user->id)
                ->whereIn('status', ResidencyClaim::MONITORING_STATUSES)
                ->firstOrFail();

            $threshold = $this->residency->thresholdDays($claim);
            $this->residency->simulatePings($user, $threshold);

            $claim->refresh();
            $this->residency->verify($claim);
        } catch (ConstitutionalViolation $e) {
            return response()->json([
                'granted' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'granted'      => true,
            'already'      => false,
            'jurisdiction' => $this->jurisdictionPayload($target),
            'threshold'    => $threshold,
            'chain'        => $this->roles->associationsFor($user->refresh()),
            'roles'        => $this->roles->rolesFor($user),
        ]);
    }

    /** @return array{id: string, name: string, slug: ?string, adm_level: int} */
    private function jurisdictionPayload(object $target): array
    {
        return [
            'id'        => (string) $target->id,
            'name'      => $target->name,
            'slug'      => $target->slug,
            'adm_level' => (int) $target->adm_level,
        ];
    }
}
