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
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => str_contains($r->uri(), 'jurisdictions/{jurisdiction}/activate'));

        $this->assertNotNull($route, 'the /dev/jurisdictions/{jurisdiction}/activate route must exist');
        $this->assertContains(
            DevToolsEnabled::class,
            $route->gatherMiddleware(),
            'the web activate door must carry the DevToolsEnabled gate — the boundary jurisdiction:activate --force lives behind',
        );
    }
}
