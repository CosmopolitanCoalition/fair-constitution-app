<?php

namespace Tests\Constitutional;

use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G-OP) the PLANE WALL. The operator identity plane
 * (operator_accounts / operator_devices, OperatorIdentityService, MeshOperator*)
 * is an INFRASTRUCTURE plane, orthogonal to the citizen franchise. `RoleService`
 * — the sole deriver of R-## governance roles (Art. I: roles are derived, never
 * stored) — must NEVER read operator state, so that being a server operator can
 * confer NO governance advantage. Mirrors ClusterAuthoritySeparationTest's
 * authority≠leadership grep pin.
 *
 * If an edit makes a role-deriving file reference the operator plane, the edit is
 * the violation — fix the edit, not the test.
 */
class OperatorPlaneSeparationTest extends TestCase
{
    public function test_role_deriving_files_never_reference_the_operator_plane(): void
    {
        $forbidden = [
            'OperatorAccount', 'OperatorDevice', 'OperatorIdentityService',
            'MeshOperator', 'operator_accounts', 'operator_devices',
        ];

        // RoleService is the load-bearing pin (the sole R-## deriver). The role
        // gate + attestation surface are pinned too where present; path drift is
        // tolerated so the pin never false-fails on a rename.
        $files = [
            'app/Services/RoleService.php',
            'app/Services/Identity/AttestationGate.php',
            'app/Http/Middleware/EnsureRole.php',
        ];

        $checkedRoleService = false;

        foreach ($files as $rel) {
            $path = base_path($rel);
            if (! is_file($path)) {
                continue;
            }
            if ($rel === 'app/Services/RoleService.php') {
                $checkedRoleService = true;
            }
            $src = (string) file_get_contents($path);
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $src,
                    "{$rel} must not reference the operator plane ({$needle}) — an operator role confers NO governance standing (Art. I)."
                );
            }
        }

        $this->assertTrue($checkedRoleService, 'RoleService.php must exist and be pinned — it is the sole R-## role deriver.');
    }
}
