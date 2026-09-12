<?php

namespace Tests\Unit;

use App\Http\Controllers\Civic\JourneysController;
use App\Http\Controllers\Education\LearnController;
use App\Services\JourneyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Reading public lessons must never query anyone's private progress. */
final class JourneyReadingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.journey_reading_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('journey_reading_fixture');
        DB::enableQueryLog();
    }

    public function test_guide_bookmarks_lead_to_the_canonical_public_directory(): void
    {
        self::assertSame(url('/journeys'), (new LearnController)->guides()->getTargetUrl());
        foreach (['journeys.index', 'journeys.show'] as $name) {
            self::assertNotContains('auth', Route::getRoutes()->getByName($name)->gatherMiddleware());
        }
        foreach (['journeys.step', 'journeys.unstep'] as $name) {
            self::assertContains('auth', Route::getRoutes()->getByName($name)->gatherMiddleware());
        }
    }

    public function test_guest_can_read_directory_and_every_authored_step_without_private_queries(): void
    {
        $service = $this->createMock(JourneyService::class);
        $service->expects(self::never())->method('progress');
        $controller = new JourneysController($service);
        $request = Request::create('/journeys');
        $request->setUserResolver(fn () => null);
        $response = $controller->index($request);
        $props = (new \ReflectionProperty($response, 'props'))->getValue($response);
        self::assertNotEmpty($props['journeys']);
        foreach ($props['journeys'] as $journey) {
            self::assertSame(0, $journey['steps_done']);
            self::assertFalse($journey['completed']);
            $response = $controller->show($request, $journey['id']);
            $detail = (new \ReflectionProperty($response, 'props'))->getValue($response);
            self::assertCount($journey['steps_total'], $detail['journey']['steps']);
            self::assertSame([], $detail['progress']['stepsDone']);
            self::assertNull($detail['achievement']);
        }
        self::assertSame([], DB::getQueryLog());
    }
}
