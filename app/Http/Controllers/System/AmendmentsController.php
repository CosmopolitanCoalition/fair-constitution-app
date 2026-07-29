<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Legislature\SettingsController;
use App\Models\Jurisdiction;
use App\Models\SettingChange;
use App\Services\ConstitutionalValidator;
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
 * Door one shows BOTH the current resolved amendable values (with their
 * hardened range, enacting act, and effective date) and the append-only
 * ledger of applied changes. The mockup's "Try a proposed value" pre-vote
 * check runs CLIENT-SIDE against the bounds shipped here — a convenience
 * hint only; the AUTHORITATIVE bounds check still runs server-side in the
 * PROTECTED validator when the amendment bill is filed (F-LEG-031), so this
 * surface stays read-only and can never enact anything.
 */
class AmendmentsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('System/Amendments', [
            'surface'  => SurfaceMeta::for('system/amendments'),
            'settings' => $this->currentValues(),
            'changes'  => $this->ledger(),
        ]);
    }

    /**
     * Door one, the "current values" cards: each amendable key's live value at
     * the instance root, its hardened range (min/max or the allowed set), the
     * constitutional basis, and the enacting act + effective date of the last
     * change. Keys + bounds are reused from the per-jurisdiction register so the
     * two surfaces cannot drift; the value resolves from the ROOT jurisdiction
     * (the instance-wide snapshot — the detailed per-jurisdiction, inheritance-
     * aware view is legislature/settings, linked from the page).
     *
     * @return list<array<string, mixed>>
     */
    private function currentValues(): array
    {
        $settings = Jurisdiction::query()
            ->whereNull('parent_id')
            ->whereNull('deleted_at')
            ->with('constitutionalSettings')
            ->first()?->constitutionalSettings;

        // Latest enacting change per key, instance-wide (ordered newest-first,
        // so unique() keeps the most recent row for each setting_key).
        $latestByKey = SettingChange::query()
            ->with('law:id,act_number,enacting_bill_id')
            ->orderByDesc('applied_at')
            ->get()
            ->unique('setting_key')
            ->keyBy('setting_key');

        $bounds = ConstitutionalValidator::SETTING_BOUNDS;

        return collect(SettingsController::REGISTER_KEYS)
            ->map(function (string $key) use ($settings, $latestByKey, $bounds): array {
                $keyBounds = $bounds[$key] ?? null;
                $change = $latestByKey->get($key);

                return [
                    'key'        => $key,
                    'value'      => $settings?->{$key},
                    'bounds'     => $keyBounds !== null
                        ? array_intersect_key($keyBounds, array_flip(['min', 'max', 'allowed']))
                        : null,
                    'basis'      => $keyBounds['citation'] ?? 'Art. VII',
                    'enacted_by' => $change !== null && $change->law !== null ? [
                        'act_number'   => $change->law->act_number,
                        'href'         => $change->law->enacting_bill_id !== null
                            ? "/bills/{$change->law->enacting_bill_id}"
                            : '/system/public-records',
                        'effective_at' => $change->applied_at?->toIso8601String(),
                    ] : null,
                ];
            })
            ->values()
            ->all();
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
