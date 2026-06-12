<?php

namespace Tests\Constitutional;

use App\Services\ConstitutionalValidator;
use App\Services\RoleService;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. I: voting and candidacy are ABSOLUTE rights of
 * residency. R-04 (Voter) must derive as R-04 ⇔ R-03 (Associated): the
 * jurisdictional association is the only gate, and nothing may ever sit
 * between the two.
 *
 * Deliberately DB-free (same posture as AuditChainSmokeTest /
 * AuthPagesTest: the phpunit sqlite :memory: connection has no schema and
 * RefreshDatabase is forbidden on the live dev DB). RoleService::derive is
 * a static pure function precisely so this suite can pin it exhaustively;
 * the fact-querying wrapper (rolesFor) is exercised in the live-stack WI-5
 * E2E.
 *
 * If an edit to RoleService::derive breaks these tests, that edit is a
 * constitutional violation — fix the edit, never the test.
 */
class RightsAutomaticTest extends TestCase
{
    public function test_r04_derives_if_and_only_if_r03_across_all_fact_combinations(): void
    {
        foreach ([true, false] as $authenticated) {
            foreach ([true, false] as $activeClaim) {
                foreach ([true, false] as $activeAssociation) {
                    $roles = RoleService::derive($authenticated, $activeClaim, $activeAssociation);

                    $this->assertSame(
                        in_array('R-03', $roles, true),
                        in_array('R-04', $roles, true),
                        sprintf(
                            'R-04 must hold iff R-03 (Art. I). Facts: authenticated=%s, claim=%s, association=%s → [%s]',
                            var_export($authenticated, true),
                            var_export($activeClaim, true),
                            var_export($activeAssociation, true),
                            implode(', ', $roles)
                        )
                    );
                }
            }
        }
    }

    public function test_unauthenticated_holds_no_roles(): void
    {
        $this->assertSame([], RoleService::derive(false, false, false));
        // Facts cannot exist without an account, but the derivation must be
        // safe against impossible inputs too.
        $this->assertSame([], RoleService::derive(false, true, true));
    }

    public function test_authentication_alone_grants_exactly_r01(): void
    {
        $this->assertSame(['R-01'], RoleService::derive(true, false, false));
    }

    public function test_active_claim_adds_r02_without_touching_rights(): void
    {
        $this->assertSame(['R-01', 'R-02'], RoleService::derive(true, true, false));
    }

    public function test_association_grants_r03_and_r04_atomically(): void
    {
        // Voting and candidacy unlock together with the association —
        // there is no state in which an associated person lacks R-04.
        // R-05 (Petitioner, Phase C votes_laws §E) rides the same
        // association-only surface — petitioning gates on R-03 alone.
        $this->assertSame(['R-01', 'R-02', 'R-03', 'R-04', 'R-05'], RoleService::derive(true, true, true));

        // Association without an active claim (e.g. legacy data) still
        // carries the rights — the association IS the right.
        $this->assertSame(['R-01', 'R-03', 'R-04', 'R-05'], RoleService::derive(true, false, true));
    }

    public function test_residency_forms_remain_in_the_rights_automatic_guard(): void
    {
        // The validator's rights.automatic guard must keep covering the
        // forms that establish the R-02 → R-03 → R-04 chain, PLUS the
        // Phase B candidacy forms (WI-B4, PHASE_B_DESIGN_schema_lifecycle
        // §C): registration and board validation are rights-automatic —
        // association is the only gate. This list may only ever GROW, and
        // only under constitutional review.
        $this->assertSame(
            ['F-IND-003', 'F-IND-005', 'F-IND-006', 'F-IND-011', 'F-ELB-002'],
            ConstitutionalValidator::RIGHTS_AUTOMATIC_FORMS
        );
    }

    // ─── Phase B derivations (WI-B4): R-06..R-09, R-23 ───────────────────

    public function test_phase_b_facts_default_off_and_never_disturb_the_rights_chain(): void
    {
        // The Phase A call shape (3 args) must keep deriving identically —
        // the new facts default to false.
        $this->assertSame(['R-01', 'R-02', 'R-03', 'R-04', 'R-05'], RoleService::derive(true, true, true));

        // And with every Phase B fact on, R-03 ⇔ R-04 still holds.
        $roles = RoleService::derive(true, true, true, true, true, true, true, true);
        $this->assertSame(
            in_array('R-03', $roles, true),
            in_array('R-04', $roles, true),
        );
        $this->assertSame(['R-01', 'R-02', 'R-03', 'R-04', 'R-05', 'R-06', 'R-07', 'R-08', 'R-09', 'R-23'], $roles);
    }

    public function test_r06_derives_from_a_standing_candidacy(): void
    {
        $this->assertContains('R-06', RoleService::derive(true, false, false, true));
        $this->assertNotContains('R-06', RoleService::derive(true, true, true, false));
    }

    public function test_r07_requires_r06(): void
    {
        // An endorsement without a standing candidacy derives nothing.
        $roles = RoleService::derive(true, true, true, false, true);
        $this->assertNotContains('R-07', $roles);
        $this->assertNotContains('R-06', $roles);

        // Candidacy + org endorsement → R-06 and R-07 together.
        $roles = RoleService::derive(true, true, true, true, true);
        $this->assertContains('R-06', $roles);
        $this->assertContains('R-07', $roles);
    }

    public function test_r08_and_r09_derive_independently_from_their_seat_rows(): void
    {
        $this->assertSame(['R-01', 'R-08'], RoleService::derive(true, false, false, false, false, true));
        $this->assertSame(['R-01', 'R-09'], RoleService::derive(true, false, false, false, false, false, true));
    }

    public function test_unauthenticated_holds_no_phase_b_roles_either(): void
    {
        $this->assertSame([], RoleService::derive(false, true, true, true, true, true, true, true));
    }
}
