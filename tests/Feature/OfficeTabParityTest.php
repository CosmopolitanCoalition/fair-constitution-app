<?php

namespace Tests\Feature;

use App\Services\OfficesHeldResolver;
use Tests\TestCase;

/**
 * PIN — the Office tab, and the shared resolver behind it.
 *
 * A seat-holder's OWN record (/civic/record, MyRecordController) and their PUBLIC
 * profile (/people, PersonProfileController) must show the same offices, because
 * both now read App\Services\OfficesHeldResolver::forUser — the method was lifted
 * out of PersonProfileController precisely so the two views cannot drift. The
 * desk named the Office tab the civic-partials priority; this guards the wiring
 * that makes it honest.
 *
 * Deliberately a SEPARATE file from MyProfileTabsTest, which another lane owns
 * this wave — this adds coverage without editing theirs.
 */
class OfficeTabParityTest extends TestCase
{
    public function test_office_is_a_valid_self_record_tab(): void
    {
        // The server must accept ?tab=office (it is now in MyRecordController::TABS);
        // the Vue is what hides the panel when the viewer holds no office, so the
        // server-side contract here is simply that 'office' is a recognised tab
        // rather than silently coerced to 'overview' like a typo would be.
        $this->assertContains('office', \App\Http\Controllers\Civic\MyRecordController::TABS);
    }

    public function test_forUser_stays_the_public_entry_point(): void
    {
        // Both controllers call OfficesHeldResolver::forUser; if it stops being a
        // public method the injection breaks. Cheap structural guard.
        $reflection = new \ReflectionMethod(OfficesHeldResolver::class, 'forUser');
        $this->assertTrue($reflection->isPublic(), 'forUser must stay public — both controllers call it');
    }

    public function test_the_office_kind_the_vue_branches_on_matches_the_resolver(): void
    {
        // MyRecord.vue's Office panel switches its "Open the …" button label on
        // office.kind === 'legislature'. The resolver is the only thing that emits
        // that value, so the string must exist on BOTH sides — a rename on one
        // silently mislabels or dead-ends the button. This catches that drift
        // across the PHP/JS boundary, which a same-language test cannot see.
        $resolver = file_get_contents(app_path('Services/OfficesHeldResolver.php'));
        $vue = file_get_contents(resource_path('js/Pages/Civic/MyRecord.vue'));

        $this->assertStringContainsString("'kind' => 'legislature'", $resolver,
            'the resolver must still emit the legislature kind the Vue branches on');
        $this->assertStringContainsString("office.kind === 'legislature'", $vue,
            'MyRecord.vue must still branch on the legislature kind the resolver emits');
    }

    public function test_both_controllers_resolve_office_through_the_shared_service(): void
    {
        // The anti-drift guarantee is structural: both controllers must depend on
        // OfficesHeldResolver, not hand-roll the joins. Reading the source is the
        // cheapest way to hold that without a seeded fixture — a controller that
        // reintroduces `legislature_members` joins of its own is the regression.
        $myRecord = file_get_contents(app_path('Http/Controllers/Civic/MyRecordController.php'));
        $person = file_get_contents(app_path('Http/Controllers/Social/PersonProfileController.php'));

        foreach (['MyRecordController' => $myRecord, 'PersonProfileController' => $person] as $name => $src) {
            $this->assertStringContainsString('OfficesHeldResolver', $src,
                "{$name} must resolve offices through the shared OfficesHeldResolver");
            $this->assertStringNotContainsString("DB::table('legislature_members as lm')", $src,
                "{$name} must NOT hand-roll the office joins — that is the drift the resolver prevents");
        }
    }
}
