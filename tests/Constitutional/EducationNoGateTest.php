<?php

namespace Tests\Constitutional;

use App\Domain\Forms\FormRegistry;
use App\Models\User;
use App\Services\Education\TrainingGateService;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. I (absolute rights) + operator ruling A5
 * (K2_ENGINE_PLAN §5.2/§6.5): NO GATE ON BALLOT ACCESS, EVER — and the
 * lawful gate binds ONLY the filing of ROLE-authority forms by a
 * role-holder. Acquiring is free, acting asks.
 *
 * Every clause here is settled law, not safe-direction caution:
 *   - No education state ever conditions an Art. I right: voting,
 *     candidacy, residency, petitions, speech.
 *   - No education state reaches role derivation — roles derive from
 *     residency and acts, never from lessons.
 *   - The gate reads ONLY filed F-EDU-001 completions — never the
 *     achievement ledger (CI-1 stays absolute), never education_progress
 *     (node-local). Asserted in both directions.
 *   - No gate code touches acquisition of any role, elected or appointed.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class EducationNoGateTest extends TestCase
{
    /** Families the gate may never touch, per §5.2's never-list. */
    private const RIGHTS_PREFIXES = ['F-IND', 'F-CAN', 'F-SOC', 'F-EDU'];

    /** Named carve-outs inside otherwise-gated families. */
    private const NEVER_GATED_IDS = [
        'F-LEG-001', // oath/seating IS acquisition
        'F-LEG-002', // presence — quorum can form with untrained members
        'F-LEG-006', // a statement on the record is speech
        'F-LEG-036', // the exit door never locks
        'F-SPK-009', // dual-filer record-keeping
    ];

    public function test_no_right_and_no_carve_out_is_in_the_gated_map(): void
    {
        $gated = array_keys(config('cga.education.gated_forms'));
        $this->assertNotEmpty($gated, 'The act-gate has a form list — an empty one means the config failed to load.');

        foreach ($gated as $formId) {
            foreach (self::RIGHTS_PREFIXES as $prefix) {
                $this->assertStringStartsNotWith(
                    $prefix,
                    $formId,
                    "{$formId} is gated — the {$prefix} family carries rights (or the education plane itself) and is never gated (Art. I / §6.5)."
                );
            }

            $this->assertNotContains(
                $formId,
                self::NEVER_GATED_IDS,
                "{$formId} is gated — it is a named carve-out (acquisition, presence, speech, or the exit door)."
            );
        }
    }

    /**
     * The rights families are refused STRUCTURALLY, not just by config
     * absence: a hostile or careless config edit listing a right still
     * cannot gate it.
     */
    public function test_a_config_edit_cannot_gate_a_right(): void
    {
        config(['cga.education.gated_forms' => array_merge(
            config('cga.education.gated_forms'),
            ['F-IND-007' => 'legislature', 'F-IND-011' => 'legislature', 'F-SOC-001' => 'legislature', 'F-EDU-001' => 'legislature'],
        )]);

        $gate = new TrainingGateService;

        foreach (['F-IND-007', 'F-IND-011', 'F-SOC-001', 'F-EDU-001'] as $right) {
            $this->assertNull(
                $gate->trackFor($right),
                "{$right} was gated by config — the structural prefix rail failed."
            );
            // assertMayAct must be a no-op for a right even for a brand-new,
            // wholly-untrained user.
            $gate->assertMayAct(new User, $right);
        }

        $this->addToAssertionCount(4);
    }

    public function test_every_gated_form_is_canonical_with_a_landing_place(): void
    {
        $hrefs = config('cga.education.lesson_href_by_track');

        foreach (config('cga.education.gated_forms') as $formId => $track) {
            $this->assertSame($formId, FormRegistry::canonical($formId), "{$formId} is not a canonical form id.");
            $this->assertArrayHasKey($track, $hrefs, "Track '{$track}' (gating {$formId}) has no lesson href — the redirect would dead-end.");
        }
    }

    /**
     * THE READING RULE, asserted in both directions on comment-stripped
     * source: the gate consults filed F-EDU-001 records and NOTHING else.
     */
    public function test_the_gate_reads_only_filed_completions(): void
    {
        $code = $this->strippedSource(TrainingGateService::class);

        $this->assertStringContainsString("'F-EDU-001'", $code, 'The gate must read F-EDU-001 records.');

        foreach (['Achievement', 'achievements', 'education_progress'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $code,
                "TrainingGateService touches {$forbidden} — the gate reads ONLY filed F-EDU-001 completions (CI-1 / federation, §5.2 READING RULE)."
            );
        }
    }

    /** Roles derive from residency and acts, never from lessons. */
    public function test_no_education_state_reaches_role_derivation(): void
    {
        $code = $this->strippedSource(\App\Services\RoleService::class);

        foreach (['education', 'training', 'F-EDU', 'TrainingGate'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $code,
                "RoleService references {$forbidden} — no education state may reach role derivation (§6.5)."
            );
        }
    }

    /**
     * Acquisition is never gated, and ENFORCEMENT has exactly one home.
     *
     * Two rails, of different strengths:
     *   - Referencing TrainingGateService at all is confined to the gate's
     *     own file, the engine, and the read-only pre-train backfill (which
     *     reads hasLiveTraining/hasCompleted to decide WHOM to train — it
     *     never gates).
     *   - The enforcement call itself, assertMayAct, has exactly ONE home
     *     outside the gate's definition: the engine's single filing path. No
     *     seating, certification, appointment, registration, or arming
     *     service may grow its own consultation — the backfill is pinned
     *     enforcement-free right here.
     */
    public function test_only_the_engine_consults_the_gate(): void
    {
        $root = str_replace('\\', '/', \dirname(__DIR__, 2).'/app');

        // May NAME the gate: its own file, the engine (enforcer), and the
        // read-only pre-train backfill (arming reader — ruling Option A §①).
        $mayReference = [
            $root.'/Services/Education/TrainingGateService.php',
            $root.'/Domain/Engine/ConstitutionalEngine.php',
            $root.'/Services/Education/SeatedMemberTrainingService.php',
        ];

        // May ENFORCE (name assertMayAct): the gate defines it, the engine
        // calls it. Nothing else — not even the read-only backfill.
        $mayEnforce = [
            $root.'/Services/Education/TrainingGateService.php',
            $root.'/Domain/Engine/ConstitutionalEngine.php',
        ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            $code = preg_replace('!/\*.*?\*/!s', '', file_get_contents($path));
            $code = preg_replace('!//.*$!m', '', $code);

            if (! in_array($path, $mayReference, true)) {
                $this->assertStringNotContainsString(
                    'TrainingGateService',
                    $code,
                    "{$path} consults the training gate — only the engine's filing path and the read-only backfill may (acquiring is free, acting asks)."
                );
            }

            if (! in_array($path, $mayEnforce, true)) {
                $this->assertStringNotContainsString(
                    'assertMayAct',
                    $code,
                    "{$path} enforces the training gate — assertMayAct has one home, the engine's filing path (acquiring is free, acting asks)."
                );
            }
        }
    }

    private function strippedSource(string $class): string
    {
        $code = file_get_contents((new \ReflectionClass($class))->getFileName());
        $code = preg_replace('!/\*.*?\*/!s', '', $code);

        return preg_replace('!//.*$!m', '', $code);
    }
}
