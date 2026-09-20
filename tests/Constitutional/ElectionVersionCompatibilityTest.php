<?php

namespace Tests\Constitutional;

use App\Services\ConstitutionalVersionService;
use Tests\TestCase;

class ElectionVersionCompatibilityTest extends TestCase
{
    public function test_only_the_reviewed_transition_and_unchanged_stv_contests_are_compatible(): void
    {
        $old = 'cv1.ac7230fe88c24e2fcd8f323f5e160b78';
        $new = 'cv1.35f8a64e89b09eda7046ff11a3d94f6c';
        $service = new ConstitutionalVersionService();
        self::assertSame($new, $service->derive());
        foreach (['general','special'] as $kind) {
            self::assertTrue($service->permitsElectionCertification($old, $kind, 'stv_droop'));
            self::assertFalse($service->permitsElectionCertification('cv1.unknown', $kind, 'stv_droop'));
            self::assertFalse($service->permitsElectionCertification($old, $kind, 'approval'));
        }
        foreach (['referendum','org_board_owner','judicial','executive'] as $kind) {
            self::assertFalse($service->permitsElectionCertification($old, $kind, 'stv_droop'));
        }
        $future = new class extends ConstitutionalVersionService { public function derive(): string { return 'cv1.future'; } };
        self::assertFalse($future->permitsElectionCertification($old, 'general', 'stv_droop'));
        self::assertFalse($future->permitsElectionCertification($new, 'general', 'stv_droop'));
        self::assertTrue($service->permitsElectionCertification($new, 'general', 'stv_droop'));
        self::assertTrue($service->permitsElectionCertification(null, 'general', 'stv_droop'));
    }

    public function test_the_entire_prior_surface_is_identical_except_the_approved_tiny_threshold(): void
    {
        $hash = hash_init('sha256');
        foreach ((new ConstitutionalVersionService())->surfaceFiles() as $file) {
            $body = str_replace("\r\n", "\n", file_get_contents(base_path($file)));
            if ($file === 'app/Services/ConstitutionalValidator.php') {
                $comment = "     * Operator ruling 2026-09-20: one/two serving members require unanimity;\n     * a threshold may not demand a nonexistent extra member in those bodies.\n";
                $branch = "        if (\$serving === 1 || \$serving === 2) {\n            return \$serving;\n        }\n";
                self::assertSame(1, substr_count($body, $comment));
                self::assertSame(1, substr_count($body, $branch));
                $body = str_replace([$comment,$branch], '', $body);
            }
            hash_update($hash, $file."\n".$body."\0");
        }
        // Proves no counting/finalist/apportionment/other hardened rule changed.
        self::assertSame('cv1.ac7230fe88c24e2fcd8f323f5e160b78', 'cv1.'.substr(hash_final($hash), 0, 32));
    }
}
