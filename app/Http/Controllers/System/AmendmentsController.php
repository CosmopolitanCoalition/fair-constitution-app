<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\Jurisdiction;
use App\Models\SettingChange;
use App\Support\SurfaceMeta;
use Inertia\Inertia;
use Inertia\Response;

/**
 * mockups-v3-wiring Phase 2 — /system/amendments (design contract:
 * mockups/v3/system/amendments.html).
 *
 *   GET /system/amendments   show
 *
 * READ-ONLY BY DESIGN — the two doors the constitution changes through:
 * door one is the LIVE F-LEG-031 setting_changes ledger (append-only,
 * one row per applied amendable-setting change, linked to the enacting
 * act); door two is the hardened layer, which changes only by a public
 * software release that passes every constitutional check.
 *
 * The mockup's "Try a proposed value" pre-vote validator is deliberately
 * NOT built this phase — Phase-7 follow-up (it belongs with the bill-flow
 * validation surfaces, which already run the same bounds check server-side).
 */
class AmendmentsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('System/Amendments', [
            'surface' => SurfaceMeta::for('system/amendments'),
            'changes' => $this->ledger(),
        ]);
    }

    /**
     * The latest 50 rows of the append-only amendment ledger, newest
     * first, with jurisdiction name + enacting-act provenance (the
     * SettingsController::changesHistory idiom, instance-wide).
     *
     * @return list<array<string, mixed>>
     */
    private function ledger(): array
    {
        $changes = SettingChange::query()
            ->with('law:id,act_number,enacting_bill_id')
            ->orderByDesc('applied_at')
            ->limit(50)
            ->get();

        $names = Jurisdiction::query()
            ->whereIn('id', $changes->pluck('jurisdiction_id')->unique()->values())
            ->pluck('name', 'id');

        return $changes
            ->map(fn (SettingChange $change) => [
                'id'          => (string) $change->id,
                'applied_at'  => $change->applied_at?->toIso8601String(),
                'where'       => $names[(string) $change->jurisdiction_id] ?? '—',
                'setting_key' => $change->setting_key,
                'old_value'   => $change->old_value,
                'new_value'   => $change->new_value,
                'act_number'  => $change->law?->act_number,
                'bill_href'   => $change->law?->enacting_bill_id !== null
                    ? "/bills/{$change->law->enacting_bill_id}"
                    : null,
            ])
            ->values()
            ->all();
    }
}
