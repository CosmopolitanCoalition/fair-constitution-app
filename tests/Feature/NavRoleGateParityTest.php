<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * PIN — the client menu must never HIDE a door the server opens.
 *
 * Two role lists describe the same surface. `config/cga/surfaces.php` `roles` is
 * the SERVER's answer to "who is this surface for". The client mirrors it twice —
 * `resources/js/registry/surfaces.js` `roles` and `resources/js/Navigation/nav.js`
 * `enabledRoles` — and MenuNav uses them to decide whether the link is live or
 * rendered dead with a "Requires R-nn" hint.
 *
 * The asymmetry matters, so this pin is one-directional:
 *
 *   · client ⊋ server (client lists MORE) is TOLERATED. The link is live, the
 *     viewer clicks, and the engine refuses with a citation. A refusal is an
 *     answer; the constitution is still enforced where it is enforced.
 *   · client ⊊ server (client lists FEWER) is a DEFECT and is what this fails on.
 *     The menu becomes the gate, and it gates tighter than the constitution does.
 *     Nothing downstream can correct it because the player never gets to click.
 *
 * Three real instances motivated it, all found in one pass:
 *   · judiciary/advocate-console — server ['R-21','R-03'], client ['R-21']. The
 *     surface's own F-IND-015 is availableTo R-03 and CONFERS R-21, so the door
 *     that makes an advocate was disabled for everyone who was not already one.
 *   · judiciary/constitutional-challenge — server ['R-03','R-09','R-19','R-20'],
 *     client ['R-19','R-20','R-21']. F-IND-016's citation is "Art. IV §5 — any
 *     inhabitant, NO STANDING GATEKEEPER", and an ordinary resident was shown
 *     "Requires R-21".
 *   · executive/departments — server included R-18, client did not, so a
 *     department reporter got a prereq hint on the surface they report to.
 *
 * If this fails, widen the client list to match the server — never narrow the
 * server to match the client.
 */
class NavRoleGateParityTest extends TestCase
{
    /** Server surfaces that declare both a nav id and a role list. */
    private function serverNavRoles(): array
    {
        $out = [];
        foreach ((array) config('cga.surfaces') as $spec) {
            if (! is_array($spec) || ! isset($spec['nav'], $spec['roles'])) {
                continue;
            }
            if (! is_array($spec['roles']) || $spec['roles'] === []) {
                continue;
            }
            // A nav id can be shared by sibling surfaces (e.g. a list + its
            // detail). The door must admit the union of what they allow.
            $out[$spec['nav']] = array_values(array_unique(
                array_merge($out[$spec['nav']] ?? [], $spec['roles'])
            ));
        }

        return $out;
    }

    /**
     * The role codes a client file grants to one nav id, or null when the row
     * declares no role list at all (which means "always enabled" — never a defect).
     */
    private function clientRoles(string $source, string $navId, string $key): ?array
    {
        foreach (["id: '{$navId}'", "id: \"{$navId}\""] as $needle) {
            $at = strpos($source, $needle);
            if ($at === false) {
                continue;
            }

            // Rows are one object per line; the row's own line is the whole scope.
            $eol = strpos($source, "\n", $at);
            $line = substr($source, $at, ($eol === false ? strlen($source) : $eol) - $at);

            if (! preg_match('/'.preg_quote($key, '/').': *\[([^\]]*)\]/', $line, $m)) {
                return null;
            }

            preg_match_all('/R-\d{2}/', $m[1], $codes);

            return $codes[0];
        }

        return null; // not present in this client file at all
    }

    public function test_the_client_menu_never_gates_tighter_than_the_server(): void
    {
        $registry = (string) file_get_contents(base_path('resources/js/registry/surfaces.js'));
        $nav = (string) file_get_contents(base_path('resources/js/Navigation/nav.js'));

        $violations = [];
        $checked = 0;

        foreach ($this->serverNavRoles() as $navId => $serverRoles) {
            foreach ([['registry/surfaces.js', $registry, 'roles'], ['Navigation/nav.js', $nav, 'enabledRoles']] as [$label, $src, $key]) {
                $client = $this->clientRoles($src, $navId, $key);
                if ($client === null) {
                    continue; // no row, or no role list = always enabled
                }

                $checked++;
                $hidden = array_values(array_diff($serverRoles, $client));
                if ($hidden !== []) {
                    sort($serverRoles);
                    sort($client);
                    $violations[] = sprintf(
                        '%s [%s]: hides %s (server [%s] vs client [%s])',
                        $label, $navId, implode(',', $hidden),
                        implode(',', $serverRoles), implode(',', $client)
                    );
                }
            }
        }

        $this->assertGreaterThan(20, $checked, 'the parity sweep found almost no role-bearing rows — the parser has drifted from the file format, so this pin is not actually checking anything');

        $this->assertSame([], $violations, "the client menu gates TIGHTER than the constitution:\n  ".implode("\n  ", $violations));
    }

