<?php

namespace Tests\Constitutional;

use App\Http\Middleware\DevToolsEnabled;
use App\Support\GameMode;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Parity pin for the jurisdiction:activate ↔ /dev/jurisdictions/{id}/activate
 * pair (ruling 10, UI<->CLI parity). The dev-bootstrap capability lives behind
 * DevToolsEnabled on BOTH doors: the web door must carry the SAME gate the
 * command's --force bootstrap posture lives behind (the DevBoardSeatParityTest
 * mold — guards travel with the pair, never the lenient door).
 */
class JurisdictionActivateParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The /dev gate is local + sandbox + toggle; phpunit boots APP_ENV=testing,
        // so force the local sandbox the tool legitimately lives in (the idiom
        // DevBoardSeatParityTest uses so the dev route group registers).
        $this->app['env'] = 'local';
        config(['cga.impersonation' => true]);
        GameMode::override(GameMode::SANDBOX);
    }

    protected function tearDown(): void
    {
        GameMode::override(null);
        GameMode::flush();
        parent::tearDown();
    }

    public function test_the_web_activate_door_is_dev_gated(): void
    {
        // W-0436: match the exact dev URI. A substring match on
        // 'jurisdictions/{jurisdiction}/activate' picked the first route in
        // table order, api/jurisdictions/{jurisdiction}/activate-legislature,
        // which is a different door with a different gate (below).
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'dev/jurisdictions/{jurisdiction}/activate');

        $this->assertNotNull($route, 'the /dev/jurisdictions/{jurisdiction}/activate route must exist');
        $this->assertContains(
            DevToolsEnabled::class,
            $route->gatherMiddleware(),
            'the web activate door must carry the DevToolsEnabled gate — the boundary jurisdiction:activate --force lives behind',
        );
    }

    public function test_the_api_activate_legislature_door_is_operator_gated(): void
    {
        // The recursive subtree boot (operator ruling 2026-08-08) is an
        // operator act. Its door carries no auth middleware; the controller
        // refuses anyone but the operator before any read (guest 403).
        $route = Route::getRoutes()->getByName('jurisdictions.activate-legislature');
        $this->assertNotNull($route, 'the activate-legislature door must exist');
        $this->assertSame(['POST'], $route->methods(), 'a write door');
        $this->assertSame('api/jurisdictions/{jurisdiction}/activate-legislature', $route->uri());

        $method = new \ReflectionMethod(\App\Http\Controllers\JurisdictionController::class, 'activateLegislature');
        $lines = file($method->getFileName());
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        $guard = "abort_unless((bool) \$request->user()?->is_operator, 403);";
        $this->assertStringContainsString($guard, $body, 'activateLegislature refuses anyone but the operator');
        $this->assertLessThan(
            strpos($body, 'ActivateSubtreeJob'),
            strpos($body, $guard),
            'the operator check comes before the subtree boot is dispatched',
        );
    }
}
