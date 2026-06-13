<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\CgcIpRegisterEntry;
use App\Models\Organization;
use App\Services\ConstitutionalValidator;
use App\Services\Organizations\CgcIpRegisterService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. III §5 (hard constraint): CGC intellectual
 * property is ALWAYS public domain — never privatized. Replaces the
 * Phase D placeholder `test_cgc_intellectual_property_is_public_domain_forever`.
 *
 * The register is IRREVERSIBLE by four independent layers, each pinned:
 *   1. SCHEMA — append-only trigger on UPDATE/DELETE/TRUNCATE; no
 *      updated_at, no deleted_at; the single-value status CHECK makes
 *      "privatize" UNREPRESENTABLE; UPDATE/DELETE privileges revoked.
 *   2. TRIGGER BEHAVIOR — raw UPDATE and DELETE both raise (the
 *      audit_log tamper-test pattern).
 *   3. WRITE SURFACE — CgcIpRegisterService exposes dedicate() and
 *      nothing else; the Eloquent model throws on update/delete; no
 *      other code path writes the table (source-scanned).
 *   4. PROCESS — F-LEG-027 payloads carrying ip_/reclaim keys are
 *      rejected PRE-VOTE with the citation; cgc_to_private conversion
 *      code never references the register; ip_is_public_domain can never
 *      flip false on an is_cgc row.
 *
 * If an edit breaks these tests, that edit is a constitutional
 * violation — fix the edit, never the test.
 */
class CgcIpPublicDomainTest extends TestCase
{
    private const LIVE_CONNECTION = 'pgsql_cgc_ip_register';

    // ======================================================================
    // 1. Schema pins (read-only information_schema — live pg, guarded)
    // ======================================================================

    public function test_schema_is_append_only_by_construction(): void
    {
        $pg = $this->livePg();

        $columns = array_map(
            fn ($row) => $row->column_name,
            $pg->select(
                "SELECT column_name FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = 'cgc_ip_register'"
            )
        );

        $this->assertNotEmpty($columns, 'cgc_ip_register exists');
        $this->assertNotContains('updated_at', $columns, 'no update timestamp exists — rows never change');
        $this->assertNotContains('deleted_at', $columns, 'no soft-delete column exists — rows never leave');

        // The single-value CHECK: "privatize" is unrepresentable.
        $statusCheck = $pg->selectOne(
            "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint
             WHERE conrelid = 'cgc_ip_register'::regclass
               AND conname = 'cgc_ip_register_status_public_domain'"
        );
        $this->assertNotNull($statusCheck, 'the single-value status CHECK exists');
        $this->assertStringContainsString('public_domain', (string) $statusCheck->def);

        // Append-only triggers cover UPDATE, DELETE, and TRUNCATE.
        $triggers = $pg->select(
            "SELECT tgname, tgtype FROM pg_trigger
             WHERE tgrelid = 'cgc_ip_register'::regclass AND NOT tgisinternal"
        );
        $names = array_map(fn ($t) => $t->tgname, $triggers);
        $this->assertContains('cgc_ip_register_immutable', $names);
        $this->assertContains('cgc_ip_register_no_truncate', $names);

        // Privilege layer: the migration REVOKEs UPDATE/DELETE/TRUNCATE, so
        // the table ACL grants the app role only INSERT/SELECT (+ REFERENCES/
        // TRIGGER). We read the catalog ACL directly rather than
        // has_table_privilege(): the deployed app role is the table owner AND
        // a superuser, and a superuser BYPASSES every runtime ACL check —
        // has_table_privilege() would report UPDATE/DELETE as "available"
        // even with the grant gone. The catalog ACL is the ground truth (it
        // is what binds a non-superuser federation/replica role), and the
        // BEFORE UPDATE/DELETE trigger pinned above is the wall that binds
        // even the superuser. (Art. III §5 — defense in depth.)
        $grants = array_map(
            fn ($row) => strtoupper((string) $row->privilege_type),
            $pg->select(
                "SELECT privilege_type FROM information_schema.role_table_grants
                 WHERE table_schema = 'public' AND table_name = 'cgc_ip_register'"
            )
        );

        $this->assertContains('INSERT', $grants, 'dedications append');
        $this->assertContains('SELECT', $grants, 'dedications are readable');
        $this->assertNotContains('UPDATE', $grants, 'UPDATE privilege revoked from the app role');
        $this->assertNotContains('DELETE', $grants, 'DELETE privilege revoked from the app role');
        $this->assertNotContains('TRUNCATE', $grants, 'TRUNCATE privilege revoked from the app role');
    }