    /* ====================================================================
       S1 · consistency — the route/surface inventory (register: "actual
       route/surface inventory" + "check navigation context").

       A surface with a `nav` id is a navigable surface. The player reaches it
       through the registry menu (resources/js/registry/surfaces.js), whose row
       carries the surface's `href`. Navigation context is REAL only when that
       href is a route the app actually serves. This sweep proves, on the live
       route table, that every navigable surface resolves to a served GET route.

       A registry row with `href: null` is a Planned surface — deliberately not
       wired yet, rendered "Planned · Phase N". Those are recorded, never failed.
       A surface whose `nav` id no registry row answers is an unresolved nav
       (computeCoverageDrift's navUnresolved) and IS a defect.
       ==================================================================== */

    /** Every registry-menu id → its href (null when the surface is Planned). */
    private function registryHrefs(): array
    {
        $source = (string) file_get_contents(base_path('resources/js/registry/surfaces.js'));
        $out = [];
        // Rows are one object per line: { id: 'x', … href: '/path' | null, … }.
        if (preg_match_all("/id:\\s*'([^']+)'[^\\n]*?href:\\s*(null|'([^']*)')/", $source, $rows, PREG_SET_ORDER)) {
            foreach ($rows as $r) {
                $id = $r[1];
                $href = $r[2] === 'null' ? null : $r[3];
                // First non-null wins; a later Planned duplicate never hides a wired one.
                if (! array_key_exists($id, $out) || ($out[$id] === null && $href !== null)) {
                    $out[$id] = $href;
                }
            }
        }

        return $out;
    }

    /**
     * The app's own deferred-nav allowlist (resources/js/registry/coverage.js
     * NAV_ALLOWLIST). Its coverage instrument records these navs green-with-
     * record rather than failing; this test honours the same list so it does
     * not re-flag what the operator's own tooling has already deferred.
     *
     * @return list<string>
     */
    private function navAllowlist(): array
    {
        $source = (string) file_get_contents(base_path('resources/js/registry/coverage.js'));
        if (! preg_match('/export const NAV_ALLOWLIST = \{(.*?)\};/s', $source, $block)) {
            return [];
        }
        preg_match_all('/(\w+)\s*:/', $block[1], $keys);

        return $keys[1] ?? [];
    }

    /** Every GET route the app serves, as a leading-slash URI pattern. */
    private function servedGetPatterns(): array
    {
        $patterns = [];
        foreach (\Illuminate\Support\Facades\Route::getRoutes()->getRoutesByMethod()['GET'] ?? [] as $route) {
            if ($route->isFallback) {
                continue;
            }
            $patterns[] = '/'.ltrim($route->uri(), '/');
        }

        return array_values(array_unique($patterns));
    }

    /**
     * A registry href (a concrete path) served by a GET route pattern?
     * Params match one path segment: {seg} required, {seg?} optional-trailing —
     * so /legislature/{sub?} serves both /legislature and /legislature/bills.
     */
    private function hrefIsServed(string $href, array $patterns): bool
    {
        $path = '/'.ltrim(parse_url($href, PHP_URL_PATH) ?: $href, '/');
        foreach ($patterns as $pattern) {
            // Tokenise the braces out of harm's way, quote, then re-expand.
            $tok = strtr($pattern, ['{' => "\x01", '}' => "\x02", '?' => "\x03"]);
            $quoted = preg_quote($tok, '#');
            $quoted = preg_replace('#/\x01[^\x02]+\x03\x02#', '(?:/[^/]+)?', $quoted); // /{seg?}
            $quoted = preg_replace('#\x01[^\x02]+\x02#', '[^/]+', $quoted);             // {seg}
            if (preg_match('#^'.$quoted.'$#', $path)) {
                return true;
            }
        }

        return false;
    }

