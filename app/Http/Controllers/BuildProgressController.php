<?php

namespace App\Http\Controllers;

use App\Services\InstitutionProvisionService;
use App\Services\InstitutionScaleService;
use App\Support\SurfaceMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * THE BUILD SCREEN — how much of this world actually exists yet.
 *
 * The operator's end state: accepting geodata on a fresh box builds everything
 * before a player ever arrives — boundaries, district maps, legislatures,
 * seats, institutions, civic spaces — and he watches it happen. So this is
 * written as a PIPELINE OF STAGES that will grow, sharing the `stages[]`
 * contract and the `StageBars` component with the sim console rather than
 * being a bespoke screen for institution provisioning.
 *
 * ── Why this reads the WORLD and not a run ───────────────────────────────
 * Deliberately derived: each stage counts what EXISTS against what SHOULD
 * exist, rather than tracking a job's worklist. Two consequences, both wanted:
 *   · it answers "is this world finished?" at any moment, including at rest,
 *     which is the actual question before a player arrives;
 *   · a crashed or half-finished run cannot lie about it — the bar reflects
 *     rows on disk, not a status column someone forgot to update.
 *
 * ── The zero rule shows up here ──────────────────────────────────────────
 * Under `real` population binding an uninhabited jurisdiction gets no
 * institutions at all, so it is excluded from the denominator and reported
 * separately. Otherwise the bars would sit permanently short of 100% and read
 * as broken when they are correct.
 */
class BuildProgressController extends Controller
{
    /** Poll cadence the page uses, and the tween budget the bars get. */
    private const POLL_MS = 2000;

    public function show()
    {
        return Inertia::render('Build/Progress', [
            'surface' => SurfaceMeta::for('build/progress'),
            'pollMs'  => self::POLL_MS,
        ] + $this->snapshot());
    }

    public function progress(): JsonResponse
    {
        return response()->json($this->snapshot());
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        $svc     = app(InstitutionProvisionService::class);
        $binding = $svc->binding();

        // The spine. Every stage below is measured against the set of
        // jurisdictions that are actually entitled to institutions.
        $legislatures = (int) DB::scalar('SELECT count(*) FROM legislatures WHERE deleted_at IS NULL');
        $skipped      = $svc->skippedUninhabited();
        $target       = max(0, $legislatures - $skipped);

        $count = fn (string $sql): int => (int) DB::scalar($sql);

        // Chambers above the district band are the only ones that need a map.
        $needsMap = $count('SELECT count(*) FROM legislatures WHERE deleted_at IS NULL AND type_a_seats > 9');

        $stages = [
            [
                'kind'  => 'legislatures',
                'label' => 'Chambers sized',
                'phase' => 'seating',
                'total' => $legislatures,
                'done'  => $legislatures,
                'note'  => 'One per place, sized from its population. Built when the map was accepted.',
            ],
            [
                'kind'  => 'district_maps',
                'label' => 'District maps drawn',
                'phase' => 'seating',
                // ONLY chambers that actually need one. A chamber inside the
                // 5–9 band elects at large and needs no map, so counting every
                // legislature here would give a bar that can never reach 100%
                // and would report a finished world as unfinished forever.
                'total' => $needsMap,
                'done'  => $count('
                    SELECT count(DISTINCT m.legislature_id)
                      FROM legislature_district_maps m
                      JOIN legislatures l ON l.id = m.legislature_id
                     WHERE l.deleted_at IS NULL AND l.type_a_seats > 9
                '),
                'note'  => 'Only for chambers too big to elect in one race — smaller places need no map.',
            ],
            [
                'kind'  => 'executives',
                'label' => 'Executives',
                'phase' => 'institutions',
                'total' => $target,
                'done'  => $count('SELECT count(*) FROM executives WHERE deleted_at IS NULL'),
                'note'  => 'A committee by default, waiting to be filled by the chamber.',
            ],
            [
                'kind'  => 'judiciaries',
                'label' => 'Courts',
                'phase' => 'institutions',
                'total' => $target,
                'done'  => $count('SELECT count(*) FROM judiciaries WHERE deleted_at IS NULL'),
                'note'  => 'One court per place, appointed by default, at least five judges.',
            ],
            [
                'kind'  => 'election_boards',
                'label' => 'Election boards',
                'phase' => 'institutions',
                'total' => $target,
                'done'  => $count("SELECT count(*) FROM election_boards WHERE deleted_at IS NULL AND status = 'active'"),
                'note'  => 'Runs the first election, then hands over to a real board.',
            ],
            [
                'kind'  => 'social_spaces',
                'label' => 'Public squares and halls',
                'phase' => 'rooms',
                'total' => $target * 2,
                'done'  => $count('SELECT count(*) FROM social_spaces WHERE deleted_at IS NULL AND is_private = false'),
                'note'  => 'Two per place: somewhere to talk, and somewhere to govern.',
            ],
        ];

        foreach ($stages as $i => $s) {
            $stages[$i]['running']    = 0;
            $stages[$i]['review']     = 0;
            $stages[$i]['is_current'] = $s['total'] > 0 && $s['done'] < $s['total'];
        }

        return [
            'stages' => $stages,
            'world'  => [
                'binding'           => $binding,
                'binding_label'     => $binding === InstitutionScaleService::BINDING_REAL
                    ? 'Institutions follow real population'
                    : 'Population imposes nothing — every place gets its full set',
                'legislatures'      => $legislatures,
                'target'            => $target,
                'skipped'           => $skipped,
                'complete'          => $this->isComplete($stages),
            ],
        ];
    }

    /** @param list<array<string,mixed>> $stages */
    private function isComplete(array $stages): bool
    {
        foreach ($stages as $s) {
            if ($s['total'] > 0 && $s['done'] < $s['total']) {
                return false;
            }
        }

        return true;
    }
}
