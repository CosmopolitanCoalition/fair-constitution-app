<?php

namespace Tests\Feature;

use App\Http\Controllers\Legislature\CommitteeController;
use App\Http\Controllers\Legislature\OversightController;
use App\Http\Controllers\Legislature\SpeakerController;
use App\Support\SurfaceMeta;
use ReflectionClass;
use Tests\TestCase;

/**
 * FE-C6/C7/C8 — Committees/CommitteeDetail, SpeakerTools, Oversight
 * controllers (PHASE_C_DESIGN_frontend.md §B.5–B.8).
 *
 * Deliberately DB-free (established posture — PhaseCChamberOpsHandlersTest):
 * route-table methods, surface wiring, and the form-resolution discipline
 * are pinned here; the DB-backed paths (creation votes, F-SPK-005 runs,
 * chair RCV, removal → vacancy loop) are exercised by the live-stack
 * walkthrough.
 *
 * The source-pattern pins follow the TermLockstepTest grep idiom: every
 * state-changing controller action must either file the canonical form
 * through ConstitutionalEngine or be one of the two documented non-form
 * audited actions (testimony, committee-vote opening) — nothing writes
 * around the engine.
 */
class PhaseCGroupBControllersTest extends TestCase
{
    /** controller => methods per the REGISTERED route table (routes/web.php FE-C6..C8). */
    private const ROUTE_METHODS = [
        CommitteeController::class => [
            'index', 'show', 'store', 'storePreferences', 'assign',
            'storeMeeting', 'meetingAgenda', 'referToFloor', 'storeReport',
            'testimony',
            // re-ballot affordance — route registration pending (reported):
            'openChairBallot',
        ],
        SpeakerController::class => [
            'show', 'storePriority',
        ],
        OversightController::class => [
            'show', 'intake', 'referInvestigation', 'openProceeding', 'declareVacancy',
            // office creation — route registration pending (reported):
            'createOffice',
        ],
    ];

    /** controller file => engine form ids its writes must file.
     *  (Yes/no + ranked CASTS ride the shared POST /votes/{vote}/cast —
     *  SessionController::cast resolves F-LEG-004/005/008/011 there.) */
    private const FILED_FORMS = [
        CommitteeController::class => [
            'F-LEG-009', 'F-LEG-010', 'F-SPK-005', 'F-LEG-011',
            'F-CHR-001', 'F-CHR-002', 'F-CHR-003', 'F-CHR-004',
        ],
        SpeakerController::class => [
            'F-SPK-006',
        ],
        OversightController::class => [
            'F-LEG-013', 'F-SPK-007', 'F-LEG-022', 'F-LEG-036',
        ],
    ];

    public function test_route_table_methods_exist(): void
    {
        foreach (self::ROUTE_METHODS as $controller => $methods) {
            $reflection = new ReflectionClass($controller);

            foreach ($methods as $method) {
                $this->assertTrue(
                    $reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic(),
                    "{$controller}::{$method}() missing or not public — the route table depends on it"
                );
            }
        }
    }

    public function test_surfaces_resolve_for_all_four_pages(): void
    {
        foreach ([
            'legislature/committees',
            'legislature/committee-detail',
            'legislature/speaker-tools',
            'legislature/oversight',
        ] as $surfaceId) {
            $surface = SurfaceMeta::for($surfaceId);

            $this->assertNotEmpty($surface['forms'], "{$surfaceId} carries no canonical forms");
            $this->assertNotEmpty($surface['citation'], "{$surfaceId} carries no citation");
        }
    }

    public function test_speaker_tools_covers_all_nine_fspk_forms(): void
    {
        $ids = array_column(SurfaceMeta::for('legislature/speaker-tools')['forms'], 'id');

        for ($n = 1; $n <= 9; $n++) {
            $this->assertContains(sprintf('F-SPK-%03d', $n), $ids);
        }

        // The launchpad map covers all nine (private const — reflection pin).
        $surfaces = (new ReflectionClass(SpeakerController::class))
            ->getConstant('FORM_SURFACES');

        $this->assertSame(9, count($surfaces));
        $this->assertSame([], array_diff(array_keys($surfaces), $ids));
    }

    /**
     * Every state change files its canonical form through the engine —
     * the controller source carries the engine->file('F-…') calls, and
     * the only DB facade writes are the two documented non-form audited
     * actions (testimony record; committee-vote opening), both inside
     * DB::transaction with their audit trail.
     */
    public function test_writes_flow_through_the_engine(): void
    {
        foreach (self::FILED_FORMS as $controller => $forms) {
            $source = file_get_contents((new ReflectionClass($controller))->getFileName());

            $this->assertStringContainsString('$this->engine->file(', $source);

            foreach ($forms as $formId) {
                // Direct filings read file('F-…'); shape-resolved casts
                // (committee/oversight castVote) carry the id as a literal
                // resolved into the same engine->file() call.
                $this->assertStringContainsString(
                    "'{$formId}'",
                    $source,
                    "{$controller} never files {$formId} through the engine"
                );
            }

            // No raw query-builder writes around the engine: inserts/updates
            // ride Eloquent models inside services, never DB::table()->…
            foreach (['DB::table(', '->insert(', '->update(['] as $rawWrite) {
                $this->assertStringNotContainsString(
                    $rawWrite,
                    $source,
                    "{$controller} writes around the engine via {$rawWrite}"
                );
            }
        }
    }

    /**
     * Removal casts file F-LEG-022 (proceeding-aware) — never the generic
     * floor form: the catalog distinguishes them, so the audit ref must
     * too. The lifecycle endpoint multiplexes open/designate/open_vote
     * (F-SPK-007) + cast (F-LEG-022).
     */
    public function test_oversight_proceeding_lifecycle_files_both_forms(): void
    {
        $source = file_get_contents((new ReflectionClass(OversightController::class))->getFileName());

        $this->assertStringContainsString("file('F-LEG-022'", $source);
        $this->assertStringContainsString("file('F-SPK-007'", $source);
        $this->assertStringContainsString("'open_vote'", $source);
        $this->assertStringContainsString("'designate'", $source);
    }

    /**
     * Chamber ops §C.5: after the F-SPK-005 run seats committees, the
     * SYSTEM opens the chair ballotings (F-LEG-011 action=open, actor
     * null) — no Speaker click required for the normal flow.
     */
    public function test_assignment_run_system_opens_chair_ballots(): void
    {
        $source = file_get_contents((new ReflectionClass(CommitteeController::class))->getFileName());

        $this->assertMatchesRegularExpression(
            "/file\('F-LEG-011', null,/",
            $source,
            'assign() must system-open chair ballotings for seated chairless committees'
        );
    }
}
