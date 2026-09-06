<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionInstitutionsJob;
use App\Services\InstitutionProvisionService;
use App\Services\InstitutionScaleService;
use App\Support\SurfaceMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function show(Request $request)
    {
        return Inertia::render('Build/Progress', [
            'surface' => SurfaceMeta::for('build/progress'),
            'pollMs'  => self::POLL_MS,
            // /building is public read; the provision action carries its own
            // operator gate, and this flag decides whether the card renders.
            'canProvision' => (bool) $request->user()?->is_operator,
        ] + $this->snapshot());
    }

    /**
     * POST /building/provision — the UI twin of institutions:provision
     * (ruling 10). /building is a PUBLIC read, so this write carries its OWN
     * operator gate. `dry_run` returns the missing-per-step preview inline
     * (a cheap count); a real run is DISPATCHED to the queue, never run on the
     * request — provisioning is planet-scale and set-based/chunked, and the
     * existing 2s poll animates the bars as it fills.
     */
    public function provision(Request $request, InstitutionProvisionService $service): RedirectResponse
    {
        abort_unless($request->user()?->is_operator === true, 403, 'Provisioning is operator-triggered.');

        $step = $request->input('step');
        if ($step !== null && ! in_array($step, InstitutionProvisionService::STEPS, true)) {
            return back()->withErrors([
                'step' => 'Unknown step. Known: '.implode(', ', InstitutionProvisionService::STEPS),
            ]);
        }

        if ($request->boolean('dry_run')) {
            $steps = $step !== null ? [$step] : InstitutionProvisionService::STEPS;
            $missing = [];
            foreach ($steps as $s) {
                $missing[] = $s.': '.number_format($service->pendingTotal($s));
            }

            return back()->with(
                'status',
                'Dry run — missing institutions by step: '.implode(', ', $missing).'. Nothing written.',
            );
        }

        ProvisionInstitutionsJob::dispatch($step);

        return back()->with(
            'status',
            'Provisioning queued — set-based and chunked; the build bars fill as it runs.',
        );
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
                'label' => 'District maps adopted',
                'phase' => 'seating',
                // ONLY chambers that actually need one. A chamber inside the
                // 5–9 band elects at large and needs no map, so counting every
                // legislature here would give a bar that can never reach 100%
                // and would report a finished world as unfinished forever.
                'total' => $needsMap,
                // ⚑ ACTIVE ONLY. A DRAFT IS A PROPOSAL, NOT A BOUNDARY.
                // `racePlan()` selects with ->active(), so a chamber whose only
                // map is a draft has nothing to elect from and its election
                // will refuse. Counting drafts here reported nine chambers as
                // ready when every one of them would have been turned away —
                // the same "declared, not real" defect as counting seats a
                // chamber has not filled, one layer down. Caught by lane 4.
                'done'   => $count("
                    SELECT count(DISTINCT m.legislature_id)
                      FROM legislature_district_maps m
                      JOIN legislatures l ON l.id = m.legislature_id
                     WHERE l.deleted_at IS NULL AND m.deleted_at IS NULL
                       AND l.type_a_seats > 9 AND m.status = 'active'
                "),
                // Drafts are not progress — they need adopting, so they belong
                // in the column that says "needs attention" rather than the one
                // that says "done".
                'review' => $count("
                    SELECT count(DISTINCT m.legislature_id)
                      FROM legislature_district_maps m
                      JOIN legislatures l ON l.id = m.legislature_id
                     WHERE l.deleted_at IS NULL AND m.deleted_at IS NULL
                       AND l.type_a_seats > 9 AND m.status = 'draft'
                       AND NOT EXISTS (
                           SELECT 1 FROM legislature_district_maps a
                            WHERE a.legislature_id = m.legislature_id
                              AND a.status = 'active' AND a.deleted_at IS NULL
                       )
                "),
                // "refused" pointed BACKWARDS at something that had already
                // failed; a walker who does not know the engine reads it as an
                // error the system threw. The state is forward-looking — a map
                // is drawn and waiting to be adopted — so the words point at
                // the pending action instead. (Lane 9, from reading the page
                // as a person rather than as its author.)
                'note'  => 'Only for chambers too big to elect in one race. A DRAFT is not enough — '
                    .'a map has to be adopted before the chamber has districts and can hold its election.',
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
            [
                'kind'  => 'treasuries',
                'label' => 'Public treasuries',
                'phase' => 'money',
                // Only once the world has a currency (founded by the Step 4 run).
                'total' => $svc->currencyId() !== null ? $target : 0,
                'done'  => $count("SELECT count(*) FROM treasury_accounts WHERE deleted_at IS NULL AND owner_type = 'jurisdictions'"),
                'note'  => 'One public treasury per place in the founding currency (Art. II §9, Art. V §5).',
            ],
        ];

        foreach ($stages as $i => $s) {
            $stages[$i]['running'] = 0;
            // Do NOT clobber a review count a stage set for itself — that is
            // how drafts-awaiting-adoption would have been silently zeroed.
            $stages[$i]['review'] ??= 0;
            $stages[$i]['is_current'] = $s['total'] > 0 && $s['done'] < $s['total'];
        }

        // BUILT IS NOT GOVERNED, and the page must not let those read the same.
        // Every bar full means the institutions EXIST; it does not mean anyone
        // holds a seat. A fresh world's chambers are empty until a community
        // holds an election, and that is the correct state — activation
        // declares seats, only an election fills them, and seating without one
        // would manufacture members nobody voted for. Showing "built and ready"
        // without this number is how an empty world reads as a governed one.
        $seated = $count('
            SELECT count(*) FROM legislatures l
             WHERE l.deleted_at IS NULL
               AND EXISTS (SELECT 1 FROM legislature_members m
                            WHERE m.legislature_id = l.id AND m.deleted_at IS NULL)
        ');

        return [
            'stages' => $stages,
            'world'  => [
                'seated'            => $seated,
                'awaiting_election' => max(0, $legislatures - $seated),
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
