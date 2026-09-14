<?php

namespace Tests\Feature;

use App\Http\Middleware\RedirectIfSetupIncomplete;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\TestCase;

/**
 * PIN (W-0249) — the open write doors are closed before the public walk.
 *
 * Three doors were reachable inside the setup window:
 *   · POST /api/import/jurisdictions — a pg_restore over an uploaded bundle,
 *     with no auth at all;
 *   · POST /api/setup/wizard/step2/pull-option and pull-control — auth but no
 *     operator check;
 *   · GET /api/setup/deploy-package — mints a fresh join key, auth but no
 *     operator check;
 *   · POST /api/setup/join — adopts the box as a mirror, with no user check.
 *
 * Each is now gated the way the rest of the wizard spine is (see
 * SetupWindowOperatorGateTest): a GUEST is refused at the 'auth' middleware
 * (401 on JSON), and a signed-in CITIZEN who is not the operator is refused by
 * the handler (403) before any read. 'register' also left the setup ALLOW
 * list, so no citizen account can be minted while setup is incomplete.
 *
 * The refusal pins run with NO database and NO world: refusal lands before any
 * read, or the gate is not a gate.
 */
class OpenWriteDoorGateTest extends TestCase
{
    /** @return list<string> */
    private static function gatedDoors(): array
    {
        return [
            '/api/import/jurisdictions',
            '/api/setup/join',
            '/api/setup/wizard/step2/pull-option',
            '/api/setup/wizard/step2/pull-control',
        ];
    }

    public function test_a_guest_is_refused_at_every_door(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        foreach (self::gatedDoors() as $path) {
            $this->postJson($path, [])->assertStatus(401);
        }
    }

    public function test_a_citizen_who_is_not_the_operator_is_refused_by_the_handler(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $citizen = new User(['name' => 'Resident', 'email' => 'resident@example.test']);
        $citizen->is_operator = false;
        $this->actingAs($citizen);

        foreach (self::gatedDoors() as $path) {
            $this->postJson($path, [])->assertStatus(403);
        }
    }

    public function test_the_deploy_package_door_is_operator_only(): void
    {
        // The deploy package is fetched with GET, so the POST helpers above do
        // not reach it. A guest bounces at 'auth' (401); a citizen is refused
        // by the handler (403) before the join key is minted.
        $this->getJson('/api/setup/deploy-package')->assertStatus(401);

        $citizen = new User(['name' => 'Resident', 'email' => 'resident@example.test']);
        $citizen->is_operator = false;
        $this->actingAs($citizen);
        $this->getJson('/api/setup/deploy-package')->assertStatus(403);
    }

    public function test_the_import_and_join_routes_carry_auth(): void
    {
        foreach (['jurisdictions.import', 'api.setup.join', 'api.setup.step2.pull-option', 'api.setup.step2.pull-control', 'api.setup.deploy-package'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "route not registered: {$name}");
            $this->assertTrue(
                in_array('auth', $route->gatherMiddleware(), true) && ! in_array('auth', $route->excludedMiddleware(), true),
                "write door is no longer auth-gated: {$name}"
            );
        }
    }

    public function test_register_is_not_reachable_while_setup_is_incomplete(): void
    {
        $middleware = new RedirectIfSetupIncomplete();
        $allowed = new ReflectionMethod($middleware, 'allowed');
        $allowed->setAccessible(true);

        $this->assertFalse(
            $allowed->invoke($middleware, Request::create('/register', 'GET')),
            'register is still on the setup ALLOW list'
        );
        // Control: an auth route stays allowed so the founder can still sign in.
        $this->assertTrue($allowed->invoke($middleware, Request::create('/login', 'GET')));
    }
}
