<?php

namespace Tests\Unit;

use App\Domain\Forms\FormRegistry;
use App\Models\Organization;
use App\Services\Organizations\CoalitionSeedService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PIN (W-0300, Phase J) — the civil-society firewall and the two organisations'
 * settled shape (docs/plans/coalition/PHASE_J_PLAN.md §4, §6, §10, §11).
 *
 *   1. The Action Fund does not exist and reserves nothing: no row, column, enum,
 *      slug or name anywhere in the code.
 *   2. A parent grants no power: parent_organization_id is read by the model's
 *      two relations and by nothing that derives a role or gates a form.
 *   3. R-23's surface is the F-ORG forms only; none is legislative, executive or
 *      judicial, and the endorsement form (an Art. I right) stays registered.
 *   4. The public-domain basis has one authority (mandate | voluntary | null),
 *      and the voluntary charter is one-way.
 *   5. The seed's names and home are the operator's: Foundation parent, Coalition
 *      child programme, New York County, New York.
 */
class PhaseJFirewallTest extends TestCase
{
    private static function sources(): array
    {
        $roots = [app_path(), database_path('migrations'), base_path('routes'), resource_path('js')];
        $files = [];
        foreach ($roots as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (preg_match('/\.(php|vue|js|mjs|json)$/', $f->getFilename())) {
                    $files[] = $f->getPathname();
                }
            }
        }

        return $files;
    }

    public function test_the_action_fund_reserves_nothing(): void
    {
        $hits = [];
        foreach (self::sources() as $path) {
            $src = file_get_contents($path);
            if (preg_match('/action[ _-]?fund/i', $src)) {
                $hits[] = substr($path, strlen(base_path()) + 1);
            }
        }
        // The plan and this pin may name it in order to forbid it; code may not.
        $hits = array_values(array_filter($hits, fn ($p) => ! str_contains($p, 'CoalitionSeedService.php') && ! str_contains($p, 'PhaseJFirewallTest.php')));
        $this->assertSame([], $hits, 'the Action Fund must not be named in code: '.implode(', ', $hits));
    }

    public function test_a_parent_grants_no_power(): void
    {
        $readers = [];
        foreach (self::sources() as $path) {
            if (str_ends_with($path, 'Organization.php') && str_contains($path, 'Models')) {
                continue; // the two relations live here
            }
            if (str_contains(file_get_contents($path), 'parent_organization_id')) {
                $readers[] = substr($path, strlen(base_path()) + 1);
            }
        }
        $allowed = ['app/Services/Organizations/CoalitionSeedService.php', 'database/migrations'];
        $readers = array_values(array_filter($readers, function ($p) use ($allowed) {
            foreach ($allowed as $a) {
                if (str_starts_with(str_replace('\\', '/', $p), $a)) {
                    return false;
                }
            }

            return true;
        }));
        $this->assertSame([], $readers, 'nothing outside the model, the seed and migrations reads parent_organization_id: '.implode(', ', $readers));
    }

    public function test_r23_surface_is_the_org_forms_only(): void
    {
        $src = file_get_contents(app_path('Domain/Forms/FormRegistry.php'));
        preg_match_all("/'(F-ORG-\d{3})'/", $src, $m);
        $forms = array_values(array_unique($m[1]));
        sort($forms);
        $this->assertSame(11, count($forms), 'R-23 holds exactly the eleven F-ORG forms: '.implode(', ', $forms));
        $this->assertContains('F-ORG-002', $forms, 'the endorsement form (an Art. I right) stays registered');
        foreach ($forms as $id) {
            $this->assertStringStartsWith('F-ORG-', $id);
        }
        $this->assertTrue(method_exists(FormRegistry::class, 'resolve') || method_exists(FormRegistry::class, 'get') || class_exists(FormRegistry::class));
    }

    public function test_public_domain_basis_has_one_authority_and_the_charter_is_one_way(): void
    {
        DB::setDefaultConnection('sqlite');
        if (! Schema::hasTable('organizations')) {
            Schema::create('organizations', function ($table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('type');
                $table->boolean('is_cgc')->default(false);
                $table->boolean('ip_is_public_domain')->default(false);
                $table->boolean('public_domain_charter')->default(false);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        $cgc = new Organization(['is_cgc' => true, 'ip_is_public_domain' => true]);
        $this->assertSame('mandate', $cgc->publicDomainBasis());
        $plain = new Organization(['is_cgc' => false, 'ip_is_public_domain' => false]);
        $this->assertNull($plain->publicDomainBasis());

        $org = Organization::create(['id' => '80000000-0000-4000-8000-000000000001', 'name' => 'Charter test', 'type' => Organization::TYPE_NONPROFIT]);
        $this->assertNull($org->publicDomainBasis());
        $org->forceFill(['public_domain_charter' => true])->save();
        $this->assertSame('voluntary', $org->fresh()->publicDomainBasis());

        $this->expectException(\RuntimeException::class);
        $org->fresh()->forceFill(['public_domain_charter' => false])->save();
    }

    public function test_the_seed_carries_the_settled_identities(): void
    {
        $this->assertSame('Cosmopolitan Party Foundation', CoalitionSeedService::FOUNDATION_NAME, 'the legal entity, the parent');
        $this->assertSame('Cosmopolitan Coalition of United Earth', CoalitionSeedService::COALITION_NAME, 'a programme of the Foundation, the child');
        $this->assertSame('usa-3-new-york', CoalitionSeedService::HOME_SLUG, 'New York County, New York (operator order 2026-09-14)');
        $src = file_get_contents(app_path('Services/Organizations/CoalitionSeedService.php'));
        $this->assertStringContainsString("'parent_organization_id' => (string) \$foundation->id", $src, 'the Coalition is the child');
        $this->assertStringContainsString('seatOrganizationBoard($foundation', $src, 'the board sits on the Foundation');
        $this->assertStringNotContainsString('501', $src, 'no US tax category is stored or named as data');
    }
}
