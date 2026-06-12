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
        $this->assertSame(['R-01', 'R-02', 'R-03', 'R-04'], RoleService::derive(true, true, true));

        // Association without an active claim (e.g. legacy data) still
        // carries the rights — the association IS the right.
        $this->assertSame(['R-01', 'R-03', 'R-04'], RoleService::derive(true, false, true));
    }

    public function test_residency_forms_remain_in_the_rights_automatic_guard(): void
    {
        // The validator's rights.automatic guard must keep covering the
        // forms that establish the R-02 → R-03 → R-04 chain.
        $this->assertSame(
            ['F-IND-003', 'F-IND-005', 'F-IND-006'],
            ConstitutionalValidator::RIGHTS_AUTOMATIC_FORMS
        );
    }
}
