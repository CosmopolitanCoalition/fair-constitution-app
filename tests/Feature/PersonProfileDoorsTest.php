<?php

namespace Tests\Feature;

use App\Http\Controllers\Social\PersonProfileController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * W-0222: the per-user doors on the person profile: a direct-message route
 * and a self-edit write door for handle, bio and visibility. This pin reads
 * the route collection only, so it evidences the wiring without the live
 * world database (the behaviour pins live in PersonProfileTest, live-pg).
 */
class PersonProfileDoorsTest extends TestCase
{
    public function test_the_direct_message_route_maps_to_the_controller_behind_auth(): void
    {
        $route = Route::getRoutes()->getByName('people.message');

        $this->assertNotNull($route, 'the per-user DM route exists');
        $this->assertSame(['POST'], array_values(array_intersect($route->methods(), ['POST'])));
        $this->assertSame('people/{user}/message', $route->uri());
        $this->assertSame(PersonProfileController::class.'@message', $route->getActionName());
        $this->assertContains('auth', $route->gatherMiddleware(), 'DM is auth-gated');
    }

    public function test_the_self_edit_door_maps_to_updateProfile_behind_auth(): void
    {
        $route = Route::getRoutes()->getByName('people.profile.update');

        $this->assertNotNull($route, 'the self-edit write door exists');
        $this->assertContains('POST', $route->methods());
        $this->assertSame('people/profile', $route->uri());
        $this->assertSame(PersonProfileController::class.'@updateProfile', $route->getActionName());
        $this->assertContains('auth', $route->gatherMiddleware(), 'the self-edit door is auth-gated');
    }

    public function test_updateProfile_accepts_handle_bio_and_visibility(): void
    {
        // The write door validates exactly the three social fields (plus the
        // display name). Reading only the method body keeps this DB-free while
        // still proving handle, bio and visibility have a write path.
        $method = new \ReflectionMethod(PersonProfileController::class, 'updateProfile');
        $lines = file($method->getFileName());
        $source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        foreach (["'handle'", "'bio'", "'visibility'"] as $field) {
            $this->assertStringContainsString($field, $source, "updateProfile handles {$field}");
        }
        $this->assertStringContainsString("file('F-IND-002'", $source, 'the edit files through the engine (F-IND-002)');
    }
}
