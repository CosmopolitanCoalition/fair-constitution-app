<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\AttendanceRegistration;
use App\Models\User;
use App\Services\Demo\DemoSessionService;
use App\Support\DemoMode;
use App\Support\InstanceClass;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * DEMO MODE — the compensating purge (operator rulings 2026-09-10, DemoMode
 * C + A). Pins, on the live box inside a rolled-back transaction:
 *
 *  1. CAPTURE + VOID MECHANICS: with `cga.demo_session` set, an insert, an
 *     update and a delete on a captured table are recorded; void() reverses
 *     them newest-first (re-insert, restore the before image, soft-delete)
 *     and appends ONE demo.session.voided entry. The purge is not captured.
 *  2. THE ENGINE ON A DEMO BOX: a signed-in user without the role files a
 *     role-gated form anyway; the waiver is in the act's audit payload; the
 *     handler's rows are captured against the session; logout voids them.
 *  3. OFF A DEMO BOX nothing changes: the role gate still refuses, no demo
 *     session opens, no capture row is written.
 *  4. EXPIRY: a demo session whose Laravel session is gone is voided by
 *     voidExpired(); a live one is left alone.
 */
class DemoSessionVoidTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_demo_void_pin';

    public function test_capture_and_void_reverse_insert_update_and_delete_newest_first(): void
    {
        $this->onLivePg(function (): void {
            $this->assertSame(220 <= $this->captureTableCount(), true, 'the capture trigger is installed');

            $demoId = $this->openDemoSession(null);
            $tag = 'demo-'.Str::lower(Str::random(6));

            // B exists BEFORE the demo session touches it.
            $bId = (string) Str::uuid();
            DB::table('jurisdictions')->insert(['id' => $bId, 'name' => "{$tag}-b", 'slug' => "{$tag}-b", 'adm_level' => 3, 'population' => 1, 'created_at' => now(), 'updated_at' => now()]);

            DB::statement('SELECT set_config(?, ?, true)', [DemoMode::GUC, $demoId]);
            $aId = (string) Str::uuid();
            DB::table('jurisdictions')->insert(['id' => $aId, 'name' => "{$tag}-a", 'slug' => "{$tag}-a", 'adm_level' => 3, 'population' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('jurisdictions')->where('id', $aId)->update(['name' => "{$tag}-a-renamed"]);
            DB::table('jurisdictions')->where('id', $bId)->delete();
            DB::statement("SELECT set_config(?, '', true)", [DemoMode::GUC]);

            $writes = DB::table('demo_session_writes')->where('demo_session_id', $demoId)->orderBy('seq')->get();
            $this->assertSame(['INSERT', 'UPDATE', 'DELETE'], $writes->pluck('op')->all(), 'three writes captured in order');
            $this->assertSame($aId, json_decode($writes[0]->pk, true)['id'], 'the primary key is captured');
            $this->assertSame("{$tag}-a", json_decode($writes[1]->before, true)['name'], 'an update carries its BEFORE image');
            $this->assertNull(DB::table('jurisdictions')->where('id', $bId)->first(), 'B is gone while the session lives');

            $auditBefore = (int) DB::table('audit_log')->where('event', 'demo.session.voided')->count();
            $r = app(DemoSessionService::class)->void($demoId, 'logout');
            $this->assertSame(3, $r['writes']);
            $this->assertSame(3, $r['reversed'], 'every write reversed: '.json_encode($r['skipped']));

            $a = DB::table('jurisdictions')->where('id', $aId)->first();
            $this->assertNotNull($a->deleted_at, 'the inserted row is soft-deleted (jurisdictions has deleted_at)');
            $this->assertSame("{$tag}-a", $a->name, 'the update is reversed before the insert (newest first)');
            $this->assertNotNull(DB::table('jurisdictions')->where('id', $bId)->first(), 'the deleted row is back');

            $this->assertSame($auditBefore + 1, (int) DB::table('audit_log')->where('event', 'demo.session.voided')->count(),
                'one void entry on the chain');
            $this->assertSame(3, (int) DB::table('demo_session_writes')->where('demo_session_id', $demoId)->count(),
                'the purge itself is not captured');
            $this->assertNotNull(DB::table('demo_sessions')->where('id', $demoId)->value('voided_at'));

            $again = app(DemoSessionService::class)->void($demoId, 'logout');
            $this->assertSame(0, $again['reversed'], 'a voided session is never purged twice');
        });
    }

    public function test_on_a_demo_box_the_engine_waives_the_role_gate_captures_the_act_and_logout_voids_it(): void
    {
        $this->onLivePg(function (): void {
            $this->becomeScaleDemo();
            $this->session([]);
            $user = User::factory()->create();
            $tag = 'demo-'.Str::lower(Str::random(6));
            $this->bindFakeAttendanceHandler($tag, ['R-09']);

            $result = app(ConstitutionalEngine::class)->file('F-LEG-002', $user, []);

            $demoId = app('session')->get(DemoMode::SESSION_KEY);
            $this->assertNotEmpty($demoId, 'the first filing opens the demo session');
            $this->assertSame($demoId, $result->recorded['_demo']['session'] ?? null);
            $this->assertContains('role', $result->recorded['_demo']['bypassed'] ?? [], 'the role gate is waived and the waiver is on the record');

            $jid = $result->recorded['jurisdiction_id'];
            $this->assertNotNull(DB::table('jurisdictions')->where('id', $jid)->whereNull('deleted_at')->first(), 'the act landed for real');
            $this->assertSame(1, (int) DB::table('demo_session_writes')
                ->where('demo_session_id', $demoId)->where('table_name', 'jurisdictions')->where('op', 'INSERT')->count(),
                "the handler's row is captured against the session");
            $this->assertSame($demoId, DB::table('audit_log')->where('id', $result->entry->id)->value(DB::raw("payload->'_demo'->>'session'")),
                'the chain entry names the demo session');

            $r = app(DemoSessionService::class)->endCurrent('logout');
            $this->assertSame(1, $r['reversed']);
            $this->assertNotNull(DB::table('jurisdictions')->where('id', $jid)->value('deleted_at'), 'logout voids the act');
            $this->assertNull(app('session')->get(DemoMode::SESSION_KEY), 'the session forgets its demo id');
        });
    }

    public function test_off_a_demo_box_the_role_gate_still_refuses_and_nothing_is_captured(): void
    {
        $this->onLivePg(function (): void {
            InstanceClass::flush();
            $this->assertFalse(DemoMode::active(), 'box E is a production-class instance');
            $this->session([]);
            $user = User::factory()->create();
            $this->bindFakeAttendanceHandler('demo-'.Str::lower(Str::random(6)), ['R-09']);

            $before = (int) DB::table('demo_session_writes')->count();
            $this->expectException(\App\Domain\Engine\ConstitutionalViolation::class);
            try {
                app(ConstitutionalEngine::class)->file('F-LEG-002', $user, []);
            } finally {
                $this->assertNull(app('session')->get(DemoMode::SESSION_KEY), 'no demo session opens off a demo box');
                $this->assertSame($before, (int) DB::table('demo_session_writes')->count(), 'nothing captured');
            }
        });
    }

    public function test_an_expired_laravel_session_is_voided_and_a_live_one_is_kept(): void
    {
        $this->onLivePg(function (): void {
            $this->becomeScaleDemo();
            $this->session(['k' => 'v']);
            $live = $this->openDemoSession(null);        // bound to the started session id
            app('session')->save();                    // the array handler now knows this id

            $gone = (string) Str::uuid();
            DB::table('demo_sessions')->insert([
                'id' => $gone, 'session_id' => 'no-such-session-'.Str::random(8), 'user_id' => null,
                'started_at' => now()->subHours(3), 'last_seen_at' => now()->subHours(3),
            ]);

            $n = app(DemoSessionService::class)->voidExpired();
            $this->assertSame(1, $n);
            $this->assertNotNull(DB::table('demo_sessions')->where('id', $gone)->value('voided_at'), 'the expired one is voided');
            $this->assertNull(DB::table('demo_sessions')->where('id', $live)->value('voided_at'), 'the live one is kept');
        });
    }

    // ------------------------------------------------------------ helpers

    private function captureTableCount(): int
    {
        return (int) DB::scalar("SELECT count(DISTINCT event_object_table) FROM information_schema.triggers WHERE trigger_name = 'cga_demo_capture'");
    }

    private function becomeScaleDemo(): void
    {
        DB::table('instance_settings')->whereNull('deleted_at')->update(['instance_class' => InstanceClass::SCALE_DEMO]);
        InstanceClass::flush();
        $this->assertTrue(DemoMode::active(), 'the box reads as scale_demo inside the transaction');
    }

    private function openDemoSession(?string $userId): string
    {
        $id = (string) Str::uuid();
        DB::table('demo_sessions')->insert([
            'id' => $id, 'session_id' => app()->bound('session') && app('session')->isStarted() ? app('session')->getId() : 'test-'.Str::random(8),
            'user_id' => $userId, 'started_at' => now(), 'last_seen_at' => now(),
        ]);

        return $id;
    }

    /** A stand-in for F-LEG-002: role-gated, and its mutation is one jurisdiction row. */
    private function bindFakeAttendanceHandler(string $tag, array $roles): void
    {
        app()->bind(AttendanceRegistration::class, fn () => new class($tag, $roles) implements FormHandler {
            public function __construct(private string $tag, private array $roles) {}
            public function module(): string { return 'legislature'; }
            public function event(): string { return 'attendance.registered'; }
            public function requiredRoles(): array { return $this->roles; }
            public function systemOnly(): bool { return false; }
            public function handle(?User $actor, array $payload): array
            {
                $id = (string) Str::uuid();
                DB::table('jurisdictions')->insert([
                    'id' => $id, 'name' => $this->tag, 'slug' => $this->tag, 'adm_level' => 3, 'population' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return ['jurisdiction_id' => $id];
            }
        });
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            $conn->rollBack();
            DB::setDefaultConnection($original);
            InstanceClass::flush();
        }
    }
}
