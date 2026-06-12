<?php

namespace Tests\Feature;

use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\FormRegistry;
use Tests\TestCase;

/**
 * Phase C chamber-ops scope — the 19 engine handlers of
 * PHASE_C_DESIGN_chamber_ops §G.1: registry wiring + role gates.
 *
 * Deliberately DB-free (established posture — PhaseBHandlersTest): the
 * DB-backed paths (vote casting, assignment runs, removal loop, board
 * transition) are exercised by the live-stack tinker verification.
 *
 * Two documented role-gate extensions beyond the catalog's "Filed by"
 * column (the engine gate is the handler's, the catalog row is display):
 *  - F-SPK-007 adds R-09: the designated presider for the Speaker's OWN
 *    case holds no R-10 (removal.presider, Art. II §3 · as implemented);
 *    the handler pins that an R-09 actor IS the designated presider.
 *  - F-CHR-001..004 add R-13: the alternate acts when the chair is
 *    absent (chamber ops §C.5 — attested on the filing).
 */
class PhaseCChamberOpsHandlersTest extends TestCase
{
    /** form id => engine role gate (handler-declared). */
    private const CHAMBER_OPS_FORMS = [
        'F-LEG-001' => ['R-09'],
        'F-LEG-008' => ['R-09'],
        'F-LEG-009' => ['R-09'],
        'F-LEG-010' => ['R-09'],
        'F-LEG-011' => ['R-09'],
        'F-LEG-012' => ['R-09'],
        'F-LEG-013' => ['R-09'],
        'F-LEG-022' => ['R-09'],
        'F-LEG-032' => ['R-09'],
        'F-LEG-033' => ['R-09'],
        'F-LEG-036' => ['R-09', 'R-10'],
        'F-SPK-004' => ['R-10'],
        'F-SPK-005' => ['R-10'],
        'F-SPK-006' => ['R-10'],
        'F-SPK-007' => ['R-10', 'R-09'],
        'F-CHR-001' => ['R-12', 'R-13'],
        'F-CHR-002' => ['R-12', 'R-13'],
        'F-CHR-003' => ['R-12', 'R-13'],
        'F-CHR-004' => ['R-12', 'R-13'],
    ];

    public function test_all_chamber_ops_forms_have_registered_handlers(): void
    {
        foreach (array_keys(self::CHAMBER_OPS_FORMS) as $formId) {
            $class = FormRegistry::handlerFor($formId);

            $this->assertNotNull($class, "{$formId} has no handler registered");
            $this->assertTrue(class_exists($class), "{$formId} handler class {$class} missing");
            $this->assertTrue(
                is_subclass_of($class, FormHandler::class),
                "{$formId} handler does not implement FormHandler"
            );
        }
    }

    public function test_handler_role_gates_are_pinned(): void
    {
        foreach (self::CHAMBER_OPS_FORMS as $formId => $roles) {
            $handler = app(FormRegistry::handlerFor($formId));

            $this->assertSame($roles, $handler->requiredRoles(), "{$formId} role gate drifted");
            $this->assertSame('legislature', $handler->module(), "{$formId} module");
            $this->assertNotSame('', $handler->event(), "{$formId} event empty");
            $this->assertFalse(
                $handler->systemOnly(),
                "{$formId} must allow member/speaker filing (system filings bypass gates)"
            );
        }
    }

    public function test_pure_alias_forms_resolve_to_the_chr_handlers(): void
    {
        // F-COM-001..004 (Workflows Catalog prefix drift) resolve to the
        // canonical F-CHR forms, which now carry handlers.
        foreach (['F-COM-001' => 'F-CHR-001', 'F-COM-004' => 'F-CHR-004'] as $alias => $canonical) {
            $this->assertSame($canonical, FormRegistry::canonical($alias));
            $this->assertNotNull(FormRegistry::handlerFor($canonical));
        }
    }

    public function test_judge_and_executive_consent_forms_stay_unregistered(): void
    {
        // F-LEG-020/021 have no seated subjects until Phase D/E — honest
        // deferral, pinned so a stray registration is noticed.
        $this->assertNull(FormRegistry::handlerFor('F-LEG-020'));
        $this->assertNull(FormRegistry::handlerFor('F-LEG-021'));
    }
}