    public function test_every_navigable_surface_resolves_to_a_served_route(): void
    {
        $registry = $this->registryHrefs();
        $patterns = $this->servedGetPatterns();

        $navIds = [];
        foreach ((array) config('cga.surfaces') as $id => $spec) {
            if (is_array($spec) && ! empty($spec['nav']) && is_string($spec['nav'])) {
                $navIds[$spec['nav']][] = $id;
            }
        }

        $this->assertGreaterThan(20, count($navIds), 'almost no navigable surfaces were found — the surface parser has drifted');

        $allowlist = $this->navAllowlist();

        $unresolved = []; // nav id no registry row answers
        $dead = [];       // registry href is a real path but no route serves it
        $planned = [];    // registry href is null (Planned) — recorded, not failed
        $deferred = [];   // nav on the app's NAV_ALLOWLIST — recorded, not failed
        $served = 0;

        foreach ($navIds as $nav => $surfaceIds) {
            if (in_array($nav, $allowlist, true)) {
                $deferred[] = $nav;

                continue;
            }
            if (! array_key_exists($nav, $registry)) {
                $unresolved[] = sprintf('%s (surfaces: %s)', $nav, implode(', ', $surfaceIds));

                continue;
            }
            $href = $registry[$nav];
            if ($href === null) {
                $planned[] = $nav;

                continue;
            }
            if ($this->hrefIsServed($href, $patterns)) {
                $served++;

                continue;
            }
            $dead[] = sprintf('%s → %s (surfaces: %s)', $nav, $href, implode(', ', $surfaceIds));
        }

        $this->assertGreaterThan(15, $served, 'the route inventory served almost nothing — the pattern matcher has drifted');

        $this->assertSame([], $unresolved, "navigable surfaces name a nav id no registry menu answers:\n  ".implode("\n  ", $unresolved));
        $this->assertSame([], $dead, "navigable surfaces point at a route the app does not serve (dead navigation context):\n  ".implode("\n  ", $dead));
    }

    /* ====================================================================
       S1 · consistency — UI acceptance is EARNED, not assumed (register:
       "route/source presence alone does not establish UI acceptance").

       The four surfaces this consistency row sweeps for the shared usability
       conventions (loading / error / disabled / return) each must (a) be a
       registered surface, (b) resolve to a served route, and (c) carry a
       compiled prop-driven companion under tests/js that mounts the real page.
       tests/js/consistencySweep.test.mjs is that companion for all four.
       ==================================================================== */
    public function test_the_swept_surfaces_carry_a_compiled_ui_companion(): void
    {
        // page component → (surface id it renders, registry nav id it lives under)
        $swept = [
            'Pages/Judiciary/Home.vue'                 => ['judiciary/judiciary-home', 'judiciary-home'],
            'Pages/Judiciary/CaseDetail.vue'           => ['judiciary/case-detail', 'public-docket'],
            'Pages/Organizations/OrgDetail.vue'        => ['organizations/org-detail', 'org-registry'],
            'Pages/Jurisdictions/UnionFormation.vue'   => ['jurisdictions/union-formation', 'union-formation'],
        ];

        // Which page .vue files any tests/js companion mounts.
        $referenced = '';
        foreach (glob(base_path('tests/js/*.test.mjs')) ?: [] as $file) {
            $referenced .= file_get_contents($file);
        }

        $surfaces = (array) config('cga.surfaces');
        $registry = $this->registryHrefs();
        $patterns = $this->servedGetPatterns();

        $gaps = [];
        foreach ($swept as $page => [$surfaceId, $navId]) {
            if (! isset($surfaces[$surfaceId])) {
                $gaps[] = "surface not registered: {$surfaceId}";
            }
            if (! array_key_exists($navId, $registry) || $registry[$navId] === null || ! $this->hrefIsServed($registry[$navId], $patterns)) {
                $gaps[] = "nav id has no served route: {$navId} (for {$page})";
            }
            if (strpos($referenced, $page) === false) {
                $gaps[] = "no compiled tests/js companion mounts {$page}";
            }
        }

        $this->assertSame([], $gaps, "a swept surface is missing its route or its compiled UI companion:\n  ".implode("\n  ", $gaps));
    }

    /* ====================================================================
       S1 · consistency — the DOM-level UI-acceptance boundary is DECLARED
       and ENFORCED (register: "route/source presence alone does not
       establish UI acceptance").

       The consistency sweep (tests/js/consistencySweep.test.mjs) DOM-drives
       the shared loading / error / disabled / return conventions on four
       representative surfaces. Sibling S1 review rows carry their own
       compiled prop-driven companions for further action doors. But NOT
       every action door has one, and the register forbids reading a route
       that merely resolves as a surface whose UI is accepted.

       So this test partitions EVERY S1-module action-door page into two sets
       and pins the partition:

         · COVERED  — some tests/js companion mounts the real page, so its
                      DOM-level conventions are exercised.
         · RECORDED — no companion mounts it. Route inventory only; DOM-level
                      UI acceptance is NOT established. It is recorded here for
                      the punch list, never counted as accepted.

       The two sets must together be the WHOLE action-door population and must
       not overlap. A new or renamed action door therefore FAILS this test
       until it is triaged into one set, so the uncovered boundary can never
       grow silently and a PASS on this row can never be misread as
       full-surface UI acceptance.

       Census (this run): 41 action doors, 14 COVERED, 27 RECORDED. Only the
       Executive module has zero covered action doors; Elections has one
       (OpenBallot), Legislature four. Recorded for the punch list.
       ==================================================================== */

