<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\Jurisdiction;
use App\Services\ActivationService;
use App\Services\SettingsResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The dev-only web twin of jurisdiction:activate (ruling 10, UI<->CLI parity).
 * The gate is DevToolsEnabled on the route (local + sandbox + impersonation) —
 * the SAME boundary the command's --force dev-bootstrap posture lives behind —
 * so this never exists on a real Standard instance. It mirrors the command
 * exactly: --replan re-runs step 3.5 (ActivationService refuses seated chambers
 * with a RuntimeException); otherwise the CLK-06 critical-population gate applies
 * unless force (the dev bootstrap bypass). Engine refusals surface as errors.
 */
class JurisdictionActivateController extends Controller
{
    public function activate(
        Request $request,
        ActivationService $activation,
        SettingsResolver $settings,
        string $jurisdiction,
    ): RedirectResponse {
        $jur = Jurisdiction::query()
            ->where('slug', $jurisdiction)
            ->orWhere('id', $jurisdiction)
            ->first();

        if ($jur === null) {
            return back()->withErrors(['activate' => "Jurisdiction not found: {$jurisdiction}."]);
        }

        $force  = $request->boolean('force');
        $replan = $request->boolean('replan');

        try {
            if ($replan) {
                $activation->replan($jur);

                return back()->with('status', "Re-planned {$jur->name} (step 3.5).");
            }

            if (! $force) {
                $residents = (int) DB::table('residency_confirmations')
                    ->where('jurisdiction_id', $jur->id)
                    ->where('is_active', true)
                    ->count();

                $threshold = $activation->thresholdFor(
                    $jur->id,
                    $jur->population !== null ? (int) $jur->population : null,
                    $settings,
                );

                if ($residents < $threshold) {
                    return back()->withErrors([
                        'activate' => "CLK-06 not met: {$residents} verified resident(s), threshold {$threshold}. Force to bypass (dev only).",
                    ]);
                }

                $activation->onCriticalPopulation($jur->id, $residents, $threshold);
            }

            $activation->activate($jur);

            return back()->with('status', "Activated {$jur->name} — the WF-JUR-01 pipeline ran.");
        } catch (RuntimeException $e) {
            return back()->withErrors(['activate' => $e->getMessage()]);
        }
    }
}
