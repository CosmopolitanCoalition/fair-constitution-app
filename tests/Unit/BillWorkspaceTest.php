<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Legislature\BillConversationController;
use App\Models\Bill;
use App\Models\Jurisdiction;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\SocialSubforum;
use App\Models\User;
use App\Support\BillWorkspace;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\TestCase;

/** Isolated SQLite fixtures: never connects to the simulated world or files a form. */
final class BillWorkspaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.bill_workspace_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('bill_workspace_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('social_subforums', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('governing_object_type');
            $table->string('governing_object_id');
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('social_threads', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('subforum_id')->index();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('social_posts', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('thread_id')->index();
            $table->string('author_display');
            $table->text('body');
            $table->timestamp('created_at');
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function test_context_keeps_the_selected_bill_and_its_public_sponsor_name(): void
    {
        $bill = $this->bill();
        $workspace = BillWorkspace::for($bill);
        self::assertSame('Public sponsor', $workspace['sponsor']);
        self::assertSame('/bills/selected-bill', $workspace['recordHref']);
        self::assertSame('/bills/selected-bill/conversation', $workspace['discussionHref']);
        self::assertSame('/legislatures/selected-legislature/bills', $workspace['billsHref']);
        self::assertSame('/legislatures/selected-legislature/chamber', $workspace['chamberHref']);
        self::assertSame('/jurisdictions/selected-place', $workspace['place']['href']);
        $bill->sponsor->user->display_name = null;
        self::assertNull(BillWorkspace::for($bill)['sponsor'], 'Never fall back to the private account name.');
        $bill->setRelation('sponsor', null);
        self::assertNull(BillWorkspace::for($bill)['sponsor']);
    }

    public function test_discussion_pages_reach_all_comments_without_crossing_into_another_bill(): void
    {
        DB::table('social_subforums')->insert(['id' => 'our-hall', 'governing_object_type' => SocialSubforum::OBJECT_BILL, 'governing_object_id' => 'selected-bill']);
        DB::table('social_threads')->insert([
            ['id' => 'ours-a', 'subforum_id' => 'our-hall', 'deleted_at' => null],
            ['id' => 'ours-b', 'subforum_id' => 'our-hall', 'deleted_at' => null],
            ['id' => 'foreign', 'subforum_id' => 'another-hall', 'deleted_at' => null],
            ['id' => 'removed', 'subforum_id' => 'our-hall', 'deleted_at' => '2026-09-12'],
        ]);
        for ($i = 1; $i <= 120; $i++) {
            $this->fixturePost(sprintf('post-%03d', $i), $i % 2 ? 'ours-a' : 'ours-b');
        }
        $this->fixturePost('foreign-post', 'foreign');
        $this->fixturePost('removed-thread-post', 'removed');
        $this->fixturePost('removed-post', 'ours-a', '2026-09-12');

        $seen = [];
        foreach ([1 => 50, 2 => 50, 3 => 20] as $page => $count) {
            $props = $this->discussion($page);
            self::assertCount($count, $props['comments']);
            self::assertSame('needs_auth', $props['commentState']);
            self::assertSame('selected-place', $props['jurisdictionContext']['current']['id']);
            self::assertSame($page === 1, $props['commentPages']['newerHref'] === null);
            self::assertSame($page === 3, $props['commentPages']['olderHref'] === null);
            $seen = array_merge($seen, array_column($props['comments'], 'id'));
        }
        self::assertCount(120, array_unique($seen));
        self::assertNotContains('foreign-post', $seen);
        self::assertNotContains('removed-thread-post', $seen);
        self::assertNotContains('removed-post', $seen);
        self::assertSame('post-071', $seen[0]); // The newest page still reads in chronological order.
        self::assertSame('open', $this->discussion(1, true)['commentState']);
    }

    public function test_no_discussion_space_remains_an_honest_empty_read(): void
    {
        $props = $this->discussion(1, true);
        self::assertSame('no_space', $props['commentState']);
        self::assertSame([], $props['comments']);
        self::assertNull($props['commentPages']);
    }

    private function discussion(int $page, bool $signedIn = false): array
    {
        Paginator::currentPageResolver(fn () => $page);
        Paginator::currentPathResolver(fn () => '/bills/selected-bill/conversation');
        $request = Request::create('/bills/selected-bill/conversation', 'GET', ['page' => $page]);
        $request->setUserResolver(fn () => $signedIn ? new User : null);
        $engine = $this->createMock(ConstitutionalEngine::class);
        $engine->expects($this->never())->method('file');
        $response = (new BillConversationController($engine))->show($request, $this->bill());

        return (new ReflectionProperty($response, 'props'))->getValue($response);
    }

    private function bill(): Bill
    {
        $place = (new Jurisdiction)->forceFill(['id' => 'selected-place', 'name' => 'Selected place', 'slug' => 'selected-place', 'adm_level' => 1])->setRelation('parent', null);
        $legislature = (new Legislature)->forceFill(['id' => 'selected-legislature'])->setRelation('jurisdiction', $place);
        $sponsor = (new LegislatureMember)->setRelation('user', (new User)->forceFill(['name' => 'Account name', 'display_name' => 'Public sponsor']));

        return (new Bill)->forceFill(['id' => 'selected-bill', 'title' => 'Selected bill', 'status' => 'introduced', 'act_type' => 'ordinary'])
            ->setRelation('legislature', $legislature)->setRelation('sponsor', $sponsor);
    }

    private function fixturePost(string $id, string $thread, ?string $deleted = null): void
    {
        DB::table('social_posts')->insert([
            'id' => $id, 'thread_id' => $thread, 'body' => $id, 'author_display' => 'Public commenter',
            'created_at' => '2026-09-12 12:00:00', 'deleted_at' => $deleted,
        ]);
    }
}