    // ======================================================================
    // 2. Trigger behavior + dedication completeness (live, rolled back)
    // ======================================================================

    public function test_dedications_seal_to_the_record_and_raw_mutation_raises(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $conn->beginTransaction();

        try {
            $org = $this->throwawayCgc($conn);

            $entry = app(CgcIpRegisterService::class)->dedicate(
                $org,
                'Throwaway invention',
                'patentable_invention',
                'Tamper-test dedication.',
                'F-LEG-019',
            );

            // Dedication completeness: sealed to public_records AND the
            // audit chain; status is the only representable value.
            $this->assertSame(CgcIpRegisterEntry::STATUS_PUBLIC_DOMAIN, $entry->status);
            $this->assertNotNull($entry->published_record_id);
            $this->assertNotNull($entry->audit_seq);

            $this->assertTrue(
                $conn->table('public_records')->where('id', (string) $entry->published_record_id)->exists(),
                'the dedication is on the public record'
            );
            $this->assertSame(
                'cgc_ip.dedicated',
                $conn->table('audit_log')->where('seq', (int) $entry->audit_seq)->value('event'),
                'the dedication is a chain entry'
            );

            // Raw UPDATE raises (savepoint so the transaction survives).
            try {
                DB::transaction(function () use ($conn, $entry) {
                    $conn->statement(
                        "UPDATE cgc_ip_register SET status = 'public_domain', asset = 'tampered' WHERE seq = ?",
                        [(int) $entry->seq]
                    );
                });
                $this->fail('UPDATE on cgc_ip_register must raise.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only and irreversible', $e->getMessage());
            }

            // Raw DELETE raises.
            try {
                DB::transaction(function () use ($conn, $entry) {
                    $conn->statement('DELETE FROM cgc_ip_register WHERE seq = ?', [(int) $entry->seq]);
                });
                $this->fail('DELETE on cgc_ip_register must raise.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only and irreversible', $e->getMessage());
            }

            // The row is byte-identical after both attempts.
            $row = $conn->table('cgc_ip_register')->where('seq', (int) $entry->seq)->first();
            $this->assertSame('Throwaway invention', $row->asset);
            $this->assertSame('public_domain', $row->status);

            // The Eloquent model forbids update/delete too.
            try {
                $entry->forceFill(['asset' => 'tampered'])->save();
                $this->fail('Model update must throw.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('irreversible', $e->getMessage());
            }

            try {
                $entry->delete();
                $this->fail('Model delete must throw.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('irreversible', $e->getMessage());
            }

            // ip_is_public_domain can never flip false on an is_cgc row
            // (model invariant — Art. III §5).
            try {
                $org->forceFill(['ip_is_public_domain' => false])->save();
                $this->fail('A CGC\'s IP flag must never flip false.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Art. III §5', $e->getMessage());
            }
        } finally {
            $conn->rollBack();
            DB::setDefaultConnection($originalDefault);
        }
    }

    // ======================================================================
    // 3. Write-surface + process pins (DB-free, always run)
    // ======================================================================

    public function test_the_service_exposes_dedicate_and_nothing_else(): void
    {
        $methods = array_values(array_filter(
            array_map(
                fn (\ReflectionMethod $m) => $m->getName(),
                (new \ReflectionClass(CgcIpRegisterService::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
            ),
            fn (string $name) => $name !== '__construct'
        ));

        $this->assertSame(['dedicate'], $methods, 'CgcIpRegisterService is dedicate-only — reads go through the model.');
    }

    /**
     * Source scan: no code path outside the dedicate surface references
     * the register for mutation; conversion code (cgc_to_private) never
     * touches the table at all (WF-ORG-09 — dedications survive sale).
     */
    public function test_no_other_writer_or_conversion_reference_exists(): void
    {
        $allowedTouchers = array_map(fn ($p) => str_replace('\\', '/', $p), [
            app_path('Models/CgcIpRegisterEntry.php'),
            app_path('Services/Organizations/CgcIpRegisterService.php'),
        ]);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $contents = file_get_contents($path);

            // Strip comments — prose may NAME the register; code may not
            // touch it outside the allowed files.
            $code = preg_replace('!/\*.*?\*/!s', '', $contents);
            $code = preg_replace('!//.*$!m', '', $code);

            if (in_array($path, $allowedTouchers, true)) {
                $this->assertDoesNotMatchRegularExpression(
                    '/cgc_ip_register.*(->update\(|->delete\(|UPDATE\s+cgc_ip_register|DELETE\s+FROM\s+cgc_ip_register)/is',
                    $code,
                    "{$path} mutates the register"
                );

                continue;
            }

            $this->assertStringNotContainsString(
                'cgc_ip_register',
                $code,
                "{$path} references cgc_ip_register — only the model and the dedicate service may."
            );
            $this->assertStringNotContainsString(
                'CgcIpRegisterEntry::',
                str_replace('CgcIpRegisterEntry::class', '', $code),
                "{$path} writes through the register model — only CgcIpRegisterService may."
            );
        }
    }

    /**
     * F-LEG-027 process pin: a reorganization/sale payload smuggling an
     * ip_/reclaim key is rejected PRE-VOTE with the Art. III §5 citation
     * (the engine records the rejected=true chain entry on the live
     * path); the rejection is the validator's, before any vote opens.
     */
    public function test_sale_payload_cannot_carry_ip_reclaim_keys(): void
    {
        $validator = new ConstitutionalValidator;

        foreach (['ip_reclaim' => true, 'ip_register_reset' => 1, 'reclaim_assets' => 'yes'] as $key => $value) {
            try {
                $validator->check('F-LEG-027', [
                    'legislature_id' => (string) Str::uuid(),
                    'organization_id' => (string) Str::uuid(),
                    'branch' => 'sell',
                    $key => $value,
                ]);
                $this->fail("F-LEG-027 payload key [{$key}] must be rejected.");
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. III §5', $e->citation);
                $this->assertStringContainsString('irreversible', $e->getMessage());
            }
        }
    }

    /**
     * Identical-regulation pin (Art. III §5 — the cgc-detail "hardened"
     * line): org-module services branch on is_cgc ONLY in the enumerated
     * places — oversight/chartering, IP dedication, conversion/sale, and
     * dissolution gating. Everything else regulates CGCs exactly like
     * private peers.
     */
    public function test_is_cgc_branches_only_in_the_enumerated_places(): void
    {
        $allowed = array_map(fn ($p) => str_replace('\\', '/', $p), [
            app_path('Models/Organization.php'),                          // the flag + the Art. III §5 invariant
            app_path('Services/Organizations/CgcService.php'),            // chartering + oversight
            app_path('Services/Organizations/CgcIpRegisterService.php'),  // IP dedication
            app_path('Services/Organizations/OrgConversionService.php'),  // conversion directions
            app_path('Services/Organizations/OrgTransferService.php'),    // CGC-never-transfers gate
            app_path('Services/Organizations/OrgRegistryService.php'),    // dissolution gate (F-LEG-027 only)
            app_path('Services/Organizations/OrgBoardService.php'),       // owner-side seat class (BoG posture)
        ]);

        $base = str_replace('\\', '/', app_path());
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Services/Organizations'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            if (in_array($path, $allowed, true)) {
                continue;
            }

            $code = preg_replace('!//.*$!m', '', preg_replace('!/\*.*?\*/!s', '', file_get_contents($path)));

            $this->assertStringNotContainsString(
                'is_cgc',
                $code,
                str_replace($base.'/', '', $path).' branches on is_cgc outside the enumerated Art. III §5 surface.'
            );
        }
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    private function throwawayCgc($conn): Organization
    {
        $jurisdictionId = $conn->table('jurisdictions')->whereNull('deleted_at')->value('id');
        $this->assertNotNull($jurisdictionId, 'Live DB has no jurisdictions — seed it first.');

        return Organization::create([
            'jurisdiction_id' => (string) $jurisdictionId,
            'type' => Organization::TYPE_COMMON_GOOD_CORP,
            'name' => 'CGC IP Throwaway '.Str::random(6),
            'slug' => 'cgc-ip-throwaway-'.strtolower(Str::random(8)),
            'is_cgc' => true,
            'ownership_type' => 'public',
            'ip_is_public_domain' => true,
            'status' => Organization::STATUS_ACTIVE,
            'is_active' => true,
            'is_registered' => true,
            'registered_at' => now(),
            'registered_via_form' => 'F-LEG-019',
            'worker_count' => 0,
        ]);
    }

    private function livePg(): \Illuminate\Database\Connection
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql not loaded — live pins run inside the app container.');
        }

        config([
            'database.connections.'.self::LIVE_CONNECTION => array_merge(
                config('database.connections.pgsql'),
                ['database' => env('LIVE_PG_DATABASE', 'fair_constitution')]
            ),
        ]);

        try {
            $connection = DB::connection(self::LIVE_CONNECTION);
            $connection->getPdo();

            return $connection;
        } catch (\Throwable $e) {
            $this->markTestSkipped('Live PostgreSQL unreachable — run inside the app container. ('.$e->getMessage().')');
        }
    }
}