    /** Every S1-module page that carries an action door (a form/router write). */
    private function s1ActionDoorPages(): array
    {
        $doors = [];
        foreach (['Elections', 'Executive', 'Judiciary', 'Legislature', 'Organizations', 'Jurisdictions'] as $module) {
            foreach (glob(base_path("resources/js/Pages/{$module}/*.vue")) ?: [] as $file) {
                $src = (string) file_get_contents($file);
                if (preg_match('/\.(post|patch|delete|put)\(|useForm|onSubmit/', $src)) {
                    $doors[] = 'Pages/'.$module.'/'.basename($file);
                }
            }
        }
        sort($doors);

        return $doors;
    }

    public function test_the_S1_action_door_ui_acceptance_boundary_is_declared_and_enforced(): void
    {
        $doorPages = $this->s1ActionDoorPages();
        $this->assertGreaterThan(20, count($doorPages), 'the action-door census found almost nothing — the page parser has drifted');

        // Pages some tests/js companion mounts (DOM-level conventions exercised).
        $referenced = '';
        foreach (glob(base_path('tests/js/*.test.mjs')) ?: [] as $file) {
            $referenced .= file_get_contents($file);
        }
        $covered = array_values(array_filter($doorPages, fn ($p) => strpos($referenced, $p) !== false));

        // Action doors with NO companion: route inventory only. DOM-level UI
        // acceptance is NOT established for these; they are RECORDED here for
        // the punch list, never counted as accepted (S1 · consistency scope).
        $recordedGap = [
            'Pages/Elections/BoardConsole.vue',
            'Pages/Elections/CandidacyRegistration.vue',
            'Pages/Elections/ElectionDetail.vue',
            'Pages/Elections/RankedBallot.vue',
            'Pages/Elections/Results.vue',
            'Pages/Elections/VacancyCountback.vue',
            'Pages/Executive/Actions.vue',
            'Pages/Executive/DepartmentDetail.vue',
            'Pages/Executive/DepartmentReporting.vue',
            'Pages/Judiciary/CaseDocket.vue',
            'Pages/Judiciary/ConstitutionalChallenge.vue',
            'Pages/Judiciary/JurorView.vue',
            'Pages/Jurisdictions/Federation.vue',
            'Pages/Legislature/BillConversation.vue',
            'Pages/Legislature/BillDetail.vue',
            'Pages/Legislature/Bills.vue',
            'Pages/Legislature/Chamber.vue',
            'Pages/Legislature/EmergencyPowers.vue',
            'Pages/Legislature/LiveCivicRoom.vue',
            'Pages/Legislature/Referendums.vue',
            'Pages/Legislature/SessionConsole.vue',
            'Pages/Legislature/Settings.vue',
            'Pages/Legislature/SpeakerTools.vue',
            'Pages/Organizations/Registry.vue',
            'Pages/Organizations/TransfersConversions.vue',
        ];
        sort($recordedGap);

        // No page may be both DOM-covered and recorded-as-uncovered.
        $overlap = array_values(array_intersect($covered, $recordedGap));
        $this->assertSame([], $overlap, "a recorded-gap page now has a companion — move it out of the recorded gap:\n  ".implode("\n  ", $overlap));

        // COVERED ∪ RECORDED must be the WHOLE action-door population. A new or
        // renamed door lands here until it is triaged into one set.
        $classified = array_values(array_unique(array_merge($covered, $recordedGap)));
        $untriaged = array_values(array_diff($doorPages, $classified));
        $this->assertSame([], $untriaged, "action doors are neither DOM-covered nor recorded as an uncovered gap (silent coverage hole):\n  ".implode("\n  ", $untriaged));

        // A recorded-gap entry that is no longer an action door is stale.
        $stale = array_values(array_diff($recordedGap, $doorPages));
        $this->assertSame([], $stale, "recorded-gap names a page that is no longer an S1 action door — prune it:\n  ".implode("\n  ", $stale));

        // The sample must be real: the consistency sweep's own action doors are
        // detected as covered, so the partition rests on live wiring.
        foreach (['Pages/Judiciary/CaseDetail.vue', 'Pages/Organizations/OrgDetail.vue', 'Pages/Jurisdictions/UnionFormation.vue'] as $mustCover) {
            $this->assertContains($mustCover, $covered, "the consistency sweep's own action door is not detected as covered: {$mustCover}");
        }
    }
}
