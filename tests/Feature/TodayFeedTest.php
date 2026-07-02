<?php

namespace Tests\Feature;

use App\Models\Jurisdiction;
use App\Models\Legislature;
use App\Models\LegislatureSession;
use App\Models\Petition;
use App\Models\ResidencyConfirmation;
use App\Models\User;
use App\Services\RoleService;
use App\Services\TodayFeedService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Home "today" feed (mockups-v3-wiring Phase 3b — the civic/today.html
 * contract): TodayFeedService rows/calendar/record + the /civic feed prop.
 *
 * The MyProfileTabsTest posture: DB-backed paths run on the guarded live-pg
 * connection (never RefreshDatabase — the live dev DB is not disposable),
 * everything inside a rolled-back transaction; SKIPS when pg is unreachable.
 */
class TodayFeedTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_today_feed';

    public function test_the_service_returns_the_contract_keys(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser('Empty Footprint');

            $feed = app(TodayFeedService::class)->forUser($user, []);

            $this->assertSame(['rows', 'total', 'calendar', 'record'], array_keys($feed));
            $this->assertSame([], $feed['rows']);
            $this->assertSame(0, $feed['total']);
            $this->assertSame([], $feed['calendar']);
            $this->assertSame([], $feed['record']);
        });
    }

    public function test_a_gathering_petition_and_a_scheduled_session_produce_feed_rows(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser('Feed Resident');
            $jurisdiction = $this->aFreshJurisdiction();
            $this->associate($user, $jurisdiction);

            $petition = Petition::create([
                'id'              => (string) Str::uuid(),
                'creator_user_id' => (string) $user->id,
                'jurisdiction_id' => (string) $jurisdiction->id,
                'title'           => 'Feed-test petition '.Str::random(6),
                'law_text'        => 'Proposed law text (throwaway).',
                'act_type'        => 'ordinary',
                'scale'           => [(string) $jurisdiction->id],
                'population_basis' => 100,
                'threshold_pct'   => 5.00,
                'threshold_count' => 5,
                'status'          => Petition::STATUS_GATHERING,
            ]);

            $legislature = Legislature::create([
                'jurisdiction_id' => (string) $jurisdiction->id,
                'status'          => Legislature::STATUS_ACTIVE,
            ]);
            $session = LegislatureSession::create([
                'id'             => (string) Str::uuid(),
                'legislature_id' => (string) $legislature->id,
                'session_no'     => 1,
                'status'         => LegislatureSession::STATUS_SCHEDULED,
                'scheduled_for'  => now()->addHours(30),
            ]);

            $feed = app(TodayFeedService::class)->forUser($user, [(string) $jurisdiction->id]);

            $this->assertSame(2, $feed['total']);
            $this->assertCount(2, $feed['rows']);

            $byKind = collect($feed['rows'])->keyBy('kind');

            $petitionRow = $byKind->get('petition');
            $this->assertNotNull($petitionRow, 'the gathering petition appears in the feed');
            $this->assertSame('open', $petitionRow['status']);
            $this->assertSame($petition->title, $petitionRow['title']);
            $this->assertSame("/civic/petitions/{$petition->id}", $petitionRow['href']);
            $this->assertSame('wait', $petitionRow['pill']['tone']);
            $this->assertSame('0 of 5 signatures', $petitionRow['pill']['label']);
            $this->assertSame($jurisdiction->name, $petitionRow['jurisdiction']);

            $sessionRow = $byKind->get('session');
            $this->assertNotNull($sessionRow, 'the scheduled session appears in the feed');
            $this->assertSame('soon', $sessionRow['status']);
            $this->assertSame("/legislatures/{$legislature->id}/chamber", $sessionRow['href']);
            $this->assertSame('opensAt', $sessionRow['target']['kind']);
            $this->assertSame(
                $session->scheduled_for->toIso8601String(),
                $sessionRow['target']['iso'],
            );

            // open (the petition) sorts ahead of soon (the session).
            $this->assertSame('petition', $feed['rows'][0]['kind']);
            $this->assertSame('session', $feed['rows'][1]['kind']);
        });
    }

    public function test_an_open_session_is_live_and_leads_the_feed(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser('Gallery Watcher');
            $jurisdiction = $this->aFreshJurisdiction();
            $this->associate($user, $jurisdiction);

            $legislature = Legislature::create([
                'jurisdiction_id' => (string) $jurisdiction->id,
                'status'          => Legislature::STATUS_ACTIVE,
            ]);
            LegislatureSession::create([
                'id'             => (string) Str::uuid(),
                'legislature_id' => (string) $legislature->id,
                'session_no'     => 1,
                'status'         => LegislatureSession::STATUS_OPEN,
                'opened_at'      => now()->subHour(),
            ]);
            Petition::create([
                'id'              => (string) Str::uuid(),
                'creator_user_id' => (string) $user->id,
                'jurisdiction_id' => (string) $jurisdiction->id,
                'title'           => 'Second fiddle petition '.Str::random(6),
                'law_text'        => 'Proposed law text (throwaway).',
                'act_type'        => 'ordinary',
                'scale'           => [(string) $jurisdiction->id],
                'population_basis' => 100,
                'threshold_pct'   => 5.00,
                'threshold_count' => 5,
                'status'          => Petition::STATUS_GATHERING,
            ]);

            $feed = app(TodayFeedService::class)->forUser($user, [(string) $jurisdiction->id]);

            $this->assertSame('session', $feed['rows'][0]['kind'], 'live sorts before open');
            $this->assertSame('live', $feed['rows'][0]['status']);
            $this->assertSame('live', $feed['rows'][0]['pill']['tone']);
            $this->assertNull($feed['rows'][0]['target']);
        });
    }

    public function test_the_calendar_buckets_a_future_session(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser('Calendar Reader');
            $jurisdiction = $this->aFreshJurisdiction();
            $this->associate($user, $jurisdiction);

            $legislature = Legislature::create([
                'jurisdiction_id' => (string) $jurisdiction->id,
                'status'          => Legislature::STATUS_ACTIVE,
            ]);
            LegislatureSession::create([
                'id'             => (string) Str::uuid(),
                'legislature_id' => (string) $legislature->id,
                'session_no'     => 1,
                'status'         => LegislatureSession::STATUS_SCHEDULED,
                'scheduled_for'  => now()->addDay(),
            ]);

            $feed = app(TodayFeedService::class)->forUser($user, [(string) $jurisdiction->id]);

            $event = collect($feed['calendar'])
                ->first(fn (array $e) => str_contains($e['title'], $jurisdiction->name));

            $this->assertNotNull($event, 'the future session lands on the calendar');
            $this->assertSame('Tomorrow', $event['day']);
            $this->assertSame("Chamber session — {$jurisdiction->name}", $event['title']);
            $this->assertSame($jurisdiction->name, $event['where']);
            $this->assertSame('jurisdiction', $event['kind']);
            $this->assertSame("/legislatures/{$legislature->id}/chamber", $event['href']);
        });
    }

    public function test_the_civic_endpoint_carries_the_feed_prop(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser('Home Visitor');

            $this->actingAs($user)
                ->get('/civic')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Civic/Home')
                    ->has('feed.rows')
                    ->has('feed.total')
                    ->has('feed.calendar')
                    ->has('feed.record')
                    // The pre-3b props stay wired (other consumers + tests).
                    ->has('claim')
                    ->has('machine')
                    ->has('associations')
                    ->has('stats')
                    ->has('elections')
                    ->has('petitions'));
        });
    }

    // ── helpers (the MyProfileTabsTest live-pg posture) ──────────────────────

    private function aUser(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => 'today-feed-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
    }

    /** A jurisdiction with NO legislature (the live DB carries demo chambers). */
    private function aFreshJurisdiction(): Jurisdiction
    {
        $used = Legislature::query()->pluck('jurisdiction_id')->all();
        $jurisdiction = Jurisdiction::query()
            ->whereNotIn('id', $used)
            ->where('adm_level', 2)
            ->orderBy('id')
            ->first();

        $this->assertNotNull($jurisdiction, 'live DB has an adm2 jurisdiction without a legislature');

        return $jurisdiction;
    }

    private function associate(User $user, Jurisdiction $jurisdiction): void
    {
        ResidencyConfirmation::create([
            'id'              => (string) Str::uuid(),
            'user_id'         => (string) $user->id,
            'jurisdiction_id' => (string) $jurisdiction->id,
            'depth'           => 0,
            'days_confirmed'  => 30,
            'confirmed_at'    => now(),
            'is_active'       => true,
        ]);
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        app(RoleService::class)->flush();
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
            app(RoleService::class)->flush();
        }
    }
}
