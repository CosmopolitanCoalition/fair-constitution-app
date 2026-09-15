<?php

namespace Tests\Unit;

use App\Http\Controllers\LocaleController;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Gap lane fix-guest-locale — PHP-side wiring pins, DB-free.
 *
 * (1) GUEST LOCALE READ-BACK. The POST /locale endpoint (LocaleController) is
 *     registered without auth, so a guest can record a locale choice; it is
 *     validated against the ENABLED product locales SetLocale resolves into.
 */
class Wiring_fix_guest_localeTest extends TestCase
{
    public function test_post_locale_route_is_registered_without_auth(): void
    {
        $route = Route::getRoutes()->getByName('locale.store');

        $this->assertNotNull($route, 'the locale.store route is registered');
        $this->assertContains('POST', $route->methods(), 'it is a POST route');
        $this->assertSame('locale', ltrim($route->uri(), '/'), 'it is served at /locale');
        $this->assertSame(
            LocaleController::class,
            $route->getController()::class,
            'it points at LocaleController',
        );
        $this->assertSame('store', $route->getActionMethod(), 'it invokes the store action');
        $this->assertNotContains(
            'auth',
            $route->gatherMiddleware(),
            'a guest endpoint carries no auth middleware',
        );
    }

    public function test_supported_locales_are_the_enabled_product_set(): void
    {
        $supported = SetLocale::supported();

        // The validator behind POST /locale accepts exactly this set.
        $this->assertContains('en', $supported);
        $this->assertContains('es', $supported);
        // A registered-but-disabled locale (display-only, no catalog offered) is
        // not resolvable and must be rejected by the endpoint.
        $this->assertNotContains('de', $supported);
    }
}
