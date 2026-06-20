<?php

namespace Tests\Constitutional;

use App\Services\Matrix\MatrixClientService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-3 (K3-L prerequisite), the MatrixClientService READ seam. getMessages is
 * the single read method the embedded client renders from; it hits the /messages endpoint with the
 * appservice token, clamps the page size, and returns the {chunk,start,end} page. Read-only — it never
 * mutates. (Hermetic: the homeserver HTTP is faked.)
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class MatrixClientReadSeamTest extends TestCase
{
    public function test_get_messages_reads_the_timeline_page_with_the_appservice_token(): void
    {
        Http::fake([
            '*/_matrix/client/v3/rooms/*/messages*' => Http::response([
                'chunk' => [['type' => 'm.room.message', 'content' => ['body' => 'hello square']]],
                'start' => 't1', 'end' => 't2',
            ], 200),
        ]);

        $page = app(MatrixClientService::class)->getMessages('!square:localhost', 'b', null, 25);

        $this->assertSame('t2', $page['end']);
        $this->assertSame('hello square', $page['chunk'][0]['content']['body']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/rooms/')
                && str_contains($request->url(), '/messages')
                && str_contains($request->url(), 'dir=b')
                && str_contains($request->url(), 'limit=25')
                && $request->hasHeader('Authorization', 'Bearer '.config('matrix.appservice.as_token'));
        });
    }

    public function test_get_messages_clamps_the_page_size(): void
    {
        Http::fake(['*/messages*' => Http::response(['chunk' => [], 'start' => 'a', 'end' => 'b'], 200)]);

        app(MatrixClientService::class)->getMessages('!halls:localhost', 'b', null, 9999);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'limit=100')); // clamped to 100
    }
}
