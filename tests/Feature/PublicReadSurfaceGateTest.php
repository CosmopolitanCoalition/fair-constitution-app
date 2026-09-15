<?php

namespace Tests\Feature;

use App\Http\Middleware\RedirectIfSetupIncomplete;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\TestCase;

/**
 * PIN (W-0283) — every read surface is open to a guest; every drive endpoint
 * stays gated.
 *
 * Operator ruling 2026-09-10: every page is readable by anyone regardless of
 * residency or role. A role gates ACTIONS, never the page. Two gates decide
 * whether a guest reaches a read page:
 *
 *   1. the route's own 'auth' middleware (a guest bounces to /login when set);
 *   2. the setup lock (RedirectIfSetupIncomplete), which pins a GET page load
 *      to the wizard while setup is incomplete unless the path is on its ALLOW
 *      list.
 *
 * A read route must clear BOTH: no effective 'auth', and its top-level path on
 * the setup ALLOW list. A drive route must keep 'auth'. The checks read the
 * live route table and the middleware itself, so no database is touched (the
 * setup lock bypasses the console anyway, so a live request cannot exercise it).
 */
class PublicReadSurfaceGateTest extends TestCase
{
    /** Read route names that must be reachable by a guest (no effective 'auth'). */
    private static function readRouteNames(): array
    {
        return [
            // The sim console as a read-only view + its poll.
            'simworld.console', 'api.simworld.progress',
            // Federation + operator consoles (host block is operator-gated inside).
            'federation.show', 'operator.operations', 'federation.cluster.sync-progress',
            // The economy read pages.
            'economy.home', 'economy.wallet', 'economy.market', 'economy.work', 'economy.help',
            'economy.help.show', 'economy.listing', 'economy.treasury', 'economy.units', 'economy.stipend',
            'economy.request', 'economy.agreements', 'economy.agreement', 'economy.joint', 'economy.exchange',
            'economy.resident-agreements',
            // Chamber read registers.
            'committees.index', 'committees.show', 'referendums.index', 'emergency-powers.index',
            // Executive read surfaces.
            'executives.show', 'executive.departments', 'executive.department-detail', 'departments.reporting', 'executive.actions',
            // Standing public reads named by the item.
            'build.progress', 'learn.home', 'support.report',
            'videos', 'launchpad', 'tour', 'atlas.index', 'coverage', 'coverage-ops',
            // System read pages that are public record. Amendments, the audit
            // chain and translations keep their own auth decision (pinned by
            // SystemClocksAmendmentsTest); this item does not change those.
            'system.public-records', 'system.term-sync', 'system.clocks',
            'system.accessibility', 'system.constitutional-questions',
            // Organizations (W-0444): the registry, the profile and the CGC profile
            // read for a guest; every action stays behind its form and its role.
            'organizations.index', 'organizations.show', 'organizations.cgc.show',
        ];
    }

    /** Drive endpoints that must stay gated (effective 'auth' present). */
    private static function driveRouteNames(): array
    {
        return [
            // Sim + engine drive endpoints.
            'api.setup.step5.start', 'api.setup.step5.halt', 'api.setup.step5.resume',
            'api.setup.step4.start', 'api.setup.step3.autoscale-halt',
            // Economy write endpoints.
            'economy.transfer', 'economy.market.order', 'economy.shares.offer',
            // Chamber write endpoints.
            'committees.store', 'referendums.store', 'emergency-powers.store',
            // Executive write endpoints.
            'executive.orders.store', 'executive.departments.nominate',
        ];
    }

    /**
     * Representative read PAGE paths (a GET the setup lock would gate). Each
     * must pass the ALLOW list while setup is incomplete.
     */
    private static function readPagePaths(): array
    {
        $u = '00000000-0000-0000-0000-000000000000';

        return [
            // Root is the guest cover (Home). It must load during setup too.
            '/',
            '/simworld', '/building',
            '/economy', '/economy/wallet', '/economy/work', '/economy/help', '/economy/exchange',
            "/executives/{$u}", "/executives/{$u}/departments", "/departments/{$u}",
            "/legislatures/{$u}/committees", "/committees/{$u}",
            "/legislatures/{$u}/referendums", "/legislatures/{$u}/emergency-powers",
            '/learn', '/support/report',
            '/videos', '/launchpad', '/tour', '/atlas', '/coverage', '/coverage-ops',
            '/system/public-records', '/system/clocks',
            '/operator/federation', '/operator/operations',
            '/organizations', "/organizations/{$u}",
        ];
    }

    private function effectiveAuth(RoutingRoute $route): bool
    {
        return in_array('auth', $route->gatherMiddleware(), true)
            && ! in_array('auth', $route->excludedMiddleware(), true);
    }

    public function test_every_read_route_is_open_to_a_guest(): void
    {
        $blocked = [];
        foreach (self::readRouteNames() as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "read route not registered: {$name}");
            if ($this->effectiveAuth($route)) {
                $blocked[] = $name;
            }
        }

        $this->assertSame([], $blocked, 'these read routes still require sign-in: '.implode(', ', $blocked));
    }

    public function test_every_drive_route_stays_gated(): void
    {
        $open = [];
        foreach (self::driveRouteNames() as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "drive route not registered: {$name}");
            if (! $this->effectiveAuth($route)) {
                $open[] = $name;
            }
        }

        $this->assertSame([], $open, 'these drive routes are no longer gated: '.implode(', ', $open));
    }

    public function test_setup_lock_allows_every_public_read_page(): void
    {
        $middleware = new RedirectIfSetupIncomplete();
        $allowed = new ReflectionMethod($middleware, 'allowed');
        $allowed->setAccessible(true);

        $blocked = [];
        foreach (self::readPagePaths() as $path) {
            $request = Request::create($path, 'GET');
            if (! $allowed->invoke($middleware, $request)) {
                $blocked[] = $path;
            }
        }

        $this->assertSame([], $blocked, 'the setup lock still pins these read pages to the wizard: '.implode(', ', $blocked));
    }
}
