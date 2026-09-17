<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\InstanceSettings;
use App\Services\Operator\OperatorApplyService;
use App\Services\Operator\OperatorInfraService;
use App\Services\Operator\OperatorSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operator Operations console (Phase 1, read-only) — the infrastructure & identity
 * inventory: every hardcoded / env-baked / file-managed knob in one operator-facing
 * surface, with its apply tier and live status (cert expiry, channel state). The
 * operator plane, deliberately separate from constitutional_settings.
 *
 * Gating mirrors the federation console: the page shell is reachable by any
 * authenticated user, but the inventory (config values, cert domains, channel state)
 * is built ONLY for an authenticated OPERATOR — a citizen sees a sign-in prompt and
 * NO infra data. Secrets never ride a prop (OperatorInfraService surfaces only
 * `configured?` / `dev_default?`, never a value).
 */
class OperatorConsoleController extends Controller
{
    public function operations(OperatorInfraService $infra): Response
    {
        $operator = Auth::guard('operator')->user();
        $authed = $operator !== null;

        return Inertia::render('Operator/Operations', [
            // The instance class (operator ruling 2026-09-17): shown with the act
            // that flips it; demo mode arms on scale_demo.
            'instanceClass' => \App\Support\InstanceClass::current(),
            'authed' => $authed,
            'operator' => $authed ? ($operator->username ?? null) : null,
            'inventory' => $authed ? $infra->inventory() : null,
        ]);
    }

    /**
     * Phase 2 — set an INSTANT-tier knob (operator-gated route). `federation_enabled`
     * is a direct instance_settings toggle; the rest are validated overrides overlaid
     * onto config (OperatorSettingsService), applying on the next request — no restart.
     */
    public function setTuning(Request $request, OperatorSettingsService $settings): RedirectResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:64'],
            'value' => ['nullable'],
        ]);
        $key = $validated['key'];

        try {
            if ($key === 'federation_enabled') {
                $s = InstanceSettings::current();
                $s->federation_enabled = $request->boolean('value');
                $s->save();
            } else {
                $settings->set($key, $validated['value'] ?? null);
            }
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['operator_tuning' => $e->getMessage()]);
        }

        return back()->with('status', __('Updated :key.', ['key' => $key]));
    }

    /** Phase 2 — clear an instant-tier override; the knob reverts to its env default. */
    public function resetTuning(Request $request, OperatorSettingsService $settings): RedirectResponse
    {
        $validated = $request->validate(['key' => ['required', 'string', 'max:64']]);
        $settings->clear($validated['key']);

        return back()->with('status', __('Reset :key to its default.', ['key' => $validated['key']]));
    }

    /**
     * Phase 3 — stage a RESTART-tier apply (operator-gated). Writes the validated
     * desired-state control file the host supervisor consumes. No secrets are
     * applyable through this path (see OperatorApplyService).
     */
    public function applyInfra(Request $request, OperatorApplyService $apply): RedirectResponse
    {
        $validated = $request->validate(['changes' => ['required', 'array', 'min:1']]);

        try {
            $apply->requestApply($validated['changes']);
        } catch (InvalidArgumentException | \RuntimeException $e) {
            return back()->withErrors(['operator_apply' => $e->getMessage()]);
        }

        return back()->with('status', __('Apply requested — the host supervisor will rewrite .env and recreate the service. Watch the status below.'));
    }

    /** Phase 3 — the apply lifecycle poll (operator-gated): pending → applying → applied|failed. */
    /**
     * POST /operator/operations/instance-class — flip the instance class as
     * an operator act on the record (operator ruling 2026-09-17). Operator-only;
     * InstanceClass::change() is the single owner the console command shares.
     */
    public function setInstanceClass(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);

        $data = $request->validate([
            'class'  => ['required', 'in:production,scale_demo'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $result = \App\Support\InstanceClass::change(
            $data['class'],
            $request->user()?->id !== null ? (string) $request->user()->id : null,
            (string) ($data['reason'] ?? 'operations console'),
        );

        return back()->with('status', 'instance-class:'.$result['from'].'>'.$result['to']);
    }

    public function applyStatus(OperatorApplyService $apply): JsonResponse
    {
        return response()->json($apply->status());
    }
}
