<?php

namespace Tests\Feature;

use App\Models\SupportReport;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\DisposableRepairWorld;
use Tests\TestCase;

class SupportReportIntakeTest extends TestCase
{
    use DisposableRepairWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openRepairWorld();
        $this->withoutVite();
        config(['cache.default' => 'array', 'queue.default' => 'sync', 'session.driver' => 'array']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->closeRepairWorld();
        parent::tearDown();
    }

    public function test_guest_form_exposes_only_valid_repository_and_keeps_local_filing_authenticated(): void
    {
        $this->get('/support/report?ref=legislature/districts')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Support/Report')
                ->where('ref', 'legislature/districts')->where('githubRepository', 'CosmopolitanCoalition/fair-constitution-app'));
        $this->post('/support/report', ['category' => 'bug', 'body' => 'Draft'])->assertRedirect('/login');
        self::assertSame(0, SupportReport::count());
        config(['services.github.issue_repository' => 'https://unexpected.example/repository']);
        $this->get('/support/report')->assertInertia(fn (Assert $page) => $page->where('githubRepository', null));
    }

    public function test_local_reports_remain_private_attributed_and_correctly_routed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        foreach (['bug' => 'operators', 'abuse' => 'moderation', 'idea' => 'backlog'] as $category => $target) {
            $this->post('/support/report', ['category' => $category, 'subject' => 'A report', 'body' => 'Private written details', 'ref' => 'legislature/districts'])
                ->assertRedirect('/support/report')->assertSessionHasNoErrors();
            $report = SupportReport::where('category', $category)->firstOrFail();
            self::assertSame($user->id, $report->reporter_id);
            self::assertSame($target, $report->route_target);
            self::assertSame('Private written details', $report->body);
            self::assertNotEmpty($report->public_id);
        }
        $this->actingAs(User::factory()->create())->get('/support/ticket/'.$report->public_id)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_sign_in_continuation_returns_to_report_without_sending_draft_in_the_url(): void
    {
        $path = '/support/report?ref=legislature%2Fdistricts';
        $this->get('/continue?mode=login&to='.urlencode($path))->assertRedirect('/login')->assertSessionHas('url.intended', $path);
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('intendedUrl', $path));
    }
}
