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
}
