<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * gap-lane fix-access — DB-free source pins for the wiring/behaviour changes
 * this lane made. Each reads a source file as text and asserts the change is
 * present. No database, no app boot.
 *
 * Finding 3 (resolver): a11y:roster-ids exposes a second organization sample,
 * 'organization_cgc', that prefers a Common Good Corporation so the CGC profile
 * page itself is swept; resolve-ids.mjs substitutes it into the /cgc route only.
 */
final class Wiring_fix_accessTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $relative): string
    {
        $path = self::root().'/'.$relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    // ── Finding 3: the CGC resolver ──────────────────────────────────────────

    public function test_roster_command_adds_a_cgc_preferring_organization_sample(): void
    {
        $src = self::read('app/Console/Commands/A11yRosterIdsCommand.php');

        self::assertMatchesRegularExpression("/'organization_cgc'\s*=>\s*\['cgc'\]/", $src,
            'a resolver keyed organization_cgc exists');
        self::assertMatchesRegularExpression("/->where\(\s*'is_cgc'\s*,\s*true\s*\)/", $src,
            'the cgc resolver prefers is_cgc = true');
        self::assertMatchesRegularExpression("/'param'\s*=>\s*'organization_cgc'/", $src,
            'the command emits an organization_cgc row');
        self::assertStringContainsString("return \$this->sampleFor(['db', 'organizations', 'id']);", $src,
            'the cgc resolver falls back to the oldest organization');
    }

    public function test_resolve_ids_substitutes_the_cgc_sample_for_the_cgc_route_only(): void
    {
        $src = self::read('tests/browser/roster/resolve-ids.mjs');

        self::assertStringContainsString("'/organizations/{organization}/cgc': { organization: 'organization_cgc' }", $src,
            'the /cgc route overrides {organization} with the CGC sample');
        self::assertMatchesRegularExpression('/buildUrl\(\s*uri\s*,\s*map\s*,\s*overrides/', $src,
            'buildUrl accepts a per-route override map');
        self::assertStringContainsString('URL_OVERRIDES[p.uri]', $src,
            'the route loop passes the override for the current uri');
    }

    // ── Finding 1: delegations index no longer aborts ────────────────────────

    public function test_delegations_index_no_longer_aborts_and_delegates_to_show(): void
    {
        $src = self::read('app/Http/Controllers/Organizations/OrgDelegationController.php');

        // The index method body must not carry an abort_unless.
        self::assertMatchesRegularExpression(
            '/function index\([^)]*\)\s*:\s*mixed\s*\{(?:(?!\bfunction\b).)*?return app\(OrganizationController::class\)->show/s',
            $src,
            'index delegates to OrganizationController@show'
        );
        self::assertDoesNotMatchRegularExpression(
            '/function index\([^)]*\)\s*:\s*mixed\s*\{(?:(?!\bfunction\b).)*?abort_unless/s',
            $src,
            'index no longer aborts for a non-agent'
        );
        // The write path stays agent-only (unchanged).
        self::assertStringContainsString("\$this->engine->file('F-ORG-011'", $src,
            'grant/revoke still route through the F-ORG-011 handler');
    }

    // ── Finding 2: the no-live-call state ─────────────────────────────────────

    public function test_institution_room_page_carries_call_available(): void
    {
        $controller = self::read('app/Http/Controllers/Rooms/InstitutionRoomController.php');
        self::assertMatchesRegularExpression("/'callAvailable'\s*=>\s*\\\$room\s*!==\s*null/", $controller,
            'the page prop reports whether a live room exists');

        $vue = self::read('resources/js/Pages/Rooms/Institution.vue');
        self::assertStringContainsString('callAvailable: { type: Boolean, default: true }', $vue,
            'the page declares the callAvailable prop');
        self::assertStringContainsString(
            "text('institution.no_live_call', 'No call is live in this room right now.')",
            $vue,
            'the private variant shows the no-live-call line'
        );
        self::assertMatchesRegularExpression('/v-if="private && !callAvailable"/', $vue,
            'the no-live-call line is gated to the private variant');
    }

    public function test_catalog_carries_the_no_live_call_key_verbatim(): void
    {
        $json = self::read('resources/js/i18n/locales/en/c_rooms.json');
        $catalog = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('institution.no_live_call', $catalog);
        self::assertSame('No call is live in this room right now.', $catalog['institution.no_live_call']);
    }
}
