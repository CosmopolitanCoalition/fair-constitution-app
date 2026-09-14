<?php

namespace Tests\Unit;

use App\Http\Controllers\Media\VideoPrefsController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * W-0432 — the account-saved video player preferences endpoint. An explicit
 * SQLite memory fixture, no live world: a guest is refused, a signed-in viewer
 * round-trips a document, an unknown key is rejected, and there is only ever
 * one row per user.
 */
final class VideoPrefsControllerTest extends TestCase
{
    private string $original;

    private string $userId = '40000000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.video_prefs_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('video_prefs_fixture');
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        DB::connection()->getSchemaBuilder()->create('user_media_prefs', function (Blueprint $t) {
            $t->uuid('user_id')->primary();
            $t->text('prefs');
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::purge('video_prefs_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function request(string $method, array $body = [], bool $signedIn = true): Request
    {
        $request = Request::create('/api/me/video-prefs', $method, $body);
        $request->headers->set('Accept', 'application/json');
        if ($signedIn) {
            $request->setUserResolver(fn () => (object) ['id' => $this->userId]);
        }

        return $request;
    }

    public function test_a_guest_is_refused_with_401(): void
    {
        try {
            (new VideoPrefsController())->show($this->request('GET', [], signedIn: false));
            self::fail('a guest must be refused');
        } catch (HttpException $e) {
            self::assertSame(401, $e->getStatusCode());
        }
    }

    public function test_update_then_show_round_trips_the_document(): void
    {
        $controller = new VideoPrefsController();

        $put = $controller->update($this->request('PUT', [
            'audio' => 'pl', 'cap' => 'ar', 'linked' => false, 'captionsOn' => true, 'volume' => 0.5, 'muted' => true,
        ]));
        self::assertSame(200, $put->getStatusCode());
        $saved = $put->getData(true);
        self::assertSame('pl', $saved['audio']);
        self::assertSame(0.5, $saved['volume']);
        self::assertTrue($saved['muted']);

        $shown = $controller->show($this->request('GET'))->getData(true);
        self::assertSame($saved, $shown);
    }

    public function test_a_partial_update_merges_into_the_existing_document(): void
    {
        $controller = new VideoPrefsController();
        $controller->update($this->request('PUT', ['audio' => 'en', 'volume' => 0.2]));
        $merged = $controller->update($this->request('PUT', ['volume' => 0.8]))->getData(true);

        self::assertSame('en', $merged['audio'], 'the untouched key survives');
        self::assertSame(0.8, $merged['volume'], 'the sent key is updated');
    }

    public function test_an_unknown_key_is_rejected(): void
    {
        try {
            (new VideoPrefsController())->update($this->request('PUT', ['audio' => 'en', 'evil' => 'x']));
            self::fail('an unknown key must be rejected');
        } catch (HttpException $e) {
            self::assertSame(422, $e->getStatusCode());
        }

        self::assertSame(0, DB::table('user_media_prefs')->count(), 'nothing is written when a key is rejected');
    }

    public function test_there_is_only_ever_one_row_per_user(): void
    {
        $controller = new VideoPrefsController();
        $controller->update($this->request('PUT', ['audio' => 'en']));
        $controller->update($this->request('PUT', ['audio' => 'pl']));
        $controller->update($this->request('PUT', ['volume' => 0.3]));

        self::assertSame(1, DB::table('user_media_prefs')->count());
        $stored = json_decode((string) DB::table('user_media_prefs')->where('user_id', $this->userId)->value('prefs'), true);
        self::assertSame('pl', $stored['audio']);
    }
}
